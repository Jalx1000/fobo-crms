<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\Admin\Listeners\Ciudad;
use Webkul\Admin\Mail\CotizacionNueva;
use Webkul\Admin\Services\DestinatariosAviso;
use Webkul\Quote\Models\QuoteProxy;

/**
 * El correo de una cotización recién creada: al asesor y a la copia fija.
 *
 * Lee la cotización al correr. Avisa UNA vez por cotización aunque el evento
 * llegue dos veces; si el SMTP falla, se libera la marca y el reintento vuelve
 * a probar. El cliente no lo recibe: lleva datos internos.
 */
class AvisarCotizacionNueva implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $quoteId) {}

    public function handle(DestinatariosAviso $destinatarios, Ciudad $ciudades): void
    {
        $quote = QuoteProxy::modelClass()::with(['items', 'person', 'user'])->find($this->quoteId);

        if (! $quote) {
            return;
        }

        $marca = "cotizacion-nueva:avisada:{$quote->id}";

        if (! Cache::add($marca, true, now()->addDays(30))) {
            return;
        }

        try {
            $ciudad = $ciudades->nombreDe('quotes', $quote->ciudad ?? null)
                ?? $ciudades->nombreDe('persons', $quote->person?->ciudad ?? null);

            $equipo = $destinatarios->equipo($quote->user_id, $ciudad);

            foreach ($equipo as $asesor) {
                Mail::to($asesor->email)->send(new CotizacionNueva($quote, $asesor));
            }

            $copias = $destinatarios->copias($equipo, config('mail.cotizacion_nueva_copia'));

            foreach ($copias as $email) {
                Mail::to($email)->send(new CotizacionNueva($quote, null, $equipo->pluck('name')->all()));
            }

            Log::info('Cotización nueva: correos enviados', [
                'quote_id' => $quote->id,
                'equipo'   => $equipo->pluck('id')->all(),
                'copias'   => count($copias),
            ]);
        } catch (Throwable $e) {
            Cache::forget($marca);

            throw $e;
        }
    }
}
