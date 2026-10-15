<?php

namespace Webkul\Whatsapp\Jobs\Concerns;

use Webkul\Whatsapp\Jobs\ReplyAfterAgent;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Responder por el chat el resultado de algo hecho en segundo plano (formularios
 * de WhatsApp). Va dentro de la ventana de 24 h: el usuario acaba de completar
 * el formulario.
 */
trait RepliesToConversation
{
    /** Cuándo se completó el formulario (unix): desde ahí se espera la respuesta del agente. */
    public ?int $requestedAt = null;

    /**
     * Responde el resultado del formulario después del "estoy registrando…" del
     * agente (ver ReplyAfterAgent), no antes.
     */
    protected function replyAfterAgent(int $conversationId, string $text): void
    {
        ReplyAfterAgent::dispatch($conversationId, $text, $this->requestedAt ?? time())
            ->onQueue(config('whatsapp.queue'));
    }

    protected function replyTo(int $conversationId, string $text): void
    {
        $conversation = ConversationProxy::modelClass()::find($conversationId);

        if (! $conversation) {
            return;
        }

        $message = MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'body'            => $text,
            'status'          => 'queued',
            'sender'          => 'system',
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));
    }
}
