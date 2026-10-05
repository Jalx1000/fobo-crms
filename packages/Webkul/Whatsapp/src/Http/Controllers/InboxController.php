<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Exceptions\HandoffConflictException;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\TemplateHeader;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\Handoff as HandoffService;
use Webkul\Whatsapp\Services\PhoneNumber;
use Webkul\Whatsapp\Services\TemplateCatalog;

class InboxController extends Controller
{
    public function __construct(protected GatewayManager $gateways) {}

    /**
     * Return the conversation, its messages (incremental via the `since` cursor)
     * and what the active provider can actually do.
     */
    public function thread(Request $request): JsonResponse
    {
        $conversation = $this->resolveConversation($request);

        // Cursor captured before the query so status changes during the request
        // are picked up on the next poll (merge on the client is idempotent).
        $serverTime = now();
        $since = $request->input('since');

        $messages = collect();

        if ($conversation) {
            $query = $conversation->messages()->with('replyTo');

            if ($since) {
                // Incremental: new messages AND status changes (updated_at bumps).
                $messages = $query->where('updated_at', '>=', $since)->orderBy('id')->limit(200)->get();
            } else {
                // Initial load: latest 200 in chronological order.
                $messages = $query->orderByDesc('id')->limit(200)->get()->sortBy('id')->values();

                if ($conversation->unread_count) {
                    $conversation->update(['unread_count' => 0]);
                }
            }

            $messages = $messages->map(fn ($message) => $this->transform($message));

            // Internal notes live as Krayin note-activities on the linked
            // person/lead — merge them into the stream chronologically.
            $messages = $messages
                ->concat($this->notesFor($conversation, $since))
                ->sortBy('timestamp')
                ->values();
        }

        // Resolve against the conversation's own gateway so history created
        // under a previous provider still reports the right abilities.
        $gateway = $conversation
            ? $this->gateways->for($conversation)
            : $this->gateways->active();

        return response()->json([
            'conversation' => $conversation,
            'messages'     => $messages->values(),
            'server_time'  => $serverTime->toDateTimeString(),
            'capabilities' => $gateway->capabilities()->toArray(),
            'locations'    => $this->savedLocations(),
            'handoff'      => app(HandoffService::class)->toArray($conversation, auth()->guard('user')->id()),
            'window'       => MessagingWindow::compute(
                $gateway->messagingWindowHours(),
                $conversation?->last_inbound_at,
            )->toArray(),
            'agent'        => [
                // raw = the per-conversation override (null = inherit global);
                // effective = what actually applies right now.
                'enabled'   => $conversation?->ai_enabled,
                'effective' => $conversation ? $conversation->aiEffective() : (bool) config('whatsapp.ai.enabled'),
            ],
        ]);
    }

    /**
     * Turn the AI agent on/off for a conversation.
     *
     * Off is what lets a human take over: the event sender only notifies the
     * agent while this is on, so turning it off stops AI replies and hands the
     * conversation to the advisor.
     */
    public function toggleAgent(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $conversation->update(['ai_enabled' => $data['enabled']]);

        return response()->json([
            'agent' => [
                'enabled'   => $conversation->ai_enabled,
                'effective' => $conversation->aiEffective(),
            ],
        ]);
    }

