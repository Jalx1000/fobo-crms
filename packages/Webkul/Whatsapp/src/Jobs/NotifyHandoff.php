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
     * Assigned → that advisor. Pooled → the configured team addresses.
     *
     * The pool list comes from config on purpose. Deriving it from roles would
     * mean a mail to every user who can see WhatsApp — dozens of people, on
     * every pooled conversation.
     *
     * @return array<int, string>
     */
    protected function recipients(): array
    {
        if ($this->userId !== null) {
            $email = UserProxy::modelClass()::where('id', $this->userId)->value('email');

            return $email ? [$email] : [];
        }

        return array_values(array_filter(
            array_map('trim', (array) config('whatsapp.handoff.pool_emails', []))
        ));
    }
}
