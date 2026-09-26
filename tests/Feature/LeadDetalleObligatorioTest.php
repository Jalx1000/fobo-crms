<?php

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Product as LeadProduct;

/*
 * Que el detalle del pedido no se pierda.
 *
 * Medido sobre los 662 pedidos creados desde el 01/08/2026:
 *
 *   Confirmados o entregados sin una sola línea de producto:  27 de 182 (15%)
 *   lead_value distinto de la suma de los productos:          33 leads
 *   Filas de producto con amount = 0:                         20 (15 en septiembre)
 *
 * Los tres duelen en el mismo lugar: el ranking de productos filtra con
 * HAVING SUM(amount) > 0, así que una venta sin detalle —o con amount en 0—
 * no aparece en ningún reporte. No es un dato feo: es plata invisible.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();

    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

function unProductoDelCatalogo(): object
{
    return DB::table('products')->orderBy('id')->first();
}

function pedidoParaLaApi(array $extra = []): array
{
    return array_merge([
        'title'          => 'Pedido test '.uniqid(),
        'person'         => ['name' => 'Cliente test '.uniqid()],
        'lead_source_id' => optional(Webkul\Lead\Models\Source::first())->id,
        'lead_type_id'   => optional(Webkul\Lead\Models\Type::first())->id,
        'user_id'        => getDefaultAdmin()->id,
        // Obligatorio: es un atributo required, y además el LeadController del
        // rest-api lo lee con $data['lead_pipeline_stage_id'] sin isset, así que
        // un payload sin la clave revienta con "Undefined array key" antes de
        // llegar a validar nada.
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->value('id'),
    ], $extra);
}

// ---------------------------------------------------------------------------
// amount = precio × cantidad, venga por donde venga
// ---------------------------------------------------------------------------

it('calcula el amount aunque el que graba se olvide de mandarlo', function () {
    $lead = Lead::first();

    $fila = LeadProduct::create([
        'lead_id'    => $lead->id,
        'product_id' => unProductoDelCatalogo()->id,
        'quantity'   => 3,
        'price'      => 75,
        // amount NO se manda: es exactamente el caso de p#1390 (lead #1773),
        // que quedó con cantidad 3, precio 75 y amount 0.
    ]);

    expect((float) $fila->fresh()->amount)->toBe(225.0);
});

it('corrige el amount si le mandan uno que no cierra', function () {
    $fila = LeadProduct::create([
        'lead_id'    => Lead::first()->id,
        'product_id' => unProductoDelCatalogo()->id,
        'quantity'   => 2,
        'price'      => 100,
        'amount'     => 999, // inconsistente a propósito
    ]);

    expect((float) $fila->fresh()->amount)->toBe(200.0);
});

it('recalcula el amount al editar la cantidad', function () {
    $fila = LeadProduct::create([
        'lead_id'    => Lead::first()->id,
        'product_id' => unProductoDelCatalogo()->id,
        'quantity'   => 1,
        'price'      => 135,
    ]);

    $fila->update(['quantity' => 4]);

    expect((float) $fila->fresh()->amount)->toBe(540.0);
});

// ---------------------------------------------------------------------------
// La API rechaza un pedido con monto y sin detalle
// ---------------------------------------------------------------------------

it('rechaza con 422 un pedido que trae lead_value y ningun producto', function () {
    $r = test()->postJson('/api/v1/leads', pedidoParaLaApi(['lead_value' => 450]));

    $r->assertStatus(422);

    expect($r->json('errors.lead_value.0'))->toContain('products[]');
});

it('tambien lo rechaza si los productos vienen sin product_id', function () {
    // El lookup del formulario emite un objeto vacío al limpiarse; el agente
    // puede mandar la misma basura. Una fila sin producto no es un producto.
    $r = test()->postJson('/api/v1/leads', pedidoParaLaApi([
        'lead_value' => 450,
        'products'   => ['product_0' => ['product_id' => null, 'quantity' => 2]],
    ]));

    $r->assertStatus(422);
});

it('acepta el pedido cuando trae el detalle completo', function () {
    $producto = unProductoDelCatalogo();

    $r = test()->postJson('/api/v1/leads', pedidoParaLaApi([
        'lead_value' => 450,
        'products'   => ['product_0' => [
            'product_id' => $producto->id,
            'name'       => $producto->name,
            'quantity'   => 2,
            'price'      => 225,
        ]],
    ]));

    $r->assertSuccessful();

    $lead = Lead::find($r->json('data.data.id') ?? $r->json('data.id'));

    expect($lead->products)->toHaveCount(1)
        ->and((float) $lead->products->first()->amount)->toBe(450.0);
});

it('deja pasar un pedido sin monto y sin productos: es un contacto, no una venta', function () {
    // Es el lead que abre LeadOpener cuando alguien escribe por primera vez.
    // 341 de los 662 están así y está bien que estén así.
    test()->postJson('/api/v1/leads', pedidoParaLaApi())->assertSuccessful();
});

it('no le impone la regla al formulario del admin', function () {
    // Ahí un supervisor puede fijar un valor manual sin productos.
    $r = test()->post(route('admin.leads.store'), pedidoParaLaApi(['lead_value' => 450]));

    expect($r->status())->not->toBe(422);
});

// ---------------------------------------------------------------------------
// lead_value sigue a los productos aunque se agreguen desde la vista del pedido
// ---------------------------------------------------------------------------

it('actualiza el lead_value al agregar un producto desde la vista', function () {
    $lead = Lead::create([
        'title'                  => 'Pedido sin valor '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->value('id'),
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->value('id'),
        'user_id'                => getDefaultAdmin()->id,
    ]);

    test()->putJson(route('admin.leads.product.add', $lead->id), [
        'product_id' => unProductoDelCatalogo()->id,
        'quantity'   => 2,
        'price'      => 142.50,
    ])->assertSuccessful();

    // Sin esto el pedido quedaba con productos y lead_value 0 — el caso #1764.
    expect((float) $lead->fresh()->lead_value)->toBe(285.0);
});

it('baja el lead_value al quitar el producto', function () {
    $lead = Lead::create([
        'title'                  => 'Pedido a vaciar '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->value('id'),
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->value('id'),
        'user_id'                => getDefaultAdmin()->id,
    ]);

    $producto = unProductoDelCatalogo();

    test()->putJson(route('admin.leads.product.add', $lead->id), [
        'product_id' => $producto->id,
        'quantity'   => 2,
        'price'      => 142.50,
    ]);

    test()->deleteJson(route('admin.leads.product.remove', $lead->id), [
        'product_id' => $producto->id,
    ]);

    expect((float) $lead->fresh()->lead_value)->toBe(0.0);
});

it('respeta el valor congelado a mano por un supervisor', function () {
    $lead = Lead::create([
        'title'                  => 'Pedido con valor manual '.uniqid(),
        'status'                 => 1,
        'lead_value'             => 1000,
        'lead_value_is_manual'   => true,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->value('id'),
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->value('id'),
        'user_id'                => getDefaultAdmin()->id,
    ]);

    test()->putJson(route('admin.leads.product.add', $lead->id), [
        'product_id' => unProductoDelCatalogo()->id,
        'quantity'   => 1,
        'price'      => 135,
    ]);

    expect((float) $lead->fresh()->lead_value)->toBe(1000.0);
});

// ---------------------------------------------------------------------------
// PUT parcial: el agente va a escribir el pedido en vivo
// ---------------------------------------------------------------------------

function pedidoConUnProducto(): Webkul\Lead\Models\Lead
{
    $lead = Lead::create([
        'title'                  => 'Pedido en vivo '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->value('id'),
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->value('id'),
        'user_id'                => getDefaultAdmin()->id,
    ]);

    LeadProduct::create([
        'lead_id'  => $lead->id, 'product_id' => unProductoDelCatalogo()->id,
        'quantity' => 2, 'price' => 135,
    ]);

    return $lead;
}

it('un PUT sin la clave products NO borra los productos', function () {
    $lead = pedidoConUnProducto();

    /*
     * El agente pasa a registrar el pedido en vivo: un PUT por cada dato que le
     * da el cliente (ciudad, nombre, edad). Ninguno de esos lleva products.
     * Antes, cada uno vaciaba el pedido que ya estaba cargado.
     */
    test()->putJson('/api/v1/leads/'.$lead->id, pedidoParaLaApi([
        'id'    => $lead->id,
        'title' => 'Pedido en vivo actualizado',
    ]))->assertSuccessful();

    expect($lead->fresh()->products)->toHaveCount(1);
});

