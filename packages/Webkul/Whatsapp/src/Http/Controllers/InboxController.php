<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentTestMode;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\PhoneNumber;
use Webkul\Whatsapp\Services\StaffNumbers;
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

        // Receiving and sending can be two different providers (Kommo takes the
        // messages in, the Cloud API sends them out), so each half of the
        // capabilities has to come from the driver that actually does it —
        // otherwise the composer hides what the sending driver supports.
        // Resolved against the conversation's own gateway so history created
        // under a previous provider still reports the right abilities.
        $gateway = $conversation
            ? $this->gateways->for($conversation)
            : $this->gateways->active();

        $outbound = $this->gateways->outbound($conversation);

        return response()->json([
            'conversation' => $conversation,
            'messages'     => $messages->values(),
            'server_time'  => $serverTime->toDateTimeString(),
            'capabilities' => [
                'send'    => $this->sendCapabilities($gateway, $outbound),
                'receive' => $gateway->capabilities()->receive,
            ],
            // The window is a rule of whoever sends, so it follows the outbound
            // driver.
            'window'       => MessagingWindow::compute(
                $outbound->messagingWindowHours(),
                $conversation?->last_inbound_at,
            )->toArray(),
            'agent'        => [
                // raw = the per-conversation override (null = inherit global);
                // effective = what actually applies right now.
                'enabled'   => $conversation?->ai_enabled,
                // With no conversation yet there is nothing to read the flag
                // from, so the global default applies — except for staff, who
                // the agent never handles. Without this a doctor who has never
                // written shows the agent as active, which locks the composer
                // and with it the template button.
                'effective' => $conversation
                    ? $conversation->aiEffective()
                    : ((bool) config('whatsapp.ai.enabled') && ! StaffNumbers::isStaff($request->input('phone'))),
                // Why the switch may look ignored: in test mode the agent is
                // restricted to a whitelist, and this number is not on it.
                'test_blocked' => $conversation ? ! AgentTestMode::allows($conversation->wa_phone) : false,
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

        // Stamp the hand-over so whatsapp:reactivate-agent knows how long the
        // human has had it; cleared the moment the agent is back on.
        $conversation->update([
            'ai_enabled'     => $data['enabled'],
            'ai_disabled_at' => $data['enabled'] ? null : now(),
            // Recepción resolvió la derivación: deja de tener plazo especial.
            'ai_handoff_at'  => $data['enabled'] ? null : $conversation->ai_handoff_at,
        ]);

        return response()->json([
            'agent' => [
                'enabled'      => $conversation->ai_enabled,
                'effective'    => $conversation->aiEffective(),
                'test_blocked' => ! AgentTestMode::allows($conversation->wa_phone),
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

        // WhatsApp policy: free text is only allowed within 24h of the customer's
        // last message, and only THEIR message reopens that window — sending a
        // template does not. The UI hides the composer when it closes, but the
        // window can lapse between page load and send, so it is enforced here
        // too: otherwise the message is queued only for Meta to reject it.
        if ($existing) {
            $window = MessagingWindow::compute(
                $this->gateways->outbound($existing)->messagingWindowHours(),
                $existing->last_inbound_at,
            );

            if ($window->applies && ! $window->open) {
                return response()->json([
                    'message' => 'Ventana de 24h cerrada. Solo podés enviar una plantilla aprobada hasta que el cliente vuelva a escribir.',
                ], 422);
            }
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
     * Approved templates the agent can pick from.
     *
     * Never fails the inbox: a missing account id or an unreachable Meta means
     * an empty list plus the reason, so the composer degrades to "no templates"
     * instead of breaking the conversation.
     */
    public function templates(TemplateCatalog $catalog): JsonResponse
    {
        if (! $this->gateways->outbound()->capabilities()->canSend('template')) {
            return response()->json(['templates' => [], 'reason' => 'El canal actual no envía plantillas.']);
        }

        try {
            return response()->json(['templates' => $catalog->all()]);
        } catch (\Throwable $e) {
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
        ]);

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

        // Meta fills the placeholders by position and rejects the whole message
        // when the count differs, so it is caught here with a usable reason.
        if (count($variables) !== $template['variables']) {
            return response()->json([
                'message' => 'La plantilla necesita '.$template['variables'].' variable(s) y llegaron '.count($variables).'.',
            ], 422);
        }

        if (in_array('', array_map('trim', $variables), true)) {
            return response()->json(['message' => 'Completá todas las variables.'], 422);
        }

        // A conversation_id that resolves to nothing leaves us with no phone to
        // fall back on, so it is rejected before opening the transaction.
        if (! $existing && empty($data['phone'])) {
            return response()->json(['message' => 'No se encontró la conversación.'], 422);
        }

        $message = DB::transaction(function () use ($existing, $data, $resolver, $template, $variables) {
            $conversation = $existing
                ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'template',
                // Readable preview for the thread: the real send uses the payload.
                'body'            => $this->renderTemplate($template['body'], $variables),
                'status'          => 'queued',
                'sender'          => 'human',
                'payload'         => [
                    'template'  => $template['name'],
                    'language'  => $template['language'],
                    'variables' => $variables,
                ],
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json(['message' => $this->transform($message)]);
    }

    /**
     * Replace {{1}}, {{2}}... with the values, for the preview stored on the
     * message.
     */
    protected function renderTemplate(string $body, array $variables): string
    {
        foreach ($variables as $i => $value) {
            $body = str_replace('{{'.($i + 1).'}}', $value, $body);
        }

        return $body;
    }

    /**
     * Send a file (document, image, audio, video) to the conversation.
     *
     * The provider fetches the file by URL, so it is stored on the public disk
     * and the message keeps that URL in media_path — which is also what the
     * inbox renders from, so the thread shows the real attachment.
     */
    public function sendMedia(Request $request, ConversationResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'phone'   => 'required|string',
            'name'    => 'nullable|string',
            'file'    => 'required|file|max:102400', // ceiling; the real limit is per type below
            'caption' => 'nullable|string',
        ]);

        $existing = $this->existingConversation($request);

        // Same guard as send(): a human must never talk over the agent.
        if ($existing && $existing->aiEffective()) {
            return response()->json([
                'message' => 'El agente está activo en esta conversación. Desactivalo para escribir a mano.',
            ], 422);
        }

        $file = $request->file('file');
        $type = $this->mediaTypeFor($file->getMimeType(), $file->getClientMimeType());

        if (! $type) {
            return response()->json([
                'message' => 'WhatsApp no acepta este formato ('.$file->getClientMimeType().').',
            ], 422);
        }

        if (! $this->gateways->outbound($existing)->capabilities()->canSend($type)) {
            return response()->json([
                'message' => 'El canal actual no permite enviar este tipo de archivo.',
            ], 422);
        }

        $maxKb = self::MEDIA_RULES[$type]['max'];

        if ($file->getSize() > $maxKb * 1024) {
            return response()->json([
                'message' => 'El archivo supera el máximo de WhatsApp para '.$type.' ('.round($maxKb / 1024).' MB).',
            ], 422);
        }

        $path = $file->store('whatsapp', 'public');
        $mime = $this->mimeForType($type, $file->getClientMimeType(), $file->getMimeType());

        $message = DB::transaction(function () use ($existing, $data, $resolver, $file, $type, $path, $mime) {
            $conversation = $existing
                ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => $type,
                'body'            => $data['caption'] ?? null,
                // Served by our own route so the Content-Type matches the type
                // declared to WhatsApp (see MediaController).
                'media_path'      => route('whatsapp.media', ['file' => basename($path)]),
                'media_mime'      => $mime,
                'media_name'      => $file->getClientOriginalName(),
                'status'          => 'queued',
                'sender'          => 'human',
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json(['message' => $this->transform($message)]);
    }

    /**
     * What WhatsApp accepts per media type, straight from Meta's own reference:
     * exact mime types and a size ceiling that differs for each one.
     *
     * Matching by mime prefix instead would happily send a .wav or a .gif and
     * let Meta reject the message, leaving it failed with nothing to show the
     * agent. Anything not listed here is refused up front with a clear reason.
     */
    private const MEDIA_RULES = [
        'audio' => [
            'mimes' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'],
            'max'   => 16384, // KB
        ],
        'image' => [
            'mimes' => ['image/jpeg', 'image/png'],
            'max'   => 5120,
        ],
        'video' => [
            'mimes' => ['video/mp4', 'video/3gp', 'video/3gpp'],
            'max'   => 16384,
        ],
        'sticker' => [
            'mimes' => ['image/webp'],
            'max'   => 100,
        ],
        'document' => [
            'mimes' => [
                'text/plain',
                'application/pdf',
                'application/msword',
                'application/vnd.ms-excel',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            'max' => 102400,
        ],
    ];

    /**
     * The media type WhatsApp would deliver this file as, or null when it
     * accepts none.
     *
     * Sniffing the contents alone is not enough: a voice note recorded as
     * audio/mp4 is an MP4 container with no video track, and detection reports
     * it as video/mp4 — so notes went out as videos. The browser's own mime
     * breaks that tie, and is only trusted when WhatsApp accepts it anyway.
     */
    protected function mediaTypeFor(?string $detected, ?string $declared = null): ?string
    {
        foreach ([$declared, $detected] as $mime) {
            // Browsers append codec parameters ("audio/ogg;codecs=opus").
            $mime = trim(explode(';', (string) $mime)[0]);

            foreach (self::MEDIA_RULES as $type => $rule) {
                if (in_array($mime, $rule['mimes'], true)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * The mime to serve the file as, matching the type we declare to WhatsApp.
     */
    protected function mimeForType(string $type, ?string $declared, ?string $detected): string
    {
        $allowed = self::MEDIA_RULES[$type]['mimes'];

        foreach ([$declared, $detected] as $mime) {
            $mime = trim(explode(';', (string) $mime)[0]);

            if (in_array($mime, $allowed, true)) {
                return $mime;
            }
        }

        return $allowed[0];
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
     * Send an appointment reminder through the active gateway (used by the
     * calendar). Unlike send(), it does NOT block when the AI agent is on: a
     * reminder is a deliberate, templated staff/system message, not a human
     * talking over the agent. It is logged in the patient's conversation.
     */
    public function sendReminder(Request $request, ConversationResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'phone'     => 'required|string',
            'name'      => 'nullable|string',
            'text'      => 'required|string',
            'person_id' => 'nullable|integer',
        ]);

        $message = DB::transaction(function () use ($request, $data, $resolver) {
            $conversation = $this->existingConversation($request)
                ?? $resolver->findOrCreate($data['phone'], $data['name'] ?? null);

            $message = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'text',
                'body'            => $data['text'],
                'status'          => 'queued',
                'sender'          => 'system',
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json([
            'message'         => 'Recordatorio encolado para envío por WhatsApp.',
            'conversation_id' => $message->conversation_id,
        ]);
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
            // Same canonical wa_id the resolver stores (full international, no
            // '+'), so a phone lookup matches the row it created.
            return $model::where('wa_phone', PhoneNumber::withCountryCode($phone, config('whatsapp.default_country_code')))->first();
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
     * What the composer may offer, given that receiving and sending can be two
     * different providers.
     *
     * Quoting a message is the one ability that does not survive that split: it
     * addresses the original by the provider's own message id, and the ids of
     * the receiving provider mean nothing to the sending one. Offering it would
     * show a reply that never quotes anything.
     */
    protected function sendCapabilities(Gateway $inbound, Gateway $outbound): array
    {
        $send = $outbound->capabilities()->send;

        if ($inbound->key() !== $outbound->key()) {
            $send = array_values(array_diff($send, ['reply']));
        }

        return $send;
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
            'replyTo'   => $message->replyTo ? [
                'author'  => $message->replyTo->direction === 'inbound' ? 'Cliente' : 'Agente',
                'preview' => \Illuminate\Support\Str::limit((string) $message->replyTo->body, 60),
            ] : null,
        ];
    }
}
