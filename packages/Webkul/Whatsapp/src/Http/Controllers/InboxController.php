<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\PhoneNumber;

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
            'replyTo'   => $message->replyTo ? [
                'author'  => $message->replyTo->direction === 'inbound' ? 'Cliente' : 'Agente',
                'preview' => \Illuminate\Support\Str::limit((string) $message->replyTo->body, 60),
            ] : null,
        ];
    }
}
