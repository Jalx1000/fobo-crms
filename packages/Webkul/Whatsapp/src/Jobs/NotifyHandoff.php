<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Mail\HandoffRequested;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Emails whoever now owes the customer an answer.
 *
 * Note this is a *backup* signal, not the mechanism: the CRM sends the customer
 * nothing on handoff, and there is no in-app notification system in Krayin, so
 * the real-time cue is the "Sin asesor" chip in the inbox list, which refreshes
 * on its own. Mail can bounce, queue up or land in spam; the chip cannot.
 */
class NotifyHandoff implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  int|null  $userId  Assigned advisor; null = pooled, notify the team.
     */
    public function __construct(
        public int $conversationId,
        public ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $conversation = ConversationProxy::modelClass()::with(['person:id,name', 'assignedUser:id,name,email'])
            ->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $recipients = $this->recipients();

        if (! $recipients) {
            // Nothing to retry: no addresses configured is a setup problem, not
            // a transient failure. Throwing would burn three attempts for nothing.
            Log::warning('WhatsApp: handoff with no one to notify', [
                'conversation_id' => $this->conversationId,
                'user_id'         => $this->userId,
            ]);

            return;
        }

        try {
            Mail::to($recipients)->send(new HandoffRequested($conversation, $this->userId !== null));
        } catch (Throwable $e) {
            Log::error('WhatsApp: could not send the handoff notification', [
                'conversation_id' => $this->conversationId,
                'error'           => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Assigned → that advisor's address. Pooled → the team's.
     *
     * Both come from the CRM's own users: the address to write to is the one
     * the person already logs in with, so nobody has to keep a second list in
     * sync with the staff.
     *
     * @return array<int, string>
     */
    protected function recipients(): array
    {
        if ($this->userId !== null) {
            $email = UserProxy::modelClass()::where('id', $this->userId)->value('email');

            return $email ? [$email] : [];
        }

        return $this->poolRecipients();
    }

    /**
     * The team that covers unclaimed conversations: every active user, narrowed
     * to a Krayin group when one is configured, minus the accounts explicitly
     * excluded (the API/bot user has a mailbox nobody reads).
     *
     * @return array<int, string>
     */
    protected function poolRecipients(): array
    {
        $query = UserProxy::modelClass()::query()
            ->where('status', 1)
            ->whereNotNull('email');

        if ($groupId = config('whatsapp.handoff.pool_group_id')) {
            $query->whereHas('groups', fn ($q) => $q->where('groups.id', $groupId));
        }

        if ($excluded = array_filter((array) config('whatsapp.handoff.exclude_user_ids', []))) {
            $query->whereNotIn('id', $excluded);
        }

        // Roles that manage rather than attend: mailing them on every unclaimed
        // conversation is noise, and it is not their job to answer it.
        if ($roles = array_filter((array) config('whatsapp.handoff.exclude_roles', []))) {
            $query->whereHas('role', fn ($q) => $q->whereNotIn('name', $roles));
        }

        $emails = $query->pluck('email')->all();

        // Extra addresses (a shared sales mailbox, say) on top of the users.
        $extra = array_map('trim', (array) config('whatsapp.handoff.pool_emails', []));

        return array_values(array_unique(array_filter(array_merge($emails, $extra))));
    }
}
