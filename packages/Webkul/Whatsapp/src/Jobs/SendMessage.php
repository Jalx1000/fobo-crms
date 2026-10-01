<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\TemplateHeader;
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
            $gateway = $gateways->for($message->conversation);
            $conversation = $message->conversation;
            $replyTo = $message->replyTo?->wa_message_id;

            /*
             * The message type decides the send, not the caller. Until this
             * existed every outbound went out as sendText(), so a template or a
             * location would have been delivered as its preview text — the
             * customer receiving the words "Sucursal Central" instead of a map.
             *
             * The payload is the source of truth for everything but text: it is
             * written when the message is queued and survives a retry, while
             * whatever the controller had in memory does not.
             */
            $result = match ($message->type) {
                'template' => $gateway->sendTemplate(
                    $conversation,
                    (string) ($message->payload['template'] ?? ''),
                    (array) ($message->payload['variables'] ?? []),
                    $message->payload['language'] ?? null,
                    ($h = $message->payload['header'] ?? null)
                        ? TemplateHeader::fromArray((array) $h)
                        : null,
                ),

                'location' => $gateway->sendLocation(
                    $conversation,
                    OutboundLocation::fromArray((array) ($message->payload['location'] ?? [])),
                    $replyTo,
                ),

                'interactive' => $gateway->sendInteractive(
                    $conversation,
                    OutboundInteractive::fromArray((array) ($message->payload['interactive'] ?? [])),
                    $replyTo,
                ),

                'image', 'document', 'audio', 'video', 'sticker' => $gateway->sendMedia(
                    $conversation,
                    OutboundMedia::fromArray((array) ($message->payload['media'] ?? [])),
                    $replyTo,
                ),

                default => $gateway->sendText($conversation, (string) $message->body, $replyTo),
            };

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
