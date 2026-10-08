<?php

namespace Webkul\Admin\Listeners;

use Webkul\Admin\Jobs\AvisarCotizacionNueva;

/**
 * Cotización creada (desde el CRM o desde la API del agente) → correo con el
 * detalle al asesor, en la cola.
 *
 * - Cola `whatsapp`: es la única con worker; lo que va a `default` no sale.
 * - afterCommit: el agente crea la cotización dentro de una transacción; sin
 *   esto el worker podría correr antes del commit y no encontrarla.
 */
class CotizacionNueva
{
    public function handle($quote): void
    {
        if (! $quote?->id || ! filter_var(config('mail.cotizacion_nueva'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        AvisarCotizacionNueva::dispatch((int) $quote->id)
            ->onQueue(config('whatsapp.queue'))
            ->afterCommit();
    }
}
