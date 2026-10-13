<?php

namespace Webkul\Admin\Listeners;

use Webkul\Product\Services\Responsables;

/**
 * Cuando cambia el asesor de un pedido —a mano o desde la API—, sus
 * cotizaciones pasan al mismo asesor. Así pedidos y cotizaciones cuentan
 * igual en cualquier reporte por asesor.
 */
class AsesorCotizaciones
{
    public function dePedido($lead): void
    {
        if ($lead?->id) {
            Responsables::cotizacionesConElPedido((int) $lead->id);
        }
    }
}
