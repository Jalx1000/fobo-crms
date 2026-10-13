<?php

namespace Webkul\Lead\Services;

use Illuminate\Support\Facades\DB;

/**
 * El producto de un pedido, cargado solo.
 *
 * Casi ningún pedido tenía producto (1 de 312 el 13/10): solo se veía en las
 * cotizaciones, y un pedido sin cotización no decía qué se pidió. Ahora se
 * registra cuando se sabe —una cotización, una derivación del agente con su
 * producto, el cliente eligiendo del menú— venga del CRM o del agente.
 *
 * Solo agrega: un producto que el pedido ya tiene no se toca (el asesor pudo
 * haber corregido la cantidad), y no cambia el valor del pedido, que sale de
 * su cotización si la tiene.
 */
class ProductoDelPedido
{
    public function registrar(?int $leadId, ?int $productId, ?float $cantidad = null): bool
    {
        if (! $leadId || ! $productId
            || ! DB::table('leads')->where('id', $leadId)->exists()
            || ! DB::table('products')->where('id', $productId)->exists()) {
            return false;
        }

        if (DB::table('lead_products')->where('lead_id', $leadId)->where('product_id', $productId)->exists()) {
            return false;
        }

        DB::table('lead_products')->insert([
            'lead_id'    => $leadId,
            'product_id' => $productId,
            'quantity'   => (int) ($cantidad ?? 0),
            'price'      => 0,
            'amount'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    public function registrarSku(?int $leadId, ?string $sku, ?float $cantidad = null): bool
    {
        $productId = $sku ? DB::table('products')->where('sku', trim($sku))->value('id') : null;

        return $this->registrar($leadId, $productId ? (int) $productId : null, $cantidad);
    }

    /**
     * Los productos de una cotización, en cada pedido al que está vinculada.
     */
    public function desdeCotizacion($quote): void
    {
        if (! $quote?->id) {
            return;
        }

        $leads = DB::table('lead_quotes')->where('quote_id', $quote->id)->pluck('lead_id');

        $items = DB::table('quote_items')->where('quote_id', $quote->id)->whereNotNull('product_id')->get(['product_id', 'quantity']);

        foreach ($leads as $leadId) {
            foreach ($items as $item) {
                $this->registrar((int) $leadId, (int) $item->product_id, (float) $item->quantity);
            }
        }
    }
}