    /**
     * Queue an outbound text message.
     */
    public function send(Request $request, ConversationResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'phone'       => 'required|string',
            'name'        => 'nullable|string',
            'text'        => 'required|string',
            'reply_to_id' => 'nullable|integer',
        ]);

        // Defense in depth: the UI locks the composer while the agent is on, but
        // reject here too so a human can never talk over the agent.
        $existing = $this->existingConversation($request);

        if ($existing && $existing->aiEffective()) {
            return response()->json([
                'message' => 'El agente está activo en esta conversación. Desactivalo para escribir a mano.',
            ], 422);
        }

        $message = DB::transaction(function () use ($existing, $data, $resolver) {
            // Reuse an existing conversation already linked to this person/lead
            // (inbound links person_id reliably); otherwise resolve by phone.
            $conversation = $existing
                ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'text',
                'body'            => $data['text'],
                'reply_to_id'     => $data['reply_to_id'] ?? null,
                'status'          => 'queued',
                'sender'          => 'human',
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json(['message' => $this->transform($message->load('replyTo'))]);
    }

    /**
     * Send an attachment.
     *
     * The file is stored first and sent by URL, never streamed to Meta: the
     * provider fetches it itself, a retry needs it to still be there, and the
     * thread has to keep showing it long after the send.
     */
    public function sendMedia(Request $request, ConversationResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'phone'           => 'required_without:conversation_id|string',
            'name'            => 'nullable|string',
            'kind'            => 'required|in:image,document,audio,video',
            'caption'         => 'nullable|string|max:1024',
            'reply_to_id'     => 'nullable|integer',
            // 16 MB is WhatsApp's own ceiling for video and the lowest of the
            // four; rejecting here beats a failed send the advisor cannot explain.
            'file'            => 'required|file|max:16384',
        ]);

        if (! $this->gateways->active()->capabilities()->canSend($data['kind'])) {
            return response()->json([
                'message' => 'El canal actual no envía archivos de tipo '.$data['kind'].'.',
            ], 501);
        }

        $existing = $this->existingConversation($request);

        if ($existing && $existing->aiEffective()) {
            return response()->json([
                'message' => 'El agente está activo en esta conversación. Desactivalo para escribir a mano.',
            ], 422);
        }

        if (! $existing && empty($data['phone'])) {
            return response()->json(['message' => 'No se encontró la conversación.'], 422);
        }

        $file = $request->file('file');

        /*
         * A random name, not the original: the URL is public — it has to be,
         * Meta downloads from it — so the name is the only thing keeping one
         * customer's file from being guessable from another's.
         */
        try {
            $stored = $file->storeAs(
                'whatsapp/outbound',
                \Illuminate\Support\Str::random(40).'.'.$file->getClientOriginalExtension(),
                'public',
            );
        } catch (\Throwable $e) {
            report($e);

            $stored = false;
        }

        /*
         * Si el archivo no se guardó no hay nada que mandarle a Meta — el
         * proveedor lo descarga de nuestra URL — así que se corta acá con un
         * motivo accionable.
         *
         * El caso real fue de permisos: un `php artisan` corrido como root creó
         * storage/app/public/whatsapp/outbound con dueño root, y php-fpm
         * (www-data) no podía escribir ahí. La subida fallaba en silencio y el
         * asesor solo veía que "no se puede enviar nada".
         */
        if (! $stored) {
            return response()->json([
                'message' => 'No se pudo guardar el archivo en el servidor. '
                    .'Suele ser permisos de escritura en storage/app/public/whatsapp/outbound.',
            ], 500);
        }

        $mime = $file->getMimeType();
        $filename = $file->getClientOriginalName();

        $message = DB::transaction(function () use ($existing, $data, $resolver, $stored, $mime, $filename) {
            $conversation = $existing
                ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

            $media = new OutboundMedia(
                kind: $data['kind'],
                url: route('whatsapp.media', ['file' => basename($stored)]),
                filename: $filename,
                caption: $data['caption'] ?? null,
                mime: $mime,
            );

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => $data['kind'],
                'body'            => $data['caption'] ?? null,
                'media_path'      => $media->url,
                'media_mime'      => $mime,
                'media_name'      => $filename,
                'reply_to_id'     => $data['reply_to_id'] ?? null,
                'status'          => 'queued',
                'sender'          => 'human',
                'payload'         => ['media' => $media->toArray()],
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json(['message' => $this->transform($message->load('replyTo'))]);
    }

    /**
     * Send a map pin.
     */
    public function sendLocation(Request $request, ConversationResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'phone'           => 'required_without:conversation_id|string',
            'name'            => 'nullable|string',
            'latitude'        => 'required|numeric|between:-90,90',
            'longitude'       => 'required|numeric|between:-180,180',
            'label'           => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:255',
            'reply_to_id'     => 'nullable|integer',
        ]);

        if (! $this->gateways->active()->capabilities()->canSend('location')) {
            return response()->json(['message' => 'El canal actual no envía ubicaciones.'], 501);
        }

        $existing = $this->existingConversation($request);

        if ($existing && $existing->aiEffective()) {
            return response()->json([
                'message' => 'El agente está activo en esta conversación. Desactivalo para escribir a mano.',
            ], 422);
        }

        if (! $existing && empty($data['phone'])) {
            return response()->json(['message' => 'No se encontró la conversación.'], 422);
        }

        $location = new OutboundLocation(
            latitude: (float) $data['latitude'],
            longitude: (float) $data['longitude'],
            name: $data['label'] ?? null,
            address: $data['address'] ?? null,
        );

        $message = DB::transaction(function () use ($existing, $data, $resolver, $location) {
            $conversation = $existing
                ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'location',
                // Keeps the conversation-list preview from going blank.
                'body'            => $location->label(),
                'reply_to_id'     => $data['reply_to_id'] ?? null,
                'status'          => 'queued',
                'sender'          => 'human',
                'payload'         => ['location' => $location->toArray()],
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json(['message' => $this->transform($message->load('replyTo'))]);
    }

    /**
     * Addresses the team sends over and over: branch, warehouse, pickup point.
     *
     * Static config rather than a table: they change once a year, and a table
     * would need a CRUD nobody asked for.
     */
    protected function savedLocations(): array
    {
        return collect(config('whatsapp.locations', []))
            ->map(fn ($place) => [
                'name'      => $place['name'] ?? 'Ubicación',
                'address'   => $place['address'] ?? null,
                'latitude'  => (float) ($place['latitude'] ?? 0),
                'longitude' => (float) ($place['longitude'] ?? 0),
            ])
            ->filter(fn ($place) => $place['latitude'] || $place['longitude'])
            ->values()
            ->all();
    }

    /* ---------------------------------------------------------------------
     | Handoff — derivación a un asesor
     * ------------------------------------------------------------------- */

    /**
     * Un asesor deriva a mano (o se auto-asigna) una conversación.
     */
    public function requestHandoff(Request $request, string $id, HandoffService $handoff): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
            'ciudad' => 'nullable',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $resultado = $handoff->request(
            conversation: $conversation,
            reason: $data['reason'] ?? null,
            source: 'human',
            actorUserId: auth()->guard('user')->id(),
            ciudad: $data['ciudad'] ?? null,
        );

        return response()->json([
            'handoff' => $handoff->toArray(
                $resultado['conversation'],
                auth()->guard('user')->id(),
                $resultado['changed'],
            ),
        ]);
    }

    /**
     * Tomar una conversación que quedó en el pool.
     */
    public function claimHandoff(string $id, HandoffService $handoff): JsonResponse
    {
        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        try {
            $conversation = $handoff->claim($conversation, (int) auth()->guard('user')->id());
        } catch (HandoffConflictException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'handoff' => $handoff->toArray($conversation, auth()->guard('user')->id()),
        ]);
    }

    /**
     * Cerrar la derivación. Por defecto el agente vuelve a atender, para que la
     * conversación no quede sin dueño ni bot.
     */
    public function resolveHandoff(Request $request, string $id, HandoffService $handoff): JsonResponse
    {
        $data = $request->validate(['reactivate_ai' => 'nullable|boolean']);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        try {
            $conversation = $handoff->resolve(
                $conversation,
                auth()->guard('user')->id(),
                $data['reactivate_ai'] ?? true,
            );
        } catch (HandoffConflictException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'handoff' => $handoff->toArray($conversation, auth()->guard('user')->id()),
            'agent'   => [
                'enabled'   => $conversation->ai_enabled,
                'effective' => $conversation->aiEffective(),
            ],
        ]);
    }

    /**
     * The approved templates the composer can offer.
     *
     * Never an error response: an empty list with a reason is what the composer
     * can actually render. A 500 here would blank the whole conversation panel
     * over a feature that is secondary to it.
     */
    public function templates(TemplateCatalog $catalog): JsonResponse
    {
        if (! $this->gateways->active()->capabilities()->canSend('template')) {
            return response()->json([
                'templates' => [],
                'reason'    => 'El canal actual no envía plantillas.',
            ]);
        }

        try {
            return response()->json(['templates' => $catalog->all()]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['templates' => [], 'reason' => $e->getMessage()]);
        }
    }

    /**
     * Send an approved template — the only thing WhatsApp accepts once the 24h
     * window has closed.
     */
    public function sendTemplate(Request $request, ConversationResolver $resolver, TemplateCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            // The standalone chat page addresses a conversation directly and has
            // no phone at hand; the embedded inbox has the phone but no id yet
            // when the conversation does not exist. One of the two must arrive.
            'conversation_id' => 'nullable|integer',
            'phone'           => 'required_without:conversation_id|string',
            'name'            => 'nullable|string',
            'template'        => 'required|string',
            'language'        => 'required|string',
            'variables'       => 'array',
            'variables.*'     => 'string',
            // El archivo del encabezado, para las plantillas que declaran uno.
            'header_link'     => 'nullable|url',
            'header_filename' => 'nullable|string|max:255',
        ]);

        if (! $this->gateways->active()->capabilities()->canSend('template')) {
            return response()->json([
                'message' => 'El canal actual no envía plantillas.',
            ], 501);
        }

        $existing = $this->existingConversation($request);

        if ($existing && $existing->aiEffective()) {
            return response()->json([
                'message' => 'El agente está activo en esta conversación. Desactivalo para escribir a mano.',
            ], 422);
        }

        $template = $catalog->find($data['template'], $data['language']);

        if (! $template) {
            return response()->json(['message' => 'Esa plantilla ya no está disponible.'], 422);
        }

        $variables = array_values($data['variables'] ?? []);

        /*
         * Meta fills the placeholders by position and rejects the whole message
         * when the count differs. Catching it here turns a generic 400 from the
         * Graph API into a reason the advisor can act on.
         */
        if (count($variables) !== $template['variables']) {
            return response()->json([
                'message' => 'La plantilla necesita '.$template['variables'].' variable(s) y llegaron '.count($variables).'.',
            ], 422);
        }

        if (in_array('', array_map('trim', $variables), true)) {
            return response()->json(['message' => 'Completá todas las variables.'], 422);
        }

        /*
         * El encabezado se valida acá y no en Meta.
         *
         * Una plantilla con header de documento enviada sin el archivo vuelve
         * como `(#132012) Parameter format does not match format in the created
         * template`: un error que habla de formato de parámetros cuando lo que
         * falta es un componente entero, y que el asesor no puede interpretar.
         */
        $header = null;

        if ($definition = $template['header'] ?? null) {
            if ($definition['media'] ?? false) {
                if (empty($data['header_link'])) {
                    return response()->json([
                        'message' => 'Esta plantilla lleva un '.strtolower($definition['format'])
                            .' en el encabezado: adjuntá el archivo antes de enviarla.',
                    ], 422);
                }

                $header = new TemplateHeader(
                    format: $definition['format'],
                    link: $data['header_link'],
                    filename: $data['header_filename'] ?? null,
                );
            } else {
                $header = new TemplateHeader(
                    format: 'TEXT',
                    variables: array_slice($variables, 0, (int) $definition['variables']),
                );
            }
        }

        // A conversation_id that resolves to nothing leaves no phone to fall
        // back on, so it is rejected before opening the transaction.
        if (! $existing && empty($data['phone'])) {
            return response()->json(['message' => 'No se encontró la conversación.'], 422);
        }

        $message = DB::transaction(function () use ($existing, $data, $resolver, $template, $variables, $catalog, $header) {
            $conversation = $existing
                ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'template',
                // Readable preview for the thread; the real send uses the payload.
                'body'            => $catalog->render($template['body'], $variables),
                'status'          => 'queued',
                'sender'          => 'human',
                /*
                 * Written before the send, not after: this payload is what
                 * SendMessage reads to build the request, what a retry reads
                 * after a failure, and the only source the report has for which
                 * template went out.
                 */
                'payload'         => [
                    'template'  => $template['name'],
                    'language'  => $template['language'],
                    'variables' => $variables,
                    // Viaja en el payload como todo lo demás: es lo que lee
                    // SendMessage, y lo que lee un reintento después de fallar.
                    'header'    => $header?->toArray(),
                ],
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json(['message' => $this->transform($message)]);
    }

    /**
     * Store an internal team note on the conversation.
     *
     * The note is a native Krayin activity (type=note) attached to the
     * conversation's person/lead, so it also shows on their timeline. It is
     * never a whatsapp_message: it cannot reach the customer or the AI agent.
     */
    public function storeNote(Request $request, string $id, ActivityRepository $activities): JsonResponse
    {
        $data = $request->validate(['comment' => 'required|string']);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        if (! $conversation->person_id && ! $conversation->lead_id) {
            return response()->json([
                'message' => 'Asigná un contacto o lead a la conversación antes de dejar notas.',
            ], 422);
        }

        Event::dispatch('activity.create.before');

        $activity = $activities->create([
            'type'    => 'note',
            'comment' => $data['comment'],
            'is_done' => 1,
            'user_id' => auth()->guard('user')->id(),
        ]);

        // Attach to whatever the conversation is linked to (both when both):
        // the note must be visible from the person's and the lead's timeline.
        if ($conversation->person_id && ($person = PersonProxy::modelClass()::find($conversation->person_id))) {
            $person->activities()->attach($activity->id);
        }

        if ($conversation->lead_id && ($lead = LeadProxy::modelClass()::find($conversation->lead_id))) {
            $lead->activities()->attach($activity->id);
        }

        Event::dispatch('activity.create.after', $activity);

        return response()->json(['note' => $this->transformNote($activity->load('user'))]);
    }

    /**
     * Note-activities of the conversation's person/lead, shaped for the inbox.
     * Honors the same `since` cursor as messages.
     */
    protected function notesFor($conversation, ?string $since)
    {
        if (! $conversation->person_id && ! $conversation->lead_id) {
            return collect();
        }

        $query = ActivityProxy::modelClass()::query()
            ->with('user')
            ->where('type', 'note')
            ->where(function ($q) use ($conversation) {
                if ($conversation->person_id) {
                    $q->orWhereHas('persons', fn ($p) => $p->where('persons.id', $conversation->person_id));
                }

                if ($conversation->lead_id) {
                    $q->orWhereHas('leads', fn ($l) => $l->where('leads.id', $conversation->lead_id));
                }
            });

        $notes = $since
            ? $query->where('activities.updated_at', '>=', $since)->orderBy('activities.id')->limit(200)->get()
            : $query->orderByDesc('activities.id')->limit(200)->get()->sortBy('id')->values();

        return $notes->map(fn ($note) => $this->transformNote($note));
    }

    /**
     * Shape a note-activity like a thread item (kind=note tells the UI apart).
     */
    protected function transformNote($activity): array
    {
        return [
            'id'        => 'note-'.$activity->id,
            'kind'      => 'note',
            'text'      => $activity->comment,
            'author'    => $activity->user?->name,
            'time'      => optional($activity->created_at)->format('H:i'),
            'date'      => optional($activity->created_at)->toDateString(),
            'timestamp' => optional($activity->created_at)->toIso8601String(),
        ];
    }

    /**
     * Resolve the conversation for a thread request (read-only).
     */
    protected function resolveConversation(Request $request)
    {
        return $this->existingConversation($request);
    }

    /**
     * Find an existing conversation, preferring the reliable person_id/lead_id
     * link (set at inbound ingestion) over the phone which may be stored in a
     * different format than Meta's "from".
     */
    protected function existingConversation(Request $request)
    {
        $model = ConversationProxy::modelClass();

        // The standalone chat page addresses conversations directly.
        if ($conversationId = $request->input('conversation_id')) {
            return $model::find($conversationId);
        }

        if ($personId = $request->input('person_id')) {
            $conversation = $this->preferActiveGateway($model::where('person_id', $personId))->first();

            if ($conversation) {
                return $conversation;
            }
        }

        if ($leadId = $request->input('lead_id')) {
            $conversation = $this->preferActiveGateway($model::where('lead_id', $leadId))->first();

            if ($conversation) {
                return $conversation;
            }
        }

        if ($phone = $request->input('phone')) {
            return $model::where('wa_phone', PhoneNumber::normalize($phone))->first();
        }

        return null;
    }

    /**
     * A contact can end up with several conversations — one per provider after a
     * migration, or one per phone format. Show the one on the active gateway:
     * it is the only one the agent can actually reply through. Recency alone
     * would surface a dead conversation from a decommissioned provider.
     */
    protected function preferActiveGateway($query)
    {
        return $query
            ->orderByRaw('gateway = ? desc', [(string) config('whatsapp.gateway')])
            ->orderByDesc('last_message_at');
    }

    /**
     * Shape a message for the inbox front-end.
     */
    protected function transform($message): array
    {
        return [
            'id'        => $message->id,
            'direction' => $message->direction,
            'type'      => $message->type,
            'text'      => $message->body,
            'sender'    => $message->sender,
            'status'    => $message->status,
            'error'     => $message->error,
            'time'      => optional($message->created_at)->format('H:i'),
            'date'      => optional($message->created_at)->toDateString(), // Y-m-d, for day separators
            'timestamp' => optional($message->created_at)->toIso8601String(),
            'media'     => $message->media_path ? [
                'url'      => $message->media_path,
                'filename' => $message->media_name,
                'meta'     => $message->media_mime,
            ] : null,
            'location'    => $this->locationOf($message),
            'interactive' => $this->interactiveOf($message),
            'reaction'    => $this->reactionOf($message),
            'replyTo'     => $message->replyTo ? [
                'author'  => $message->replyTo->direction === 'inbound' ? 'Cliente' : 'Agente',
                'preview' => \Illuminate\Support\Str::limit((string) $message->replyTo->body, 60),
            ] : null,
        ];
    }

    /**
     * El emoji de una reacción, y si fue para QUITARLA.
     *
     * Se distinguen porque Meta usa el mismo tipo para las dos cosas: reaccionar
     * manda el emoji, quitar la reacción manda el campo vacío. Sin separarlos,
     * "quitó su reacción" y "reaccionó con algo que no pudimos leer" se ven
     * igual en el hilo.
     */
    protected function reactionOf($message): ?array
    {
        if ($message->type !== 'reaction') {
            return null;
        }

        $emoji = $message->payload['reaction']['emoji'] ?? null;

        return [
            'emoji'   => $emoji ?: null,
            'removed' => ! $emoji,
        ];
    }

    /**
     * Las opciones que se le ofrecieron al cliente en un menú tocable.
     *
     * Sin esto el asesor abre el chat y ve "Tipo no soportado" —el v-else de la
     * cadena de tipos del blade— justo donde el agente ofreció las siete
     * ciudades. Y al leer el historial de una conversación derivada, no puede
     * saber entre qué opciones estaba eligiendo el cliente.
     */
    protected function interactiveOf($message): ?array
    {
        if ($message->type !== 'interactive') {
            return null;
        }

        $node = $message->payload['interactive'] ?? null;

        if (! is_array($node)) {
            return null;
        }

        /*
         * Un interactivo tiene DOS formas según quién lo mandó, y antes sólo se
         * entendía una.
         *
         * Saliente (el menú que mandamos): nuestro DTO, con `options`.
         * Entrante (el toque del cliente): la forma cruda de Meta, anidada un
         * nivel bajo el tipo de respuesta —`button_reply` o `list_reply`—, SIN
         * `options`.
         *
         * El `empty($node['options'])` de antes descartaba la entrante, así que la
         * burbuja del cliente caía en el v-else del hilo y mostraba "Menú
         * enviado": el asesor leía que se había mandado un menú justo donde el
         * cliente había contestado.
         */
        if (empty($node['options'])) {
            $seleccion = $node['button_reply'] ?? $node['list_reply'] ?? null;

            if (! is_array($seleccion)) {
                return null;
            }

            return [
                'kind'      => 'seleccion',
                'seleccion' => [
                    'id'    => $seleccion['id'] ?? null,
                    'title' => (string) ($seleccion['title'] ?? ''),
                ],
            ];
        }

        return [
            'kind'    => $node['kind'] ?? 'list',
            'body'    => $node['body'] ?? null,
            'footer'  => $node['footer'] ?? null,
            'button'  => $node['list_button'] ?? null,
            'options' => collect($node['options'])
                ->map(fn ($o) => ['id' => $o['id'] ?? null, 'title' => $o['title'] ?? ''])
                ->values()
                ->all(),
        ];
    }

    /**
     * Coordinates of a location message.
     *
     * Reads BOTH shapes of `payload`: `['location' => ...]` for what we sent,
     * and the provider's own node for what arrived. Handling only ours would
     * leave every pin a customer shares rendering as an empty bubble — and
     * inbound locations already arrive today, the thread just cannot draw them.
     */
    protected function locationOf($message): ?array
    {
        if ($message->type !== 'location') {
            return null;
        }

        $node = $message->payload['location'] ?? null;

        if (! is_array($node) || ! isset($node['latitude'], $node['longitude'])) {
            return null;
        }

        return [
            'lat'     => (float) $node['latitude'],
            'lng'     => (float) $node['longitude'],
            'name'    => $node['name'] ?? null,
            'address' => $node['address'] ?? null,
            'url'     => sprintf(
                'https://www.google.com/maps?q=%s,%s',
                $node['latitude'],
                $node['longitude'],
            ),
        ];
    }
}
