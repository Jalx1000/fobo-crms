<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Envía el formulario que pidió el agente DESPUÉS de su texto ("¡Hola! 👋 … le
 * envié un formulario").
 *
 * El agente llama a la herramienta del formulario a mitad de su turno y manda
 * el texto al terminar (~2 s después), así que el formulario llegaba primero
 * (conversación 6065, 2026-10-29). Se espera a que haya un texto del agente
 * posterior al formulario y que ya haya salido, con un tope
 * (`whatsapp.flows.send_wait_seconds`) por si el agente no manda texto. Sin
 * agente activo se envía al toque. Mismo esquema que ReplyAfterAgent.
 */
class SendFlowAfterAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const POLL_SECONDS = 1;

    public function __construct(
        public int $messageId,
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

        // El texto ya salió: el envío normal no puede adelantarlo.
        SendMessage::dispatch($this->messageId)->onQueue(config('whatsapp.queue'));
    }

    protected function shouldWait(): bool
    {
        if (time() - $this->since >= $this->maxWait()) {
            return false;
        }

        $message = MessageProxy::modelClass()::find($this->messageId);

        if (! $message || $message->status !== 'queued') {
            return false;
        }

        $conversation = ConversationProxy::modelClass()::find($message->conversation_id);

        if (! $conversation || ! $conversation->aiEffective()) {
            return false;
        }

        $texts = MessageProxy::modelClass()::where('conversation_id', $message->conversation_id)
            ->where('direction', 'outbound')
            ->where('sender', 'ia')
            ->where('type', 'text')
            ->where('id', '>', $message->id)
            ->pluck('status');

        // Sin texto todavía, o el texto sigue en cola: el formulario lo pasaría.
        return $texts->isEmpty() || $texts->contains('queued');
    }

    protected function maxWait(): int
    {
        return max(0, (int) config('whatsapp.flows.send_wait_seconds', 10));
    }
}