it('un PUT con products vacío sí los borra: es la lista completa', function () {
    $lead = pedidoConUnProducto();

    test()->putJson('/api/v1/leads/'.$lead->id, pedidoParaLaApi([
        'id' => $lead->id, 'products' => [],
    ]))->assertSuccessful();

    expect($lead->fresh()->products)->toHaveCount(0);
});

it('un PUT con la lista completa conserva lo anterior y suma lo nuevo', function () {
    $lead = pedidoConUnProducto();

    $viejo = $lead->products->first();
    $otro = DB::table('products')->where('id', '!=', $viejo->product_id)->first();

    test()->putJson('/api/v1/leads/'.$lead->id, pedidoParaLaApi([
        'id'       => $lead->id,
        'products' => [
            $viejo->id    => ['product_id' => $viejo->product_id, 'name' => $viejo->name, 'quantity' => 2, 'price' => 135],
            'product_new' => ['product_id' => $otro->id, 'name' => $otro->name, 'quantity' => 1, 'price' => 225],
        ],
    ]))->assertSuccessful();

    expect($lead->fresh()->products)->toHaveCount(2)
        ->and((float) $lead->fresh()->lead_value)->toBe(495.0);
});

it('el formulario del admin sigue borrando la última fila al quitarla', function () {
    $lead = pedidoConUnProducto();

    // La vista no manda `products` cuando no queda ninguna fila.
    test()->put(route('admin.leads.update', $lead->id), [
        'id'                     => $lead->id,
        'title'                  => $lead->title,
        'lead_pipeline_stage_id' => $lead->lead_pipeline_stage_id,
        'user_id'                => getDefaultAdmin()->id,
    ]);

    expect($lead->fresh()->products)->toHaveCount(0);
});
