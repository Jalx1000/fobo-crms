<?php

namespace Webkul\Admin\Listeners;

use Webkul\Lead\Services\ResumenPedido;

/**
 * Mantiene `leads.lead_value` al día: es el número del tablero y del embudo.
 *
 * Hasta ahora nadie lo escribía y todos los pedidos valían 0, aunque tuvieran
 * productos cargados y cotizaciones emitidas. Se recalcula cuando cambia
 * cualquiera de las dos cosas de las que depende (ver [[ResumenPedido]]).
 */
class ValorPedido
{
    public function __construct(protected ResumenPedido $resumen) {}

    /**
     * Cambió la oportunidad: sus productos pudieron cambiar con ella.
     */
    public function deOportunidad($lead): void
    {
        $this->resumen->actualizarValor($lead);
    }

    /**
     * Cambió una cotización: se recalculan los pedidos a los que está
     * vinculada. Suele ser uno solo.
     */
    public function deCotizacion($quote): void
    {
        if (! $quote) {
            return;
        }

        foreach ($quote->leads as $lead) {
            $this->resumen->actualizarValor($lead);
        }
    }
}
