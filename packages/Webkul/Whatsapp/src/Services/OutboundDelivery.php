<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Repositories\HandoffRepository;

/**
 * Encola un mensaje saliente y lo deja registrado en el inbox.
 *
 * Todo lo que sale hacia el cliente pasa por acá — texto, fotos, ubicación —
 * para que el asesor vea en su inbox exactamente lo mismo que recibió el
 * cliente. Un envío que no queda registrado es un envío que el humano no puede
 * continuar cuando toma la conversación.
 */
class OutboundDelivery
{
    public function __construct(
        protected GatewayManager $gateways,
        protected HandoffRepository $handoffs,
    ) {}

    /**
     * ¿Puede el agente escribir en esta conversación?
     *
     * Devuelve null si puede, o un par [código HTTP, motivo] si no. Se
     * distinguen los dos bloqueos porque significan cosas distintas para el
     * agente: 409 es "callate, la tomó un humano" y 422 es "se cerró la ventana
     * de 24 h".
     *
     * @return array{0: int, 1: string}|null
     */
    public function bloqueoPara(Conversation $conversation): ?array
    {
        if ($this->handoffs->abiertoDe($conversation->id)) {
            return [409, 'La conversación fue derivada a un asesor. El agente ya no responde acá.'];
        }

        $window = MessagingWindow::compute(
            $this->gateways->for($conversation)->messagingWindowHours(),
            $conversation->last_inbound_at,
        );

        if ($window->applies && ! $window->open) {
            return [422, 'Fuera de la ventana de 24h de WhatsApp. Se requiere una plantilla aprobada o esperar a que el cliente escriba.'];
        }

        return null;
    }

    public function texto(Conversation $conversation, string $texto, ?int $replyToId = null, string $sender = 'ia'): Message
    {
        return $this->encolar($conversation, [
            'type'        => 'text',
            'body'        => $texto,
            'reply_to_id' => $replyToId,
        ], $sender);
    }

    /**
     * @param  array<string, mixed>  $payload  provider_media_id y/o url
     */
    public function media(Conversation $conversation, string $kind, array $payload, ?string $caption = null, ?string $filename = null, string $sender = 'ia'): Message
    {
        return $this->encolar($conversation, [
            'type'       => $kind,
            'body'       => $caption,
            'media_name' => $filename,
            'payload'    => $payload,
        ], $sender);
    }

    /**
     * Botones o lista.
     *
     * El `body` guardado es el enunciado de la pregunta, no las opciones: es lo
     * que el asesor necesita leer en el inbox para entender qué se le preguntó.
     * El menú completo viaja en `payload`, de donde el job lo reconstruye — lo
     * que el controlador tenía en memoria no sobrevive al encolado, la fila sí.
     */
    public function interactivo(Conversation $conversation, OutboundInteractive $interactive, string $sender = 'ia'): Message
    {
        return $this->encolar($conversation, [
            'type'    => 'interactive',
            'body'    => $interactive->label(),
            'payload' => ['interactive' => $interactive->toArray()],
        ], $sender);
    }

    /**
     * Texto que el agente marcó como menú pero no entra en los límites de Meta.
     *
     * Sale como texto con la numeración reescrita a «1.» y el motivo guardado en
     * `payload.interactive_skipped`, para que la degradación sea diagnosticable
     * sin adivinar.
     */
    public function textoDegradado(Conversation $conversation, string $texto, string $motivo, string $sender = 'ia'): Message
    {
        return $this->encolar($conversation, [
            'type'    => 'text',
            'body'    => $texto,
            'payload' => ['interactive_skipped' => $motivo],
        ], $sender);
    }

    /**
     * Plantilla aprobada. No pasa por `bloqueoPara()`: es justamente lo que se
     * manda cuando la ventana de 24 h está cerrada.
     *
     * El `body` guardado es el texto ya completado —lo que leyó el cliente—;
     * nombre y parámetros viajan en `payload` para que el job arme el envío.
     */
    public function plantilla(Conversation $conversation, OutboundTemplate $template, string $sender = 'human'): Message
    {
        return $this->encolar($conversation, [
            'type'    => 'template',
            'body'    => $template->rendered(),
            'payload' => ['template' => $template->toArray()],
        ], $sender);
    }

    public function ubicacion(Conversation $conversation, float $lat, float $lng, ?string $nombre = null, ?string $direccion = null, string $sender = 'ia'): Message
    {
        return $this->encolar($conversation, [
            'type'    => 'location',
            'body'    => $nombre,
            'payload' => array_filter([
                'latitude'  => $lat,
                'longitude' => $lng,
                'name'      => $nombre,
                'address'   => $direccion,
            ], fn ($v) => $v !== null),
        ], $sender);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    /**
     * @param  array<string, mixed>  $datos
     * @param  string  $sender  'ia' cuando lo manda el agente, 'human' cuando lo
     *                          manda un asesor desde el inbox. El hilo los
     *                          muestra distinto, y confundirlos haría creer al
     *                          asesor que el agente ya respondió.
     */
    protected function encolar(Conversation $conversation, array $datos, string $sender = 'ia'): Message
    {
        $message = MessageProxy::modelClass()::create($datos + [
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'status'          => 'queued',
            'sender'          => $sender,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return $message;
    }
}
