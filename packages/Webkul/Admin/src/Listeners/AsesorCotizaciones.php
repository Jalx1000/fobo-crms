<?php

namespace Webkul\Admin\Listeners;

use Webkul\Product\Services\Responsables;

/**
 * Cuando cambia el asesor de un pedido -a mano o desde la API-, sus
 * cotizaciones, su conversación y su contacto pasan al mismo asesor. Así
 * pedidos y cotizaciones cuentan igual en cualquier reporte por asesor, y el
 * chat lo atiende el dueño del pedido.
 */
class AsesorCotizaciones
{
    public function dePedido($lead): void
    {
        if ($lead?->id) {
            Responsables::todoConElPedido((int) $lead->id);
        }
    }
}
