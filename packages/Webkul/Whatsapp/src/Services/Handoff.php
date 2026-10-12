<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Exceptions\HandoffConflictException;
use Webkul\Whatsapp\Jobs\AvisarDerivacionSinRespuesta;
use Webkul\Whatsapp\Jobs\NotifyHandoff;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Moves a conversation from the AI agent to a human.
 *
 * Turning `ai_enabled` off IS the handoff, mechanically: IngestInboundPayload
 * only notifies the agent while it is on, NotifyAgent re-checks it at run time,
 * and InboxController::send() unlocks the composer once it is off. Everything
 * else here — state, reason, assignee, audit trail — exists so the team knows
 * *who* owes the customer an answer.
 *
 * This service never reads auth(). The actor is always passed in: the n8n route
 * runs under `auth:sanctum` (the token belongs to a real user, but the *actor*
 * is the bot), while the admin routes run under the `user` guard. Inferring it
 * here would credit a human for what the agent did.
 */
class Handoff
{
    public function __construct(protected ActivityRepository $activities) {}

    /**
     * Open a handoff.
     *
     * Idempotent: calling it again while one is already open does not re-route
     * and does not re-notify (an LLM tends to ask for a handoff on every single
     * message). It still forces the agent off, in case someone switched it back.
     *
     * @param  string  $source  'ai' | 'human'
     * @param  int|null  $actorUserId  Who asked; null = the AI agent.
     * @param  int|null  $assignTo  Skip owner routing and assign to this user.
     * @return array{conversation: Conversation, changed: bool}
     */
    public function request(
        Conversation $conversation,
        ?string $reason = null,
        string $source = 'ai',
        ?int $actorUserId = null,
        ?int $assignTo = null,
    ): array {
        $source = in_array($source, Conversation::HANDOFF_SOURCES, true) ? $source : 'ai';

        $result = DB::transaction(function () use ($conversation, $reason, $source, $assignTo) {
            /** @var Conversation $fresh */
            $fresh = $conversation->newQuery()->whereKey($conversation->id)->lockForUpdate()->first();

            if ($fresh->handoffOpen()) {
                // Already someone's problem. Only re-assert the agent is off.
                if ($fresh->ai_enabled !== false) {
                    $fresh->forceFill(['ai_enabled' => false])->save();
                }

                return ['conversation' => $fresh, 'changed' => false, 'owner' => $fresh->assigned_user_id];
            }

            // Re-deriving a resolved conversation routes from scratch: the lead
            // may have changed hands since the previous handoff.
            $owner = $assignTo ?? $this->ownerFor($fresh);

            $fresh->forceFill([
                'ai_enabled'           => false,
                'handoff_state'        => $owner ? Conversation::HANDOFF_ASSIGNED : Conversation::HANDOFF_REQUESTED,
                // The column holds 500; an LLM will happily write more.
                'handoff_reason'       => $reason ? Str::limit($reason, 490, '') : null,
                'handoff_source'       => $source,
                'handoff_requested_at' => now(),
                'assigned_user_id'     => $owner,
                'assigned_at'          => $owner ? now() : null,
            ])->save();

            return ['conversation' => $fresh, 'changed' => true, 'owner' => $owner];
        });

        if ($result['changed']) {
            $this->audit(
                $result['conversation'],
                $source === 'ai'
                    ? 'Derivación solicitada por el agente IA.'.($reason ? ' Motivo: '.$reason : '')
                    : 'Derivación solicitada por un asesor.'.($reason ? ' Motivo: '.$reason : ''),
                $actorUserId,
            );

            // Dispatched outside the transaction on purpose: with a Redis queue
            // a worker can pick the job up before the commit and read stale state.
            $this->notify($result['conversation'], $result['owner']);

            AvisarDerivacionSinRespuesta::agendar($result['conversation']);
        }

        return ['conversation' => $result['conversation'], 'changed' => $result['changed']];
    }

