<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Repositories\HandoffRepository;

/**
 * Builds the contract the CRM sends to the external AI agent, and the shared
 * conversation history both the event and the agent's GET endpoint expose.
 */
class AgentPayload
{
    public function __construct(
        protected GatewayManager $gateways,
        protected HandoffRepository $handoffs,
    ) {}

    /**
     * The `message.received` event for an inbound message.
     */
    public function event(Message $message): array
    {
        $conversation = $message->conversation;
        $window = $this->window($conversation);

        return [
            'event'           => 'message.received',
            'conversation_id' => $conversation->id,
            'gateway'         => $conversation->gateway,
            // Texto libre a propósito, no un enum: el agente atiende varios
            // canales y no queremos redeployar el CRM para agregar uno.
            'channel'         => $conversation->gateway,
            'ai_enabled'      => $conversation->aiEffective(),
            'handoff'         => $this->handoff($conversation),
            'contact'         => $this->contact($conversation),
            'message'         => $this->mensaje($message),
            'history'         => $this->history($conversation),
            'window'          => [
                'open'       => $window->open,
                'expires_at' => $window->expiresAt?->toIso8601String(),
            ],
            'reply' => [
                'method' => 'POST',
                'url'    => url("api/v1/whatsapp/conversations/{$conversation->id}/messages"),
            ],
        ] + $this->captura($conversation);
    }

    /**
     * El mensaje que disparó el evento.
     *
     * `interactive` solo aparece cuando el mensaje ES una selección de botón o
     * lista; en el resto se omite la clave, para no obligar al agente a
     * distinguir «no hubo selección» de «hubo una vacía».
     *
     * @return array<string, mixed>
     */
    protected function mensaje(Message $message): array
    {
        $datos = [
            'id'        => $message->id,
            'type'      => $message->type,
            'text'      => $message->body,
            'timestamp' => optional($message->created_at)->toIso8601String(),
            'media'     => $this->media($message),
        ];

        if ($seleccion = $this->interactive($message)) {
            $datos['interactive'] = $seleccion;
        }

        return $datos;
    }

    /**
     * La opción que tocó el cliente, como {button_reply|list_reply}: {id, title}.
     *
     * Se lee del payload crudo que guardó la ingesta, no de columnas propias: es
     * el mismo objeto que mandó Meta, así que el agente recibe exactamente lo
     * que ocurrió sin que el CRM lo reinterprete.
     *
     * @return array<string, mixed>|null
     */
    public function interactive(Message $message): ?array
    {
        if ($message->type !== 'interactive') {
            return null;
        }

        $interactive = ((array) ($message->payload ?? []))['interactive'] ?? [];
        $clase = $interactive['type'] ?? null;

        // 'button_reply' o 'list_reply': la clave donde Meta anida la respuesta
        // lleva el mismo nombre que el tipo.
        if (! $clase || ! isset($interactive[$clase]) || ! is_array($interactive[$clase])) {
            return null;
        }

        return [$clase => $interactive[$clase]];
    }

    /**
     * Qué archivo trae un mensaje, para el agente.
     *
     * Deliberadamente sin URL ni binario: el agente no ve imágenes, y darle un
     * enlace lo tentaría a pasárselo al cliente, que ya tiene el archivo — se lo
     * acaba de mandar él.
     *
     * `disponible` refleja si el asesor puede abrirlo de verdad. Mientras la
     * descarga esté pendiente o haya fallado va en false, para que el agente no
     * afirme tener algo que del otro lado no está.
     *
     * @return array{kind: string, disponible: bool}|null
     */
    public function media(Message $message): ?array
    {
        $conArchivo = ['image', 'video', 'audio', 'document', 'sticker'];

        if (! in_array($message->type, $conArchivo, true)) {
            return null;
        }

        return [
            'kind'       => $message->type,
            'disponible' => filled($message->media_path),
        ];
    }

    /**
     * Estado del handoff.
     *
     * Mientras `open` es true el agente no debe responder: la conversación la
     * está atendiendo una persona. Es la misma señal que hace que el endpoint de
     * envío devuelva 409.
     *
     * @return array{state: string, open: bool, asesor: ?string}
     */
    public function handoff(Conversation $conversation): array
    {
        $handoff = $this->handoffs->abiertoDe($conversation->id);

        return [
            'state'  => $handoff?->state ?? 'ninguno',
            'open'   => (bool) $handoff,
            'asesor' => $handoff?->asesor?->name,
        ];
    }

    /**
     * Señales de captura de teléfono (§7 del handoff del agente).
     *
     * `phone_required` dice que FALTA el número, no que haya que pedirlo ahora:
     * cuándo y cómo pedirlo lo decide el agente, que es quien sabe si la
     * conversación calificó. Los contadores los lleva el CRM para que
     * sobrevivan a un reinicio del servicio del agente.
     *
     * @return array<string, bool|int>
     */
    public function captura(Conversation $conversation): array
    {
        $tope = (int) config('whatsapp.ai.phone_prompt_max', 3);
        $intentos = (int) $conversation->phone_prompt_attempts;

        return [
            'phone_required'         => blank($conversation->wa_phone),
            'phone_prompt_attempts'  => $intentos,
            'phone_prompt_exhausted' => $conversation->phone_rechazado || $intentos >= $tope,
        ];
    }

    /**
     * Qué buscó, vio o consultó antes este contacto.
     *
     * Sirve para que el agente no vuelva a preguntar lo que ya sabe ni salude
     * como si fuera la primera vez.
     *
     * @return array<string, mixed>
     */
    public function historial(Conversation $conversation): array
    {
        $person = $conversation->person;

        $leads = $person
            ? $person->leads()->with(['type', 'stage'])->latest('id')->limit(10)->get()
            : collect();

        return [
            'conversation_id' => $conversation->id,
            'contacto'        => $this->contact($conversation),
            'primera_vez'     => $leads->isEmpty(),
            'leads'           => $leads->map(fn ($lead) => [
                'referencia' => 'Solicitud #'.$lead->id,
                'titulo'     => $lead->title,
                'tipo'       => $lead->type?->name,
                'etapa'      => $lead->stage?->name,
                'creado'     => optional($lead->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    public function contact(Conversation $conversation): array
    {
        return [
            'phone'     => $conversation->wa_phone,
            'name'      => $conversation->wa_name,
            'person_id' => $conversation->person_id,
            'lead_id'   => $conversation->lead_id,
        ];
    }

    /**
     * Recent messages as role/content pairs for the agent's context.
     *
     * @return array<int, array{role: string, content: ?string, type: string}>
     */
    public function history(Conversation $conversation): array
    {
        $limit = (int) config('whatsapp.ai.history_size', 15);

        return $conversation->messages()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->map(fn (Message $m) => array_filter([
                'role'    => $m->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $m->body,
                'type'    => $m->type,
                'media'   => $this->media($m),
            ], fn ($v) => $v !== null || true))
            ->values()
            ->all();
    }

    protected function window(Conversation $conversation): MessagingWindow
    {
        return MessagingWindow::compute(
            $this->gateways->for($conversation)->messagingWindowHours(),
            $conversation->last_inbound_at,
        );
    }
}
