<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\MessageProxy;

class SendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId) {}

    public function handle(GatewayManager $gateways): void
    {
        $message = MessageProxy::modelClass()::with(['conversation', 'replyTo'])->find($this->messageId);

        if (! $message || $message->status !== 'queued') {
            return;
        }

        try {
            $result = $gateways->for($message->conversation)->sendText(
                $message->conversation,
                (string) $message->body,
                $message->replyTo?->wa_message_id,
            );

            $message->update([
                'wa_message_id' => $result->providerMessageId,
                'status'        => 'sent',
                'error'         => null,
            ]);
        } catch (Throwable $e) {
            // Surface the reason where the agent can see it, not only in the log.
            $message->update([
                'status' => 'failed',
                'error'  => Str::limit($e->getMessage(), 500),
            ]);

            report($e);
        }
    }
}