    /**
     * Take a pooled conversation.
     *
     * A single conditional UPDATE, not read-then-write: InnoDB serialises
     * updates on the same primary key, so with two advisors clicking at once
     * exactly one gets $affected === 1 and the other gets the conflict.
     *
     * @throws HandoffConflictException
     */
    public function claim(Conversation $conversation, int $userId): Conversation
    {
        $affected = DB::table($conversation->getTable())
            ->where('id', $conversation->id)
            ->where('handoff_state', Conversation::HANDOFF_REQUESTED)
            ->whereNull('assigned_user_id')
            ->update([
                'handoff_state'    => Conversation::HANDOFF_ASSIGNED,
                'assigned_user_id' => $userId,
                'assigned_at'      => now(),
                'ai_enabled'       => false,
                // DB::table() bypasses Eloquent, so timestamps are ours to keep.
                'updated_at'       => now(),
            ]);

        if ($affected === 0) {
            throw HandoffConflictException::alreadyClaimed();
        }

        $conversation->refresh();

        $this->audit($conversation, 'Derivación tomada por '.$this->userName($userId).'.', $userId);

        return $conversation;
    }

    /**
     * Hand an open conversation to a specific advisor (manual reassignment).
     *
     * @throws HandoffConflictException
     */
    public function reassign(Conversation $conversation, int $userId, ?int $actorUserId = null): Conversation
    {
        if (! $conversation->handoffOpen()) {
            throw HandoffConflictException::notOpen();
        }

        $previous = $conversation->assigned_user_id;

        $conversation->forceFill([
            'handoff_state'    => Conversation::HANDOFF_ASSIGNED,
            'assigned_user_id' => $userId,
            'assigned_at'      => now(),
            'ai_enabled'       => false,
        ])->save();

        $this->audit(
            $conversation,
            'Derivación reasignada a '.$this->userName($userId).'.',
            $actorUserId,
        );

        if ($previous !== $userId) {
            $this->notify($conversation, $userId);
        }

        return $conversation;
    }

    /**
     * Close the handoff.
     *
     * `$reactivateAi` defaults to true so a closed conversation never lands in
     * no-man's-land: agent off, no handoff open, nobody aware anyone should
     * answer. The UI offers a checkbox to keep it off when the advisor stays on.
     *
     * `assigned_user_id` is deliberately kept: it is the audit trail of who
     * handled it.
     *
     * @throws HandoffConflictException
     */
    public function resolve(Conversation $conversation, ?int $actorUserId = null, bool $reactivateAi = true): Conversation
    {
        if (! $conversation->handoffOpen()) {
            throw HandoffConflictException::notOpen();
        }

        $conversation->forceFill([
            'handoff_state' => Conversation::HANDOFF_RESOLVED,
            'ai_enabled'    => $reactivateAi ? true : $conversation->ai_enabled,
        ])->save();

        $this->audit(
            $conversation,
            'Derivación cerrada'.($actorUserId ? ' por '.$this->userName($actorUserId) : '').'.'
                .($reactivateAi ? ' El agente IA vuelve a atender.' : ' El agente IA queda desactivado.'),
            $actorUserId,
        );

        return $conversation;
    }

    /**
     * Who should get this conversation: the lead's owner, else the contact's,
     * else nobody (the pool).
     *
     * The active check matters. Without it, a conversation whose lead belongs to
     * someone who left stays "assigned" to a dead mailbox — invisible in the
     * pool, and the customer was never told anything (we send them nothing on
     * handoff by design). It would rot in silence.
     */
    public function ownerFor(Conversation $conversation): ?int
    {
        $responsables = app(\Webkul\Product\Services\Responsables::class);

        // Un asesor dueño del lead o del contacto se queda con su cliente
        // (activo, y en la matriz o con un rol que vende: Responsables::esAsesor).
        foreach ([$conversation->lead?->user_id, $conversation->person?->user_id] as $dueno) {
            if ($responsables->esAsesor($dueno ? (int) $dueno : null)) {
                return (int) $dueno;
            }
        }

        // Si no —el dueño es un administrador, el usuario del agente o
        // nadie—, quien atiende la ciudad del cliente, o el asesor por
        // defecto. Antes esto caía al pozo y la conversación no la tomaba nadie.
        if ($asesor = $responsables->porCiudad($responsables->ciudadDelContacto($conversation))) {
            return (int) $asesor->id;
        }

        // Sin asesor por defecto configurado y sin nadie en la ciudad: el pozo.
        return null;
    }

