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
            'message'         => [
                'id'        => $message->id,
                'type'      => $message->type,
                'text'      => $message->body,
                'timestamp' => optional($message->created_at)->toIso8601String(),
            ],
            'history' => $this->history($conversation),
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
