<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Lead\Services\ProductoDelPedido;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * El producto del pedido se carga solo (13/10): 1 de 312 pedidos lo tenía.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->persona = DB::table('persons')->insertGetId(['name' => 'Producto pedido '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

    $this->lead = DB::table('leads')->insertGetId([
        'title'                  => 'Producto pedido '.uniqid(), 'person_id' => $this->persona, 'user_id' => 1, 'status' => 1,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    $this->stretch = (int) DB::table('products')->where('sku', 'CM_00001')->value('id');
});

function productosDe(int $lead): array
{
    return DB::table('lead_products')->where('lead_id', $lead)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
}

it('una cotización deja sus productos en el pedido', function () {
    $quote = DB::table('quotes')->insertGetId([
        'subject'    => 'Producto pedido', 'user_id' => 1, 'person_id' => $this->persona, 'sub_total' => 0, 'grand_total' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('quote_items')->insert([
        'quote_id' => $quote, 'product_id' => $this->stretch, 'sku' => 'CM_00001', 'name' => 'Stretch Film',
        'quantity' => 4, 'price' => 0, 'total' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('lead_quotes')->insert(['lead_id' => $this->lead, 'quote_id' => $quote]);

    Event::dispatch('quote.update.after', app(QuoteRepository::class)->find($quote));

    expect(productosDe($this->lead))->toBe([$this->stretch])
        ->and((int) DB::table('lead_products')->where('lead_id', $this->lead)->value('quantity'))->toBe(4);
});

it('por SKU, como lo manda el agente o el menú', function () {
    expect(app(ProductoDelPedido::class)->registrarSku($this->lead, 'CM_00001'))->toBeTrue()
        ->and(productosDe($this->lead))->toBe([$this->stretch]);
});

it('no duplica un producto que el pedido ya tiene', function () {
    $servicio = app(ProductoDelPedido::class);

    $servicio->registrar($this->lead, $this->stretch, 10);

    expect($servicio->registrar($this->lead, $this->stretch, 3))->toBeFalse()
        ->and(DB::table('lead_products')->where('lead_id', $this->lead)->count())->toBe(1)
        ->and((int) DB::table('lead_products')->where('lead_id', $this->lead)->value('quantity'))->toBe(10);
});

it('un SKU que no existe no registra nada', function () {
    expect(app(ProductoDelPedido::class)->registrarSku($this->lead, 'NO-EXISTE'))->toBeFalse()
        ->and(productosDe($this->lead))->toBe([]);
});
