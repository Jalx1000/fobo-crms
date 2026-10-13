<?php

namespace Webkul\Admin\Listeners;

use Webkul\Lead\Services\ProductoDelPedido;

/**
 * Lo que trae una cotización queda también como producto de su pedido.
 */
class ProductoPedido
{
    public function __construct(protected ProductoDelPedido $productos) {}

    public function deCotizacion($quote): void
    {
        try {
            $this->productos->desdeCotizacion($quote);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
