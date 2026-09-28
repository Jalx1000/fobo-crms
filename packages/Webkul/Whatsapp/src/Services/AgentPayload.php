<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

/**
 * Builds the contract the CRM sends to the external AI agent, and the shared
 * conversation history both the event and the agent's GET endpoint expose.
 */
class AgentPayload
{
    public function __construct(
        protected GatewayManager $gateways,
        protected Handoff $handoff,
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
            /*
            | Lo que el agente necesita saber es «¿contesto yo?», no «¿está la
            | IA encendida?». Con un handoff pedido son cosas distintas: la IA
            | sigue encendida pero el turno es del asesor.
            */
            'ai_enabled'      => $conversation->agenteDebeResponder(),
            'contact'         => $this->contact($conversation),
            'message'         => [
                'id'        => $message->id,
                'type'      => $message->type,
                'text'      => $message->body,
                /*
                | Presente solo cuando el cliente tocó un botón o eligió de una
                | lista. `text` ya trae el título —lo que el cliente ve—, pero el
                | agente debe rutear por el `id`: los títulos se acortan a 20/24
                | caracteres y pueden repetirse entre menús, el id no.
                */
                'selection' => $this->selection($message),
                'timestamp' => optional($message->created_at)->toIso8601String(),
            ],
            'history' => $this->history($conversation),
            'handoff' => $this->handoff->toAgentArray($conversation),
            'window'  => [
                'open'       => $window->open,
                'expires_at' => $window->expiresAt?->toIso8601String(),
            ],
            'reply' => [
                'method' => 'POST',
                'url'    => url("api/v1/whatsapp/conversations/{$conversation->id}/messages"),
            ],
        ];
    }

    /**
     * La opción que el cliente eligió, si el mensaje vino de un botón o lista.
     *
     * Se lee del payload crudo que guardó el webhook en vez de una columna
     * propia: es el mismo dato que mandó Meta, sin una copia que pueda quedar
     * desincronizada.
     *
     * @return array{id: string, title: string}|null
     */
    protected function selection(Message $message): ?array
    {
        $interactive = $message->payload['interactive'] ?? null;

        if (! is_array($interactive)) {
            return null;
        }

        $respuesta = $interactive['button_reply'] ?? $interactive['list_reply'] ?? null;

        if (! is_array($respuesta) || ! isset($respuesta['id'])) {
            return null;
        }

        return [
            'id'    => (string) $respuesta['id'],
            'title' => (string) ($respuesta['title'] ?? ''),
        ];
    }

    public function contact(Conversation $conversation): array
    {
        return [
            // Null on channels that do not use phone numbers (Messenger). The
            // agent is told which channel it is answering on so it can adapt
            // its wording instead of assuming WhatsApp.
            'phone'     => $conversation->wa_phone,
            'channel'   => $conversation->gateway,
            'name'      => $conversation->wa_name,
            'person_id' => $conversation->person_id,
            'lead_id'   => $conversation->lead_id,
        ];
    }

    /**
     * Recent messages as role/content pairs for the agent's context.
     *
     * `sender` dice QUIEN escribió cada mensaje: 'ia', 'human' o 'contact'.
     *
     * Sin ese dato, todo lo saliente viajaba como `role: assistant` y el agente
     * leía como PROPIAS las palabras de un asesor. Con eso pasó lo del 16/09:
     * un asesor cerró con «Ok gracias por escribirnos», el agente lo tomó como
     * su propia despedida y no volvió a contestarle al cliente, que escribió
     * tres veces más. El `role` no cambia —los modelos esperan user/assistant—;
     * el quién viaja al lado.
     *
     * @return array<int, array{role: string, sender: ?string, content: ?string, type: string}>
     */
    public function history(Conversation $conversation): array
    {
        $limit = (int) config('whatsapp.ai.history_size', 15);

        return $conversation->messages()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->map(fn (Message $m) => [
                'role'    => $m->direction === 'inbound' ? 'user' : 'assistant',
                'sender'  => $m->sender,
                'content' => $m->body,
                'type'    => $m->type,
            ])
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