    /**
     * Shape the handoff for every JSON response that carries it, so the
     * front-end can apply them all through a single code path.
     */
    public function toArray(?Conversation $conversation, ?int $viewerId = null, ?bool $changed = null): array
    {
        if (! $conversation) {
            return [
                'state'         => null,
                'open'          => false,
                'pooled'        => false,
                'reason'        => null,
                'source'        => null,
                'requested_at'  => null,
                'assigned_user' => null,
                'assigned_at'   => null,
                'is_mine'       => false,
            ];
        }

        $conversation->loadMissing('assignedUser:id,name');

        // Every key is always present, nulls included: the front-end assigns
        // this object wholesale on each poll, so a missing key would leave the
        // previous conversation's value on screen.
        $payload = [
            'state'         => $conversation->handoff_state,
            'open'          => $conversation->handoffOpen(),
            'pooled'        => $conversation->handoffPooled(),
            'reason'        => $conversation->handoff_reason,
            'source'        => $conversation->handoff_source,
            'requested_at'  => optional($conversation->handoff_requested_at)->toIso8601String(),
            'assigned_user' => $conversation->assignedUser
                ? ['id' => $conversation->assignedUser->id, 'name' => $conversation->assignedUser->name]
                : null,
            'assigned_at'   => optional($conversation->assigned_at)->toIso8601String(),
            'is_mine'       => $viewerId !== null
                && $conversation->assigned_user_id !== null
                && (int) $conversation->assigned_user_id === (int) $viewerId,
        ];

        // Only the endpoints that perform an action report whether anything
        // actually changed; the polling payload omits it.
        if ($changed !== null) {
            $payload['changed'] = $changed;
        }

        return $payload;
    }

    /**
     * The same block, shaped for the external AI agent.
     *
     * The inbox needs the advisor's name, so toArray() models `assigned_user`
     * as an object. The agent's schema types that same key as an integer, so
     * once somebody claimed a conversation the agent rejected the whole event
     * (pydantic ValidationError) and stopped replying — while still answering
     * 200, which is why nothing ever failed loudly on our side.
     *
     * The id keeps the agreed key and the name travels beside it, so the agent
     * loses nothing. See planning/contrato-handoff-agente.md.
     */
    public function toAgentArray(?Conversation $conversation, ?bool $changed = null): array
    {
        $payload = $this->toArray($conversation, null, $changed);

        $user = $payload['assigned_user'] ?? null;

        $payload['assigned_user'] = is_array($user) ? ($user['id'] ?? null) : $user;
        $payload['assigned_user_name'] = is_array($user) ? ($user['name'] ?? null) : null;

        return $payload;
    }

    protected function notify(Conversation $conversation, ?int $userId): void
    {
        if (! config('whatsapp.handoff.notify', true)) {
            return;
        }

        NotifyHandoff::dispatch($conversation->id, $userId)->onQueue(config('whatsapp.queue'));
    }

    /**
     * Leave a Krayin note-activity on the contact/lead, the same way
     * InboxController::storeNote() does, so the handoff shows up on their
     * timeline and inside the WhatsApp thread for free.
     *
     * Unlike storeNote(), a conversation without person or lead is NOT an error
     * here: an unknown number with auto_create_person off is a normal case. And
     * a failure to audit must never abort the handoff itself — the hard facts
     * already live in the conversation's own columns.
     */
    protected function audit(Conversation $conversation, string $comment, ?int $actorUserId): void
    {
        if (! $conversation->person_id && ! $conversation->lead_id) {
            Log::info('WhatsApp: handoff without person/lead, no activity recorded', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        try {
            Event::dispatch('activity.create.before');

            $activity = $this->activities->create([
                'type'    => 'note',
                'comment' => $comment,
                'is_done' => 1,
                'user_id' => $actorUserId,
            ]);

            if ($conversation->person_id && $conversation->person) {
                $conversation->person->activities()->attach($activity->id);
            }

            if ($conversation->lead_id && $conversation->lead) {
                $conversation->lead->activities()->attach($activity->id);
            }

            Event::dispatch('activity.create.after', $activity);
        } catch (Throwable $e) {
            Log::warning('WhatsApp: could not record the handoff activity', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    protected function userName(?int $userId): string
    {
        if (! $userId) {
            return 'el sistema';
        }

        return UserProxy::modelClass()::where('id', $userId)->value('name') ?: 'un asesor';
    }
}
