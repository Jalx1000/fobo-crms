<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Mail\HandoffRequested;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\CityRouter;

/**
 * Avisa que hay una conversación esperando a una persona.
 *
 * Al asesor de la ciudad si lo hay; si no, a la casilla del equipo, porque una
 * conversación en el pool que nadie mira es igual a una conversación perdida.
 */
class NotifyHandoff implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $conversationId, public ?int $userId) {}

    public function handle(CityRouter $cities): void
    {
        $conversation = ConversationProxy::modelClass()::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $advisor = $this->userId ? UserProxy::modelClass()::find($this->userId) : null;

        $destino = $advisor?->email ?: config('whatsapp.handoff.pool_email');

        if (! $destino) {
            Log::info('WhatsApp: handoff sin destinatario de aviso', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        try {
            Mail::to($destino)->send(new HandoffRequested(
                conversation: $conversation,
                advisorName: $advisor?->name,
                cityName: $cities->cityName($conversation->handoff_city_id),
                inboxUrl: route('admin.whatsapp.index').'?conversation='.$conversation->id,
            ));
        } catch (Throwable $e) {
            // Que no salga el mail no puede deshacer la derivación: la
            // conversación ya está asignada y visible en el inbox.
            Log::warning('WhatsApp: no se pudo avisar del handoff', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
