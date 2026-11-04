<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Exceptions\AttachmentStorageException;
use Webkul\Whatsapp\Exceptions\HandoffConflictException;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversacionesVisibles;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\Handoff;
use Webkul\Whatsapp\Services\OutboundAttachment;
use Webkul\Whatsapp\Services\PhoneNumber;
use Webkul\Whatsapp\Services\TemplateCatalog;

class InboxController extends Controller
{
    public function __construct(
        protected GatewayManager $gateways,
        protected Handoff $handoff,
    ) {}

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
            // person/lead - merge them into the stream chronologically.
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
            // Which channel this thread lives on, already labelled. The header
            // and the profile drawer render it verbatim - no key ever reaches
            // the screen, and no channel is assumed to be WhatsApp.
            'channel'      => [
                'key'    => $gateway->key(),
                'label'  => $this->gateways->label($gateway->key()),
                // Phone-based or not. The composer asks this instead of testing
                // for a hardcoded channel name.
                'family' => $this->gateways->family($gateway->key()),
            ],
            // Meta's per-type size/format table and the team's saved pins. Both
            // are static config the composer needs to validate before uploading.
            'media'        => config('whatsapp.media'),
            'locations'    => $this->savedLocations(),
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
            // Sent on every poll, like `window` and `capabilities`, and
            // independent of the `since` cursor: a handoff produces no message,
            // so an empty incremental poll still has to refresh the banner.
            'handoff'      => $this->handoff->toArray($conversation, $this->userId()),
            // Which order this chat is about. Deliberately tiny (5 scalars): it
            // rides every 4s poll so the header stays right when somebody moves
            // the lead from the kanban. The full picker list is fetched on demand.
            'order'        => $this->orderBlock($conversation),
        ]);
    }

    /**
     * The order (lead) the conversation is bound to, for the header chip.
     *
     * `lead_id` may be null: the FK is ON DELETE SET NULL, and most conversations
     * start unbound. `count` tells the UI whether there is anything to pick from,
     * so it can offer "elegir pedido" instead of a dead chip.
     */
    protected function orderBlock($conversation): ?array
    {
        if (! $conversation || ! $conversation->person_id) {
            return null;
        }

        $lead = $conversation->lead_id
            ? LeadProxy::modelClass()::with('stage')->find($conversation->lead_id)
            : null;

        return [
            'lead_id'  => $lead?->id,
            'title'    => $lead?->title,
            'stage'    => $lead?->stage->name ?? null,
            'stage_id' => $lead?->lead_pipeline_stage_id,
            'count'    => LeadProxy::modelClass()::where('person_id', $conversation->person_id)->count(),
        ];
    }

    /**
     * Bind the conversation to one of the contact's orders.
     *
     * This is the fix for the drift: ConversationResolver picks a lead once, at
     * ingestion (`person->leads()->latest()`), and every order opened afterwards
     * is invisible to the chat. Here the advisor re-points it explicitly, and it
     * sticks - notes, the AI payload and the list badge all follow `lead_id`.
     */
    public function setLead(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lead_id' => 'nullable|integer']);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // 404 si no es suya (spec tablero-por-asesor, D10).
        app(ConversacionesVisibles::class)->exigir($conversation);

        if (! empty($data['lead_id'])) {
            $lead = LeadProxy::modelClass()::findOrFail($data['lead_id']);

            abort_unless(app(ConversacionesVisibles::class)->puedeVerPedido($lead->id), 404);

            // Never let a chat point at another contact's order.
            if ($conversation->person_id && (int) $lead->person_id !== (int) $conversation->person_id) {
                return response()->json([
                    'message' => 'Ese pedido pertenece a otro contacto.',
                ], 422);
            }

            $conversation->update(['lead_id' => $lead->id]);
        } else {
            $conversation->update(['lead_id' => null]);
        }

        return response()->json([
            'message' => 'Pedido de la conversación actualizado.',
            'order'   => $this->orderBlock($conversation->refresh()),
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

        // 404 si no es suya (spec tablero-por-asesor, D10).
        app(ConversacionesVisibles::class)->exigir($conversation);

        // El canal tiene la IA bloqueada por configuracion. Dejar guardar el
        // switch en true daria un inbox que miente: la fila diria "encendido" y
        // el agente no se ejecutaria nunca.
        if ($data['enabled'] && ! $conversation->channelAiAllowed()) {
            return response()->json([
                'message' => 'El agente IA está deshabilitado para este canal. Se habilita desde la configuración del servidor, no por conversación.',
                'agent'   => $this->agentBlock($conversation),
                'handoff' => $this->handoff->toArray($conversation, $this->userId()),
            ], 422);
        }

        // Switching the agent back on with a handoff open would let it talk over
        // the advisor who owns the conversation. Closing the handoff is the way
        // back. Turning it *off* is always allowed.
        if ($data['enabled'] && $conversation->handoffOpen()) {
            return response()->json([
                'message' => 'Hay una derivación abierta. Cerrala antes de reactivar el agente IA.',
                'agent'   => $this->agentBlock($conversation),
                'handoff' => $this->handoff->toArray($conversation, $this->userId()),
            ], 422);
        }

        $conversation->update(['ai_enabled' => $data['enabled']]);

        return response()->json([
            'agent'   => $this->agentBlock($conversation),
            'handoff' => $this->handoff->toArray($conversation, $this->userId()),
        ]);
    }

    /**
     * Derive the conversation to a human, from the inbox.
     *
     * `assign_to_me` covers the "I'll take it myself" case, skipping the owner
     * routing entirely.
     */
    public function requestHandoff(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason'       => 'nullable|string|max:500',
            'assign_to_me' => 'nullable|boolean',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // 404 si no es suya (spec tablero-por-asesor, D10).
        app(ConversacionesVisibles::class)->exigir($conversation);

        $result = $this->handoff->request(
            conversation: $conversation,
            reason: $data['reason'] ?? null,
            source: 'human',
            actorUserId: $this->userId(),
            assignTo: ($data['assign_to_me'] ?? false) ? $this->userId() : null,
        );

        return $this->handoffResponse($result['conversation'], $result['changed']);
    }

    /**
     * Take a pooled conversation. 409 when another advisor got there first.
     */
    public function claimHandoff(string $id): JsonResponse
    {
        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // 404 si no es suya (spec tablero-por-asesor, D10).
        app(ConversacionesVisibles::class)->exigir($conversation);

        // Tomar o reasignar una derivación es tarea de quien ve el total
        // (spec tablero-por-asesor, D12): las sin asignar se reparten por
        // turno y, si alguna queda, la asignan Administrador o Supervisores.
        abort_unless(app(\Webkul\Admin\Helpers\Alcance::class)->veTotal(), 403, 'Solo Administrador y Supervisores asignan derivaciones.');

        try {
            $conversation = $this->handoff->claim($conversation, $this->userId());
        } catch (HandoffConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'handoff' => $this->handoff->toArray($conversation->refresh(), $this->userId()),
            ], 409);
        }

        return $this->handoffResponse($conversation);
    }

    /**
     * Close the handoff. Hands the conversation back to the AI agent unless the
     * advisor unticks the box.
     */
    public function resolveHandoff(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reactivate_ai' => 'nullable|boolean']);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // 404 si no es suya (spec tablero-por-asesor, D10).
        app(ConversacionesVisibles::class)->exigir($conversation);

        try {
            $conversation = $this->handoff->resolve(
                $conversation,
                $this->userId(),
                $data['reactivate_ai'] ?? true,
            );
        } catch (HandoffConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->handoffResponse($conversation);
    }

    /**
     * Pass an open handoff to another advisor.
     */
    public function reassignHandoff(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // 404 si no es suya (spec tablero-por-asesor, D10).
        app(ConversacionesVisibles::class)->exigir($conversation);

        // Tomar o reasignar una derivación es tarea de quien ve el total
        // (spec tablero-por-asesor, D12): las sin asignar se reparten por
        // turno y, si alguna queda, la asignan Administrador o Supervisores.
        // El encargado nacional pasa las suyas, solo a otro Ejecutivo
        // Comercial (20/10/2026).
        abort_unless(app(\Webkul\Admin\Helpers\Alcance::class)->asesorPermitido($data['user_id']), 403, 'Solo Administrador y Supervisores asignan derivaciones; el encargado nacional, solo a un ejecutivo comercial.');

        try {
            $conversation = $this->handoff->reassign($conversation, (int) $data['user_id'], $this->userId());
        } catch (HandoffConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->handoffResponse($conversation);
    }

    /**
     * Every handoff endpoint answers with the same shape, so the front-end can
     * apply them all through one code path.
     */
    protected function handoffResponse($conversation, ?bool $changed = null): JsonResponse
    {
        return response()->json([
            'handoff' => $this->handoff->toArray($conversation, $this->userId(), $changed),
            'agent'   => $this->agentBlock($conversation),
        ]);
    }

    protected function agentBlock($conversation): array
    {
        return [
            'enabled'   => $conversation?->ai_enabled,
            'effective' => $conversation ? $conversation->aiEffective() : (bool) config('whatsapp.ai.enabled'),
            // Para que el inbox pueda mostrar el switch deshabilitado en vez de
            // dejar que el asesor lo toque y se coma un 422.
            'channel_allowed' => $conversation ? $conversation->channelAiAllowed() : true,
        ];
    }

    protected function userId(): ?int
    {
        return auth()->guard('user')->id();
    }

    /**
     * Queue an outbound text message.
     */
    public function send(Request $request, ConversationResolver $resolver): JsonResponse
    {
        // A channel without phone numbers (Messenger) addresses the thread
        // directly, so the phone is only required when there is nothing else to
        // resolve the conversation with.
        $data = $request->validate([
            'phone'           => 'required_without_all:conversation_id,person_id,lead_id|nullable|string',
            'conversation_id' => 'nullable|integer',
            'name'            => 'nullable|string',
            'text'            => 'required|string',
            'reply_to_id'     => 'nullable|integer',
        ]);

        $existing = $this->existingConversation($request);

        if ($blocked = $this->guardOutbound($existing) ?? $this->guardAddressable($existing, $data)) {
            return $blocked;
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
     * Queue an outbound attachment (image/audio/video/document/sticker).
     *
     * The file is stored before the provider ever sees it, so the thread shows
     * it right away and a failed delivery still leaves the advisor looking at
     * what they tried to send.
     */
    public function sendMedia(
        Request $request,
        ConversationResolver $resolver,
        OutboundAttachment $attachments,
    ): JsonResponse {
        $data = $request->validate([
            'phone'           => 'required_without_all:conversation_id,person_id,lead_id|nullable|string',
            'conversation_id' => 'nullable|integer',
            'name'            => 'nullable|string',
            'type'            => ['required', Rule::in(array_keys((array) config('whatsapp.media')))],
            'file'            => 'required|file',
            'caption'         => 'nullable|string',
            'reply_to_id'     => 'nullable|integer',
        ]);

        $existing = $this->existingConversation($request);

        // Capabilities is what the composer renders from, so a type that gets
        // here without being listed means the UI is stale - not a valid send.
        $gateway = $existing ? $this->gateways->for($existing) : $this->gateways->active();

        if (! $gateway->capabilities()->canSend($data['type'])) {
            return response()->json([
                'message' => 'Este canal no puede enviar archivos de tipo '.$data['type'].'.',
            ], 422);
        }

        // Size and format come from Meta's own table (config/whatsapp.media) and
        // are checked per type: a 20 MB image is fine as a document but not as
        // an image. Rejecting here turns an opaque Graph 400 into a clear error.
        $limits = (array) config('whatsapp.media.'.$data['type']);

        Validator::make($request->all(), [
            'file' => [
                'file',
                'max:'.$limits['max_kb'],
                ...(($limits['mimes'] ?? []) ? ['mimetypes:'.implode(',', $limits['mimes'])] : []),
            ],
        ], [
            'file.max'       => 'El archivo supera el límite de WhatsApp para '.$data['type'].' ('.round($limits['max_kb'] / 1024, 1).' MB).',
            'file.mimetypes' => 'WhatsApp no acepta este formato como '.$data['type'].'.',
        ])->validate();

        if ($blocked = $this->guardOutbound($existing) ?? $this->guardAddressable($existing, $data)) {
            return $blocked;
        }

        // Audio and sticker take no caption; the service decides where the
        // advisor's text goes instead.
        $plan = $attachments->planCaption($data['type'], $data['caption'] ?? null);

        try {
            [$message, $followUp] = DB::transaction(function () use ($existing, $data, $plan, $resolver, $attachments, $request) {
                $conversation = $existing
                    ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

                $file = $attachments->stage($request->file('file'), $conversation->id);

                $message = MessageProxy::modelClass()::create([
                    'conversation_id' => $conversation->id,
                    'direction'       => 'outbound',
                    'type'            => $data['type'],
                    'body'            => $plan['caption'],
                    'media_path'      => $file['url'],
                    'media_mime'      => $file['mime'],
                    'media_name'      => $file['name'],
                    'reply_to_id'     => $data['reply_to_id'] ?? null,
                    'status'          => 'queued',
                    'sender'          => 'human',
                    // Disk-relative path for the gateway: media_path is a public URL
                    // and the driver needs to read the bytes off the disk.
                    'payload'         => ['media_disk_path' => $file['path']],
                ]);

                $followUp = $plan['follow_up']
                    ? MessageProxy::modelClass()::create([
                        'conversation_id' => $conversation->id,
                        'direction'       => 'outbound',
                        'type'            => 'text',
                        'body'            => $plan['follow_up'],
                        'status'          => 'queued',
                        'sender'          => 'human',
                    ])
                    : null;

                $conversation->forceFill(['last_message_at' => now()])->save();

                return [$message, $followUp];
            });
        } catch (AttachmentStorageException $e) {
            // The transaction rolled back, so no orphan message row survives.
            // Logged as the infrastructure problem it is, but answered as a 422
            // so the advisor sees something actionable in the composer instead
            // of a bare "Server Error".
            Log::error('WhatsApp: no se pudo guardar un adjunto saliente.', [
                'conversation' => $existing?->id,
                'type'         => $data['type'],
                'error'        => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo guardar el archivo en el servidor. Avisá al equipo técnico (permisos de storage).',
            ], 422);
        }

        // Chained, not two independent dispatches: the caption has to land after
        // the file, and parallel workers give no ordering guarantee.
        $jobs = array_filter([
            new SendMessage($message->id),
            $followUp ? new SendMessage($followUp->id) : null,
        ]);

        Bus::chain($jobs)->onQueue(config('whatsapp.queue'))->dispatch();

        return response()->json([
            'message'   => $this->transform($message->load('replyTo')),
            'follow_up' => $followUp ? $this->transform($followUp) : null,
        ]);
    }

    /**
     * Queue an outbound location pin.
     */
    public function sendLocation(Request $request, ConversationResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'phone'       => 'required|string',
            'name'        => 'nullable|string',
            'latitude'    => 'required|numeric|between:-90,90',
            'longitude'   => 'required|numeric|between:-180,180',
            // The pin's own label/address - distinct from `name`, which is the
            // contact name used to resolve the conversation.
            'label'       => 'nullable|string|max:1000',
            'address'     => 'nullable|string|max:1000',
            'reply_to_id' => 'nullable|integer',
        ]);

        $existing = $this->existingConversation($request);

        $gateway = $existing ? $this->gateways->for($existing) : $this->gateways->active();

        if (! $gateway->capabilities()->canSend('location')) {
            return response()->json(['message' => 'Este canal no puede enviar ubicaciones.'], 422);
        }

        if ($blocked = $this->guardOutbound($existing)) {
            return $blocked;
        }

        $location = new OutboundLocation(
            latitude: (float) $data['latitude'],
            longitude: (float) $data['longitude'],
            name: $data['label'] ?? null,
            address: $data['address'] ?? null,
        );

        $message = DB::transaction(function () use ($existing, $data, $location, $resolver) {
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
     * Saved locations for the composer's location panel. Static config, so it
     * rides along with the thread payload rather than costing its own endpoint.
     */
    /**
     * Las plantillas aprobadas entre las que puede elegir el asesor.
     *
     * Nunca rompe el inbox: si falta la cuenta o Meta no responde, devuelve
     * lista vacía y el motivo, y el composer degrada a "no hay plantillas" en
     * lugar de dejar la conversación inutilizable.
     */
    public function templates(Request $request, TemplateCatalog $catalog): JsonResponse
    {
        // La capacidad se evalúa contra el canal de ESTA conversación, no contra
        // el activo de la instalación: un hilo de Messenger no tiene plantillas
        // por más que Cloud API sea el canal por defecto.
        if (! $this->gatewayFor($request)->capabilities()->canSend('template')) {
            return response()->json(['templates' => [], 'reason' => 'Este canal no envía plantillas.']);
        }

        try {
            return response()->json(['templates' => $catalog->all()]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['templates' => [], 'reason' => $e->getMessage()]);
        }
    }

    /**
     * Enviar una plantilla aprobada - lo único que WhatsApp acepta una vez
     * cerrada la ventana de 24h.
     */
    public function sendTemplate(
        Request $request,
        ConversationResolver $resolver,
        TemplateCatalog $catalog,
        OutboundAttachment $attachments,
    ): JsonResponse {
        $data = $request->validate([
            'phone'           => 'required_without_all:conversation_id,person_id,lead_id|nullable|string',
            'conversation_id' => 'nullable|integer',
            'name'            => 'nullable|string',
            'template'        => 'required|string',
            'language'        => 'required|string',
            'variables'       => 'array',
            'variables.*'     => 'string',
            // Solo para plantillas cuyo encabezado es un archivo. Se valida
            // contra los límites del tipo más abajo, cuando ya sabemos cuál es.
            'file'            => 'nullable|file',
        ]);

        $existing = $this->existingConversation($request);

        if ($blocked = $this->guardOutbound($existing) ?? $this->guardAddressable($existing, $data)) {
            return $blocked;
        }

        $template = $catalog->find($data['template'], $data['language']);

        if (! $template) {
            return response()->json(['message' => 'Esa plantilla ya no está disponible.'], 422);
        }

        // El encabezado multimedia es un parámetro obligatorio: sin el archivo,
        // Meta responde 132012 ("parameter format does not match"), que no dice
        // en ninguna parte que falta un adjunto.
        $headerType = $this->headerMediaType($template);

        if ($headerType && ! $request->hasFile('file')) {
            return response()->json([
                'message' => 'Esta plantilla lleva un archivo en el encabezado. Adjuntalo para poder enviarla.',
            ], 422);
        }

        if ($headerType && $blocked = $this->guardMediaLimits($request, $headerType)) {
            return $blocked;
        }

        if (($template['header_variables'] ?? 0) > 0) {
            return response()->json([
                'message' => 'Esta plantilla usa variables en el encabezado, que todavía no se pueden completar desde el chat.',
            ], 422);
        }

        $variables = array_values($data['variables'] ?? []);

        // Meta completa los placeholders por posición y rechaza el mensaje
        // entero cuando la cantidad no coincide, así que se corta acá con un
        // motivo que se puede leer.
        if (count($variables) !== $template['variables']) {
            return response()->json([
                'message' => 'La plantilla necesita '.$template['variables'].' variable(s) y llegaron '.count($variables).'.',
            ], 422);
        }

        if (in_array('', array_map('trim', $variables), true)) {
            return response()->json(['message' => 'Completá todas las variables.'], 422);
        }

        try {
            $message = DB::transaction(function () use ($existing, $data, $request, $resolver, $attachments, $template, $variables, $headerType) {
                $conversation = $existing
                    ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

                $file = $headerType
                    ? $attachments->stage($request->file('file'), $conversation->id)
                    : null;

                $message = MessageProxy::modelClass()::create([
                    'conversation_id' => $conversation->id,
                    'direction'       => 'outbound',
                    // Con adjunto la fila se guarda con el tipo del archivo y no
                    // como 'template': así el hilo y la pestaña Archivos la
                    // renderizan como cualquier otro adjunto. Es la misma
                    // convención que usa el envío de cotizaciones, y SendMessage
                    // mira el payload antes que el tipo justamente por esto.
                    'type'            => $headerType ?: 'template',
                    // Vista legible para el hilo: el envío real usa el payload.
                    'body'            => $this->renderTemplate($template['body'], $variables),
                    'media_path'      => $file['url'] ?? null,
                    'media_mime'      => $file['mime'] ?? null,
                    'media_name'      => $file['name'] ?? null,
                    'status'          => 'queued',
                    'sender'          => 'human',
                    'payload'         => array_filter([
                        // La forma la impone OutboundTemplate::fromArray(), que
                        // es quien reconstruye esto en el worker. Con otras
                        // claves el envío sale sin nombre de plantilla y falla en
                        // la cola, no acá.
                        'template' => [
                            'name'        => $template['name'],
                            'language'    => $template['language'],
                            'body_params' => $variables,
                        ],
                        // Ruta relativa al disco: media_path es una URL pública y
                        // el driver necesita leer los bytes.
                        'media_disk_path' => $file['path'] ?? null,
                    ]),
                ]);

                $conversation->forceFill(['last_message_at' => now()])->save();

                return $message;
            });
        } catch (AttachmentStorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json(['message' => $this->transform($message)]);
    }

    /**
     * El tipo de archivo que exige el encabezado, o null si es de texto.
     *
     * Meta nombra los formatos en mayúscula y el CRM los tipos en minúscula; la
     * traducción vive acá y no en el catálogo, que solo refleja lo que Meta dice.
     */
    protected function headerMediaType(array $template): ?string
    {
        $format = strtolower((string) ($template['header_format'] ?? ''));

        return in_array($format, ['image', 'document', 'video'], true) ? $format : null;
    }

    /**
     * Los límites de tamaño y formato de Meta para ese tipo de archivo.
     *
     * Mismo control que sendMedia(): un archivo que Meta rechaza produce un 400
     * opaco desde Graph, y acá se convierte en un motivo que se puede leer.
     */
    protected function guardMediaLimits(Request $request, string $type): ?JsonResponse
    {
        $limits = (array) config('whatsapp.media.'.$type);

        $validator = Validator::make($request->all(), [
            'file' => [
                'file',
                'max:'.($limits['max_kb'] ?? 5120),
                ...(($limits['mimes'] ?? []) ? ['mimetypes:'.implode(',', $limits['mimes'])] : []),
            ],
        ], [
            'file.max'       => 'El archivo supera el límite de WhatsApp para '.$type.' ('.round(($limits['max_kb'] ?? 5120) / 1024, 1).' MB).',
            'file.mimetypes' => 'WhatsApp no acepta este formato como '.$type.'.',
        ]);

        return $validator->fails()
            ? response()->json(['message' => $validator->errors()->first('file')], 422)
            : null;
    }

    /**
     * Reemplaza los placeholders numerados por los valores, para el preview que
     * queda guardado en el mensaje.
     */
    protected function renderTemplate(string $body, array $variables): string
    {
        foreach ($variables as $i => $value) {
            $body = str_replace('{{'.($i + 1).'}}', $value, $body);
        }

        return $body;
    }

    /**
     * El driver que corresponde: el de la conversación cuando existe, el activo
     * cuando todavía se está por abrir una.
     */
    protected function gatewayFor(Request $request)
    {
        $conversation = $this->existingConversation($request);

        return $conversation
            ? $this->gateways->for($conversation)
            : $this->gateways->active();
    }

    protected function savedLocations(): array
    {
        return collect(config('whatsapp.locations', []))
            ->map(fn ($place) => [
                'name'      => $place['name'] ?? null,
                'address'   => $place['address'] ?? null,
                'latitude'  => (float) ($place['latitude'] ?? 0),
                'longitude' => (float) ($place['longitude'] ?? 0),
            ])
            ->filter(fn ($place) => $place['latitude'] || $place['longitude'])
            ->values()
            ->all();
    }

    /**
     * Shared pre-flight for every outbound path (text, media, location).
     *
     * Returns a JsonResponse when the send must be refused, null when it may
     * proceed - and claims the conversation as a side effect.
     */
    protected function guardOutbound($existing): ?JsonResponse
    {
        // Defense in depth: the UI locks the composer while the agent is on, but
        // reject here too so a human can never talk over the agent.
        if ($existing && $existing->aiEffective()) {
            return response()->json([
                'message' => 'El agente está activo en esta conversación. Desactivalo para escribir a mano.',
            ], 422);
        }

        // Writing on an unclaimed conversation IS claiming it - otherwise the
        // pool would keep advertising it as unattended while someone is already
        // answering. Writing on a conversation assigned to someone else is not
        // blocked: supervisors and stand-ins need to be able to step in.
        if ($existing && $existing->handoffPooled() && $this->userId()) {
            try {
                $this->handoff->claim($existing, $this->userId());
            } catch (HandoffConflictException) {
                // Someone claimed it a moment ago; the message still goes out.
            }
        }

        return null;
    }

    /**
     * There has to be somewhere to send it.
     *
     * A new conversation can only be opened by phone number, and only phone
     * channels have one. Without an existing thread and without a phone, the
     * message has no addressee - say so instead of failing deep inside the
     * resolver with a type error.
     */
    protected function guardAddressable($existing, array $data): ?JsonResponse
    {
        if ($existing || ! empty($data['phone'])) {
            return null;
        }

        return response()->json([
            'message' => 'No se puede iniciar una conversación nueva sin un número de teléfono. '
                .'Este canal solo permite responder conversaciones existentes.',
        ], 422);
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

        // 404 si no es suya (spec tablero-por-asesor, D10).
        app(ConversacionesVisibles::class)->exigir($conversation);

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
        $conversation = $this->buscarConversacion($request);
        $visibles = app(ConversacionesVisibles::class);

        // Spec tablero-por-asesor, D10: 404 si la conversación, el contacto o
        // el pedido no son del usuario. Un ejecutivo tampoco abre una
        // conversación nueva a un número suelto: quedaría sin contacto ni
        // pedido y ni él la vería después (D9).
        if ($conversation) {
            $visibles->exigir($conversation);
        } elseif ($request->filled('person_id')) {
            abort_unless($visibles->puedeVerContacto($request->input('person_id')), 404);
        } elseif ($request->filled('lead_id')) {
            abort_unless($visibles->puedeVerPedido($request->input('lead_id')), 404);
        } elseif ($request->filled('phone')) {
            abort_unless(app(\Webkul\Admin\Helpers\Alcance::class)->veTotal(), 404);
        }

        return $conversation;
    }

    /**
     * La conversación que pide el request, sin mirar permisos.
     */
    protected function buscarConversacion(Request $request)
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
            // Phone lookups only ever consider phone channels. A channel keyed
            // by something else (a Messenger PSID) must never be reached by
            // digits, or a contact's WhatsApp thread and a stranger's Messenger
            // thread can resolve to each other.
            $phoneChannels = $this->gateways->keysInFamily(GatewayManager::DEFAULT_FAMILY);

            $exact = $model::whereIn('gateway', $phoneChannels)
                ->where('wa_phone', PhoneNumber::normalize($phone))
                ->first();

            if ($exact) {
                return $exact;
            }

            // Tolerate a stored number that lacks the country code (or vice versa)
            // so the thread still resolves instead of showing empty.
            $tail = PhoneNumber::tail($phone);

            return strlen($tail) >= 7
                ? $model::whereIn('gateway', $phoneChannels)
                    ->where('wa_phone', 'like', '%'.$tail)
                    ->orderByDesc('id')
                    ->first()
                : null;
        }

        return null;
    }

    /**
     * A contact can end up with several conversations - one per provider after a
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
            // The thread renders these two from structured data, not from a
            // file. Both live in `payload`: outbound writes it directly, inbound
            // gets the provider's raw node.
            'location'    => $this->locationOf($message),
            'contact'     => $this->contactOf($message),
            'interactive' => $this->interactiveOf($message),
            'reaction'    => $this->reactionOf($message),
            'replyTo'     => $message->replyTo ? [
                'author'  => $message->replyTo->direction === 'inbound' ? 'Cliente' : 'Agente',
                'preview' => \Illuminate\Support\Str::limit((string) $message->replyTo->body, 60),
            ] : null,
        ];
    }

    /**
     * Botones o lista de un mensaje interactivo, en las dos direcciones.
     *
     * Saliente: las opciones que le ofrecimos al cliente, para que el asesor vea
     * en el hilo lo mismo que vio él. Entrante: la que tocó.
     *
     * Sin esto el hilo caía en «Tipo no soportado»: el mensaje existía, estaba
     * entregado, y el asesor no tenía forma de saber qué se le había ofrecido
     * ni qué había elegido el cliente.
     */
    protected function interactiveOf($message): ?array
    {
        if ($message->type !== 'interactive') {
            return null;
        }

        $nodo = (array) ($message->payload['interactive'] ?? []);

        if (! $nodo) {
            return null;
        }

        // Entrante: lo que el cliente eligió.
        $respuesta = $nodo['button_reply'] ?? $nodo['list_reply'] ?? null;

        if (is_array($respuesta)) {
            return [
                'direccion' => 'respuesta',
                'formato'   => isset($nodo['list_reply']) ? 'list' : 'button',
                'elegido'   => (string) ($respuesta['title'] ?? ''),
                'opciones'  => [],
            ];
        }

        // Saliente: lo que le ofrecimos.
        $opciones = [];

        foreach ($nodo['buttons'] ?? [] as $boton) {
            $opciones[] = (string) ($boton['title'] ?? '');
        }

        foreach ($nodo['sections'] ?? [] as $seccion) {
            foreach ($seccion['rows'] ?? [] as $fila) {
                $opciones[] = (string) ($fila['title'] ?? '');
            }
        }

        return [
            'direccion' => 'oferta',
            'formato'   => (string) ($nodo['format'] ?? 'button'),
            'elegido'   => null,
            'opciones'  => array_values(array_filter($opciones)),
            'boton'     => $nodo['list_button'] ?? null,
            'footer'    => $nodo['footer'] ?? null,
        ];
    }

    /**
     * Emoji de una reacción y a qué mensaje aplica.
     *
     * Una reacción no es un mensaje con contenido propio: es un emoji sobre
     * otro. Se resuelve el mensaje referido para poder mostrar «reaccionó 👍 a
     * "…"» en vez de un emoji suelto que no se entiende.
     */
    protected function reactionOf($message): ?array
    {
        if ($message->type !== 'reaction') {
            return null;
        }

        $nodo = (array) ($message->payload['reaction'] ?? []);

        $referido = null;

        if ($wamid = ($nodo['message_id'] ?? null)) {
            $referido = $message->newQuery()
                ->where('conversation_id', $message->conversation_id)
                ->where('wa_message_id', $wamid)
                ->value('body');
        }

        return [
            'emoji'   => (string) ($nodo['emoji'] ?? ''),
            'quitada' => ($nodo['emoji'] ?? '') === '',
            'sobre'   => $referido ? \Illuminate\Support\Str::limit((string) $referido, 60) : null,
        ];
    }

    /**
     * Coordinates of a location message, from either shape of `payload`:
     * ['location' => …] for what we sent, the provider's raw node for what we
     * received. Without this the thread renders an empty "Ubicación" card.
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
            // Deep link so the advisor can actually open the pin.
            'url'     => sprintf('https://www.google.com/maps?q=%s,%s', $node['latitude'], $node['longitude']),
        ];
    }

    /**
     * Name and phone of a shared contact card (inbound only for now).
     */
    protected function contactOf($message): ?array
    {
        if ($message->type !== 'contact') {
            return null;
        }

        // Cloud API pluralizes on the wire: contacts[] even for a single card.
        $card = $message->payload['contacts'][0] ?? null;

        if (! is_array($card)) {
            return null;
        }

        return [
            'name'  => $card['name']['formatted_name'] ?? 'Contacto',
            'phone' => $card['phones'][0]['phone'] ?? null,
        ];
    }
}
