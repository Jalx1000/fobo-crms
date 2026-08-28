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
use Webkul\Whatsapp\Mail\HandoffAsignado;
use Webkul\Whatsapp\Models\HandoffProxy;

/**
 * Avisa por mail al asesor que acaba de quedar a cargo de una conversación.
 *
 * Es la señal de respaldo, no el mecanismo: al cliente no se le manda nada al
 * derivar, y Krayin no tiene notificaciones in-app. La señal primaria es el
 * chip "Sin asesor" del inbox, que se refresca solo. El mail puede rebotar,
 * demorarse o caer en spam; el chip no.
 */
class NotifyHandoff implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $handoffId) {}

    public function handle(): void
    {
        if (! config('whatsapp.handoff.notify', true)) {
            return;
        }

        $handoff = HandoffProxy::modelClass()::with(['conversation.person:id,name', 'asesor:id,name,email'])
            ->find($this->handoffId);

        if (! $handoff || ! $handoff->conversation) {
            return;
        }

        $email = $handoff->asesor?->email;

        if (! $email) {
            // No hay nada que reintentar: que el asesor no tenga mail es un
            // problema de configuración, no una falla transitoria. Tirar una
            // excepción quemaría tres intentos para nada.
            Log::warning('WhatsApp: derivación sin a quién avisar.', [
                'handoff_id' => $this->handoffId,
                'user_id'    => $handoff->user_id,
            ]);

            return;
        }

        try {
            Mail::to($email)->send(new HandoffAsignado($handoff));
        } catch (Throwable $e) {
            Log::error('WhatsApp: no se pudo enviar el aviso de derivación.', [
                'handoff_id' => $this->handoffId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
