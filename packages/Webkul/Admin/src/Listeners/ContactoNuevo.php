<?php

namespace Webkul\Admin\Listeners;

use Webkul\Admin\Jobs\AvisarContactoNuevo;

/**
 * Alta de un contacto → correos (bienvenida y aviso al equipo), en la cola.
 *
 * Se encola en `whatsapp` porque es la única cola con worker: lo que va a
 * `default` no lo procesa nadie. Así el alta (que puede venir de un webhook)
 * nunca espera al SMTP.
 */
class ContactoNuevo
{
    public function handle($person): void
    {
        if (! $person?->id || ! filter_var(config('mail.contacto_nuevo'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        AvisarContactoNuevo::dispatch((int) $person->id)
            ->onQueue(config('whatsapp.queue'));
    }
}
