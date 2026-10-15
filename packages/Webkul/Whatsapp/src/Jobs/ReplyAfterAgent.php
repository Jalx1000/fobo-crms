<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Jobs\Concerns\RepliesToConversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Envía el resultado de un formulario ("✅ Cita confirmada") DESPUÉS de que el
 * agente contestó el flow_reply ("¡Perfecto! Estoy registrando…").
 *
 * Son dos carreras independientes: la cita se crea en ~3 s, y el agente tarda
 * más en contestar (webhook + LLM). Sin esto la confirmación llegaba antes que
 * el "estoy registrando". Se espera a que haya un mensaje del agente posterior
 * al formulario, con un tope (`whatsapp.flows.confirm_wait_seconds`) para no
 * colgarse si el agente no contesta. Sin agente activo se envía al toque.
 *
 * El mensaje se crea recién al enviarlo, así en la bandeja queda en el orden real.
 */
class ReplyAfterAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RepliesToConversation, SerializesModels;

    public const POLL_SECONDS = 3;

    public function __construct(
        public int $conversationId,
        public string $text,
        public int $since,
    ) {}

    /** Reintentos por tiempo, no por cantidad: cada espera es un release(). */
    public function retryUntil(): \DateTime
    {
        return now()->addSeconds($this->maxWait() + 120)->toDateTime();
    }

    public function handle(): void
    {
        if ($this->shouldWait()) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        $this->replyTo($this->conversationId, $this->text);
    }

    protected function shouldWait(): bool
    {
        if (time() - $this->since >= $this->maxWait()) {
            return false;
        }

        $conversation = ConversationProxy::modelClass()::find($this->conversationId);

        if (! $conversation || ! $conversation->aiEffective()) {
            return false;
        }

        $agentReplied = MessageProxy::modelClass()::where('conversation_id', $this->conversationId)
            ->where('direction', 'outbound')
            ->where('sender', 'ia')
            ->where('created_at', '>=', date('Y-m-d H:i:s', $this->since))
            ->exists();

        return ! $agentReplied;
    }

    protected function maxWait(): int
    {
        return max(0, (int) config('whatsapp.flows.confirm_wait_seconds', 40));
    }
}
