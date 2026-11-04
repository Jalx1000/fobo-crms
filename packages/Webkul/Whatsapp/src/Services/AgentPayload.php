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
        $parts = $this->parts($message);

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
                /*
                | Con una ráfaga, `text` trae todos los mensajes unidos en el
                | orden en que llegaron, y `parts` cada uno por separado.
                */
                'text'      => count($parts) > 1
                    ? implode("\n", array_filter(array_column($parts, 'text'), fn ($t) => trim((string) $t) !== ''))
                    : $message->body,
                'parts'     => $parts,
                /*
                | Presente solo cuando el cliente tocó un botón o eligió de una
                | lista. `text` ya trae el título -lo que el cliente ve-, pero el
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
            /*
            | Dónde quedan las plantas y qué se dice si el cliente está en otra
            | ciudad. Sale de Configuración → Almacenes.
            */
            'ubicaciones' => $this->ubicaciones(),
            'reply'       => [
                'method' => 'POST',
                'url'    => url("api/v1/whatsapp/conversations/{$conversation->id}/messages"),
            ],
        ];
    }

    protected function ubicaciones(): ?array
    {
        try {
            return app(\Webkul\Warehouse\Services\Ubicaciones::class)->paraAgente();
        } catch (\Throwable) {
            // Nunca frenar el mensaje al agente por esto.
            return null;
        }
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
    /**
     * La opción elegida, para quien no arma el payload (el ingreso del mensaje).
     */
    public function seleccion(Message $message): ?array
    {
        return $this->selection($message);
    }

    protected function selection(Message $message): ?array
    {
        $interactive = $message->payload['interactive'] ?? null;

        if (! is_array($interactive)) {
            return $this->seleccionPorTexto($message);
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

    /**
     * Los mensajes del cliente desde la última respuesta, hasta este inclusive.
     *
     * @return array<int, array{id: int, type: string, text: ?string}>
     */
    protected function parts(Message $message): array
    {
        $ultimaRespuesta = $message->conversation->messages()
            ->where('direction', 'outbound')
            ->where('id', '<', $message->id)
            ->max('id') ?? 0;

        return $message->conversation->messages()
            ->where('direction', 'inbound')
            ->where('id', '>', $ultimaRespuesta)
            ->where('id', '<=', $message->id)
            ->orderBy('id')
            ->get(['id', 'type', 'body'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'type' => (string) $m->type, 'text' => $m->body])
            ->all();
    }

    /**
     * La opción elegida cuando llegó como texto (Kommo).
     *
     * Kommo entrega el toque de un botón o fila como un mensaje de texto con el
     * título, sin el `interactive` de Meta, y el agente repetía el menú porque
     * no sabía qué se había elegido. Se compara el texto con las opciones del
     * ÚLTIMO menú enviado -solo ese: los títulos pueden repetirse entre menús-
     * sin distinguir mayúsculas ni espacios en los extremos.
     *
     * @return array{id: string, title: string}|null
     */
    protected function seleccionPorTexto(Message $message): ?array
    {
        $texto = mb_strtolower(trim((string) $message->body));

        if ($message->direction !== 'inbound' || $message->type !== 'text' || $texto === '') {
            return null;
        }

        $menu = $message->conversation->messages()
            ->where('direction', 'outbound')
            ->where('id', '<', $message->id)
            ->where('type', 'interactive')
            ->latest('id')
            ->value('payload');

        $menu = is_array($menu) ? $menu : json_decode((string) $menu, true);
        $interactive = $menu['interactive'] ?? [];

        $opciones = array_merge(
            (array) ($interactive['buttons'] ?? []),
            ...array_map(fn ($s) => (array) ($s['rows'] ?? []), (array) ($interactive['sections'] ?? []))
        );

        foreach ($opciones as $opcion) {
            if (isset($opcion['id'], $opcion['title']) && mb_strtolower(trim((string) $opcion['title'])) === $texto) {
                return ['id' => (string) $opcion['id'], 'title' => (string) $opcion['title']];
            }
        }

        return null;
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
     * tres veces más. El `role` no cambia -los modelos esperan user/assistant-;
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
