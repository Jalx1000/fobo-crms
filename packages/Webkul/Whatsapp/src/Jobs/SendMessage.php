<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\Message;
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
            $result = $this->despachar($gateways, $message);

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
     * Elige el método del gateway según el tipo del mensaje.
     *
     * Todo lo que el tipo necesita viaja en `payload`, resuelto por el CRM antes
     * de encolar: para cuando este job corre, ya no hay que consultar el
     * catálogo ni decidir qué foto mandar.
     */
    protected function despachar(GatewayManager $gateways, Message $message): SendResult
    {
        $gateway = $gateways->for($message->conversation);
        $payload = (array) ($message->payload ?? []);

        return match ($message->type) {
            'location' => $gateway->sendLocation($message->conversation, new OutboundLocation(
                latitude: (float) $payload['latitude'],
                longitude: (float) $payload['longitude'],
                name: $payload['name'] ?? null,
                address: $payload['address'] ?? null,
            )),

            'image', 'video', 'document' => $gateway->sendMedia($message->conversation, new OutboundMedia(
                kind: $message->type,
                providerMediaId: $payload['provider_media_id'] ?? null,
                url: $payload['url'] ?? null,
                caption: $message->body,
                filename: $message->media_name,
            )),

            default => $gateway->sendText(
                $message->conversation,
                (string) $message->body,
                $message->replyTo?->wa_message_id,
            ),
        };
    }
}
