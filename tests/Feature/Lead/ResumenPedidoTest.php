<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Lead\Services\ResumenPedido;
use Webkul\Product\Models\Product;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * El detalle del pedido: tipo de producto, cantidad y valor.
 *
 * Los tres se calculan de lo que el pedido ya tiene, así que no pueden quedar
 * desactualizados. Manda la última cotización; sin cotizaciones, los productos
 * cargados en el pedido.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Resumen '.uniqid(), 'emails' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->lead = DB::table('leads')->insertGetId([
        'title'                  => 'Resumen '.uniqid(),
        'person_id'              => $this->persona,
        'user_id'                => 1,
        'lead_value'             => 0,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->value('id'),
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
});

function agregarProducto(int $leadId, string $sku, float $cantidad, float $precio): void
{
    $p = Product::where('sku', $sku)->first();

    DB::table('lead_products')->insert([
        'lead_id'    => $leadId,
        'product_id' => $p->id,
        'quantity'   => $cantidad,
        'price'      => $precio,
        'amount'     => $cantidad * $precio,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cotizacionDelPedido(int $leadId, int $personId, array $items, float $total)
{
    $quote = app(QuoteRepository::class)->create([
        'entity_type' => 'quotes',
        'subject'     => 'Resumen '.uniqid(),
        'person_id'   => $personId,
        'user_id'     => 1,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => $total,
        'grand_total' => $total,
        'items'       => $items,
    ]);

    $quote->leads()->syncWithoutDetaching([$leadId]);

    Event::dispatch('quote.create.after', $quote);

    return $quote;
}

it('sin productos ni cotizaciones el pedido no vale nada', function () {
    $resumen = app(ResumenPedido::class)->de($this->lead);

    expect($resumen['origen'])->toBe('vacio')
        ->and($resumen['valor'])->toBe(0.0)
        ->and($resumen['productos'])->toBe([]);
});

it('con productos cargados suma su cantidad y su importe', function () {
    agregarProducto($this->lead, 'CM_00003', 5, 20);    // Hules
    agregarProducto($this->lead, 'CM_00004', 30, 2);    // Tapas

    $resumen = app(ResumenPedido::class)->de($this->lead);

    expect($resumen['origen'])->toBe('productos')
        ->and($resumen['cantidad'])->toBe(35.0)
        ->and($resumen['valor'])->toBe(160.0);

    // Una fila por producto, con su propia cantidad e importe: «Hules · Tapas»
    // con las cantidades sumadas no dice cuánto se pidió de cada uno.
    expect(collect($resumen['lineas'])->pluck('cantidad', 'producto')->all())
        ->toBe(['Hules' => 5.0, 'Tapas' => 30.0]);

    expect(collect($resumen['lineas'])->pluck('importe', 'producto')->all())
        ->toBe(['Hules' => 100.0, 'Tapas' => 60.0]);
});

it('la cotización manda sobre los productos del pedido', function () {
    agregarProducto($this->lead, 'CM_00003', 5, 20);

    $quote = cotizacionDelPedido($this->lead, $this->persona, [[
        'product_id' => Product::where('sku', 'CM_00003')->value('id'),
        'sku' => 'CM_00003', 'name' => 'Hules', 'quantity' => 8, 'price' => 25, 'total' => 200,
    ]], 200);

    $resumen = app(ResumenPedido::class)->de($this->lead);

    expect($resumen['origen'])->toBe('cotizacion')
        ->and($resumen['cantidad'])->toBe(8.0)
        ->and($resumen['valor'])->toBe(200.0);

    DB::table('quote_items')->where('quote_id', $quote->id)->delete();
});

it('con varias cotizaciones vale la última, no la suma', function () {
    /*
     * Recotizar es lo normal: se baja el precio o cambia la cantidad y se emite
     * otra. Sumarlas daría un pedido que vale el doble de lo que se va a cobrar.
     */
    $id = Product::where('sku', 'CM_00003')->value('id');

    $vieja = cotizacionDelPedido($this->lead, $this->persona, [[
        'product_id' => $id, 'sku' => 'CM_00003', 'name' => 'Hules', 'quantity' => 8, 'price' => 25, 'total' => 200,
    ]], 200);

    $nueva = cotizacionDelPedido($this->lead, $this->persona, [[
        'product_id' => $id, 'sku' => 'CM_00003', 'name' => 'Hules', 'quantity' => 8, 'price' => 20, 'total' => 160,
    ]], 160);

    expect(app(ResumenPedido::class)->de($this->lead)['valor'])->toBe(160.0);

    DB::table('quote_items')->whereIn('quote_id', [$vieja->id, $nueva->id])->delete();
});

it('el valor del pedido se escribe solo al crear la cotización', function () {
    expect((float) DB::table('leads')->where('id', $this->lead)->value('lead_value'))->toBe(0.0);

    $quote = cotizacionDelPedido($this->lead, $this->persona, [[
        'product_id' => Product::where('sku', 'CM_00004')->value('id'),
        'sku' => 'CM_00004', 'name' => 'Tapas', 'quantity' => 100, 'price' => 3, 'total' => 300,
    ]], 300);

    expect((float) DB::table('leads')->where('id', $this->lead)->value('lead_value'))->toBe(300.0);

    DB::table('quote_items')->where('quote_id', $quote->id)->delete();
});

it('el detalle se ve en la ficha del pedido y la pestaña que abre es WhatsApp', function () {
    test()->actingAs(getDefaultAdmin());

    agregarProducto($this->lead, 'CM_00004', 100, 3);

    $html = test()->get(route('admin.leads.view', $this->lead))->assertOk()->getContent();

    agregarProducto($this->lead, 'CM_00003', 4, 25);

    $html = test()->get(route('admin.leads.view', $this->lead))->assertOk()->getContent();

    expect($html)->toContain('Detalle del pedido')
        ->and($html)->toContain('active-type="whatsapp"');

    // El detalle va DEBAJO de «Sobre el Pedido».
    expect(strpos($html, 'Sobre el Pedido'))->toBeLessThan(strpos($html, 'Detalle del pedido'));

    // Una fila por producto, más el total.
    expect($html)->toContain('Bs 300,00')      // Tapas
        ->and($html)->toContain('Bs 100,00')   // Hules
        ->and($html)->toContain('Bs 400,00');  // valor del pedido
});
