<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
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

        // BEFORE the media branch on purpose: a template row is stored as
        // type=document (so the thread and the Docs tab render it like any other
        // attachment), and isMedia('document') is true. Checked the other way
        // round, this branch would never be reached.
        if ($template = $message->payload['template'] ?? null) {
            return $gateway->sendTemplate(
                $message->conversation,
                OutboundTemplate::fromArray($template, $this->mediaOf($message)),
                $replyTo,
            );
        }

        if (Capabilities::isMedia($message->type)) {
            // A media row with no staged path is a corrupt row, not an empty
            // send. Saying so beats letting a TypeError or an opaque "file not
            // found" reach the agent through the failure column.
            if (! $media = $this->mediaOf($message)) {
                throw new RuntimeException("El mensaje #{$message->id} es de tipo [{$message->type}] pero no tiene ningún archivo guardado.");
            }

            return $gateway->sendMedia($message->conversation, $media, $replyTo);
        }

        return $gateway->sendText($message->conversation, (string) $message->body, $replyTo);
    }

    /**
     * The staged file behind a message row.
     *
     * Shared by the media and template branches: an attachment is the same file
     * whether it travels as a document message or as a template header, and the
     * columns it lives in do not change with the delivery route.
     */
    protected function mediaOf($message): ?OutboundMedia
    {
        $path = (string) ($message->payload['media_disk_path'] ?? '');

        if ($path === '') {
            return null;
        }

        return new OutboundMedia(
            type: $message->type,
            // Disk-relative, parked at staging time. media_path holds the
            // public URL instead, which is what the browser renders.
            path: $path,
            mime: $message->media_mime,
            filename: $message->media_name,
            caption: $message->body,
        );
    }
}
