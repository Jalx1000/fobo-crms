<?php

namespace Webkul\Inmobiliaria\Listeners;

/**
 * Moneda y valor del cierre de un lead.
 *
 * Dos cosas que el core no hace y esta plaza necesita:
 *
 *  1. Elegir la moneda. Krayin asume una sola por instalación y acá se cierra
 *     tanto en dólares como en bolivianos. Como no convertimos en ningún punto,
 *     una cifra sin moneda no se puede interpretar después.
 *
 *  2. Guardar el valor. `LeadController::updateStage()` recoge `lead_value` en
 *     el modal pero no lo incluye en el payload que persiste: solo guarda la
 *     etapa, `closed_at` y `lost_reason`. O sea que el importe que el asesor
 *     escribía al ganar se perdía siempre. Se completa acá, enganchado al evento
 *     que ese mismo método dispara, para no editar el controlador del core.
 */
class CierreEnMoneda
{
    /**
     * Persiste importe y moneda cuando el modal los mandó.
     */
    public function guardar($lead): void
    {
        // `lead.update.after` también dispara en ediciones que no vienen del
        // modal de cierre; sin este corte, cualquier guardado borraría el valor.
        if (! request()->filled('lead_value') && ! request()->filled('lead_value_moneda')) {
            return;
        }

        $cambios = [];

        if (request()->filled('lead_value')) {
            $cambios['lead_value'] = (float) request()->input('lead_value');
        }

        if (request()->filled('lead_value_moneda')) {
            $moneda = strtoupper((string) request()->input('lead_value_moneda'));

            if (in_array($moneda, (array) config('inmobiliaria.inmuebles.monedas'), true)) {
                $cambios['lead_value_moneda'] = $moneda;
            }
        }

        if ($cambios) {
            $lead->forceFill($cambios)->save();
        }
    }
}
