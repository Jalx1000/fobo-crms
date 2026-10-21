<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\StatusUpdate;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Persists whatever the active gateway hands back. Knows nothing about any
 * provider's payload shape — that lives in the driver.
 */
class IngestInboundPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $gatewayKey  Driver that received the payload.
     * @param  array  $payload  Raw provider payload.
     */
    public function __construct(
        public string $gatewayKey,
        public array $payload,
    ) {}

    public function handle(GatewayManager $gateways, ConversationResolver $resolver): void
    {
        $batch = $gateways->driver($this->gatewayKey)->parseWebhook($this->payload);

        if (! $batch->messages && ! $batch->statuses) {
            // An authenticated delivery that yields nothing almost always means
            // the active gateway does not match the traffic hitting the webhook
            // (e.g. WHATSAPP_GATEWAY=kommo while Meta posts here). Dropping it
            // silently loses real customer messages, so say so loudly.
            Log::warning('WhatsApp: webhook payload produced no events — is WHATSAPP_GATEWAY correct for this traffic?', [
                'gateway'      => $this->gatewayKey,
                'payload_keys' => array_keys($this->payload),
            ]);

            return;
        }

        foreach ($batch->messages as $message) {
            $this->persistMessage($message, $resolver);
        }

        foreach ($batch->statuses as $status) {
            $this->applyStatus($status);
        }
    }

    /**
     * Idempotent by the provider's message id: webhooks get retried.
     */
    protected function persistMessage(InboundMessage $inbound, ConversationResolver $resolver): void
    {
        $model = MessageProxy::modelClass();

        if ($model::where('wa_message_id', $inbound->providerMessageId)->exists()) {
            return;
        }

        $conversation = $resolver->resolve($inbound->contact, $inbound->providerConversationId, $this->gatewayKey);

        if (! $conversation) {
            return;
        }

        $created = $model::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'type'            => $inbound->type,
            'body'            => $inbound->body,
            'wa_message_id'   => $inbound->providerMessageId,
            'reply_to_id'     => $this->resolveReplyTo($inbound->replyToProviderId),
            'status'          => 'delivered',
            'sender'          => 'contact',
            /*
            | Para los mensajes que Meta marca `unsupported` se guarda el SOBRE
            | COMPLETO del webhook, no solo el objeto del mensaje.
            |
            | Caso real: llegaron códigos OTP con `type: unsupported` y error
            | 131051, y el objeto del mensaje no trae ningún campo de texto —el
            | código no estaba—. Guardando el sobre entero, si Meta pone algo
            | aprovechable en algún nivel de arriba, queda registrado y se puede
            | leer; y si no pone nada, queda demostrado de una vez en lugar de
            | sospechado cada vez.
            |
            | Solo para este tipo: duplicar el sobre en cada mensaje normal
            | engordaría la tabla sin aportar nada.
            */
            'payload'         => $inbound->type === 'unsupported'
                ? ['mensaje' => $inbound->raw, 'webhook' => $this->payload]
                : $inbound->raw,
            // El nombre solo viene en documentos; el resto se nombra al guardar.
            'media_name'      => $inbound->mediaFilename,
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(), // reopens the 24h messaging window
            'unread_count'    => $conversation->unread_count + 1,
        ])->save();

        /*
        | El archivo se descarga aparte.
        |
        | Acá no se hace ninguna llamada de red nueva: el mensaje ya está creado
        | y el agente ya puede ser notificado. Descargar en línea ataría el acuse
        | a Meta a la velocidad de la descarga, y Meta reintenta el webhook si
        | tardamos — creando el mismo mensaje otra vez.
        */
        if ($inbound->tieneArchivo() && $this->descargable($inbound->type)) {
            DescargarMediaEntrante::dispatch($created->id)->onQueue(config('whatsapp.queue'));
        }

        // Hand the message to the AI agent — but only while it is enabled for
        // this conversation. Off = a human is handling it.
        if ($conversation->aiEffective()) {
            NotifyAgent::dispatch($created->id)->onQueue(config('whatsapp.queue'));
        }
    }

    /**
     * Never regress: providers can deliver receipts out of order.
     */
    protected function applyStatus(StatusUpdate $update): void
    {
        $message = MessageProxy::modelClass()::where('wa_message_id', $update->providerMessageId)->first();

        if (! $message) {
            return;
        }

        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 3];

        if (($rank[$update->status] ?? 0) < ($rank[$message->status] ?? 0)) {
            return;
        }

        $message->update(['status' => $update->status]);
    }

    protected function resolveReplyTo(?string $providerId): ?int
    {
        if (! $providerId) {
            return null;
        }

        return MessageProxy::modelClass()::where('wa_message_id', $providerId)->value('id');
    }

    /**
     * ¿Este tipo está entre los que la instancia decidió guardar?
     *
     * Configurable y no fijo porque la decisión es de negocio: los audios de
     * voz, por ejemplo, se acumulan rápido con retención permanente, y una
     * instalación podría preferir no guardarlos.
     */
    protected function descargable(string $tipo): bool
    {
        return in_array($tipo, (array) config('whatsapp.inbound_media.tipos', []), true);
    }
}
