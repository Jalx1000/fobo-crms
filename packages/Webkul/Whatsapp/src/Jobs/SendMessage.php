<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
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
            $result = $this->deliver($gateways, $message);

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

    /**
     * Route the row to the right gateway call. The message `type` is the single
     * source of truth — it is what the composer set and what the thread renders.
     */
    protected function deliver(GatewayManager $gateways, $message): SendResult
    {
        $gateway = $gateways->for($message->conversation);
        $replyTo = $message->replyTo?->wa_message_id;

        if ($message->type === 'location') {
            return $gateway->sendLocation(
                $message->conversation,
                OutboundLocation::fromArray($message->payload['location'] ?? []),
                $replyTo,
            );
        }

        if (Capabilities::isMedia($message->type)) {
            return $gateway->sendMedia(
                $message->conversation,
                new OutboundMedia(
                    type: $message->type,
                    // Disk-relative, parked at staging time. media_path holds the
                    // public URL instead, which is what the browser renders.
                    path: (string) ($message->payload['media_disk_path'] ?? ''),
                    mime: $message->media_mime,
                    filename: $message->media_name,
                    caption: $message->body,
                ),
                $replyTo,
            );
        }

        return $gateway->sendText($message->conversation, (string) $message->body, $replyTo);
    }
}
