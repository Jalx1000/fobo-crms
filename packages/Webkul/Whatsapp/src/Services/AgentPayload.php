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
    public function __construct(protected GatewayManager $gateways) {}

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
            'ai_enabled'      => $conversation->aiEffective(),
            'contact'         => $this->contact($conversation),
            'message'         => $this->message($message),
            'history'         => $this->history($conversation),
            'window'          => [
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
     * One inbound message as the agent sees it.
     *
     * `text` is always the human-readable content, including when the customer
     * answered by tapping a button or a list row — those arrive from Meta with
     * no text at all, and the agent used to drop the turn. `type` still reports
     * what really happened, and `interactive` carries the option's id for
     * agents that would rather branch on the payload than on the label.
     */
    protected function message(Message $message): array
    {
        $selection = $this->gateways->for($message->conversation)
            ->interactiveSelection((array) $message->payload);

        $payload = [
            'id'        => $message->id,
            'type'      => $message->type,
            'text'      => $message->body,
            /*
             * La opción que el cliente tocó. MISMA CLAVE Y MISMA FORMA que en
             * imprimir (`selection` con {id, title}), porque el agente es el
             * mismo para los dos tenants: cuando kohlberg mandaba esto bajo otra
             * clave, el agente leía `selection` y encontraba undefined — los
             * botones y las listas "no se detectaban".
             *
             * `text` ya trae el título, que es lo que el cliente ve, pero el
             * agente debe rutear por el `id`: los títulos se recortan a 20/24
             * caracteres y pueden repetirse entre menús; el id no.
             */
            'selection' => $selection ? [
                'id'    => (string) ($selection['id'] ?? ''),
                'title' => (string) ($selection['title'] ?? ''),
            ] : null,
            'timestamp' => optional($message->created_at)->toIso8601String(),
        ];

        // Se mantiene la clave vieja para no romper a quien ya la lea.
        if ($selection) {
            $payload['interactive'] = $selection;
        }

        return $payload;
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
            ->map(fn (Message $m) => [
                'role'    => $m->direction === 'inbound' ? 'user' : 'assistant',
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
