<?php

namespace Webkul\Whatsapp\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Cierra una derivación tras `whatsapp.handoff.cierre_horas` sin mensajes.
 *
 * Solo se cerraban a mano y casi nunca se cerraban (12/10: 48 abiertas, 8
 * cerradas); mientras siguen abiertas la IA no le contesta a ese cliente, ni
 * siquiera si vuelve días después. Se agenda al derivar; si al correr hubo
 * mensajes nuevos, se vuelve a agendar contando desde el último.
 */
class CerrarDerivacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $conversationId, public string $requestedAt) {}

    public static function horas(): int
    {
        return (int) config('whatsapp.handoff.cierre_horas', 6);
    }

    public static function agendar(Conversation $conversation, ?Carbon $cuando = null): void
    {
        if (static::horas() <= 0 || ! $conversation->handoff_requested_at) {
            return;
        }

        static::dispatch($conversation->id, $conversation->handoff_requested_at->toIso8601String())
            ->onQueue(config('whatsapp.queue'))
            ->delay($cuando ?? now()->addHours(static::horas()));
    }

    /**
     * Desde cuándo vence: el último mensaje de la conversación, o la derivación
     * si no hubo mensajes después.
     */
    public static function vence(Conversation $conversation): Carbon
    {
        $ultimo = $conversation->messages()->max('created_at');

        $desde = $ultimo && Carbon::parse($ultimo)->gt($conversation->handoff_requested_at)
            ? Carbon::parse($ultimo)
            : $conversation->handoff_requested_at->copy();

        return $desde->addHours(static::horas());
    }

    public function handle(Handoff $handoff): void
    {
        if (static::horas() <= 0) {
            return;
        }

        $conversation = ConversationProxy::modelClass()::find($this->conversationId);

        if (! $conversation
            || ! $conversation->handoffOpen()
            || optional($conversation->handoff_requested_at)->toIso8601String() !== $this->requestedAt) {
            return;
        }

        $vence = static::vence($conversation);

        if (now()->lt($vence)) {
            // Con la cola `sync` reagendar correría de nuevo al instante.
            if (config('queue.default') !== 'sync') {
                static::agendar($conversation, $vence);
            }

            return;
        }

        $handoff->resolve($conversation, null, true, 'automáticamente tras '.static::horas().' h sin mensajes');
    }
}
