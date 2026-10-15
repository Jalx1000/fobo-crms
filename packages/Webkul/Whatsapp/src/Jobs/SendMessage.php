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

    /** Message types delivered as media rather than text. */
    private const MEDIA_TYPES = ['image', 'document', 'audio', 'video', 'sticker'];

    /**
     * @param  string|null  $gatewayOverride  Force a specific driver instead of
     *                                        the conversation's own gateway (reminders send via Cloud API while the
     *                                        inbox stays on Kommo).
     */
    public function __construct(public int $messageId, public ?string $gatewayOverride = null) {}

    public function handle(GatewayManager $gateways): void
    {
        $message = MessageProxy::modelClass()::with(['conversation', 'replyTo'])->find($this->messageId);

        if (! $message || $message->status !== 'queued') {
            return;
        }

        try {
            // Explicit override first, then the installation's outbound driver,
            // and only then the conversation's own gateway.
            $key = $this->gatewayOverride ?: config('whatsapp.outbound_gateway');

            $gateway = $key
                ? $gateways->driver((string) $key)
                : $gateways->for($message->conversation);

            if ($message->type === 'template') {
                $result = $gateway->sendTemplate(
                    $message->conversation,
                    (string) ($message->payload['template'] ?? ''),
                    (array) ($message->payload['variables'] ?? []),
                    $message->payload['language'] ?? null,
                );
            } elseif ($message->type === 'flow') {
                // Formulario de WhatsApp Flows: solo canales que lo soportan.
                if (! $gateway instanceof \Webkul\Whatsapp\Gateways\Contracts\SupportsFlows) {
                    throw new \RuntimeException('Este canal no soporta formularios de WhatsApp (Flows).');
                }

                $result = $gateway->sendFlow(
                    $message->conversation,
                    (string) $message->body,
                    (array) ($message->payload['flow'] ?? []),
                );
            } elseif (in_array($message->type, self::MEDIA_TYPES, true)) {
                // media_path holds the public URL the provider fetches from, and
                // body doubles as the caption (that is how the inbox renders it).
                $result = $gateway->sendMedia(
                    $message->conversation,
                    $message->type,
                    (string) $message->media_path,
                    $message->body,
                    $message->media_name,
                );
            } else {
                $result = $gateway->sendText(
                    $message->conversation,
                    (string) $message->body,
                    $message->replyTo?->wa_message_id,
                );
            }

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
