<?php

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;

/*
 * Dos bugs del formulario de creación de pedidos, encontrados el 2026-09-07 en
 * el access.log de nginx (6 × "POST /admin/leads/create 404") y en laravel.log
 * ("Column 'product_id' cannot be null").
 *
 * Ninguno de los dos dejaba rastro útil: Laravel NO reporta
 * ModelNotFoundException, así que el 404 no aparecía en ningún log de la app.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();

    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

function datosDeLead(array $extra = []): array
{
    return array_merge([
        'title'          => 'Pedido de prueba '.uniqid(),
        'description'    => 'creado por el test',
        'lead_value'     => 100,
        'person'         => ['name' => 'Cliente de prueba '.uniqid()],
        'lead_source_id' => optional(Webkul\Lead\Models\Source::first())->id,
        'lead_type_id'   => optional(Webkul\Lead\Models\Type::first())->id,
        'user_id'        => getDefaultAdmin()->id,
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| Bug 1 — pipeline_id="all" respondía 404 AL GUARDAR
|--------------------------------------------------------------------------
| Desde la lista sin filtrar por ciudad, el botón "Crear" arrastra
| ?pipeline_id=all; la vista lo reenviaba como campo oculto y el controlador
| hacía findOrFail("all").
*/

it('guardar con lead_pipeline_id="all" ya no responde 404', function () {
    $response = test()->post(route('admin.leads.store'), datosDeLead([
        'lead_pipeline_id' => 'all',
    ]));

    expect($response->getStatusCode())->not->toBe(404);
});

it('un pipeline inexistente cae al pipeline por defecto', function () {
    test()->post(route('admin.leads.store'), datosDeLead([
        'title'            => $titulo = 'Pipeline inexistente '.uniqid(),
        'lead_pipeline_id' => 999999,
    ]));

    $lead = Lead::where('title', $titulo)->first();

    expect($lead)->not->toBeNull()
        ->and($lead->lead_pipeline_id)->toBe(Pipeline::where('is_default', 1)->first()->id);
});

it('la vista de creación no reenvía un pipeline_id que no es un id', function () {
    $html = test()->get(route('admin.leads.create', ['pipeline_id' => 'all']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('name="lead_pipeline_id"');
});

it('pero sí lo reenvía cuando es un id de verdad', function () {
    $pipeline = Pipeline::first();

    $html = test()->get(route('admin.leads.create', ['pipeline_id' => $pipeline->id]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('name="lead_pipeline_id"');
});

/*
|--------------------------------------------------------------------------
| Bug 2 — una fila de producto sin producto elegido rompía el insert
|--------------------------------------------------------------------------
| El lookup emite `on-selected` con {} al quitar el producto, y la fila queda
| con product_id nulo. El insert moría con 1048 y el lead NO se creaba.
*/

it('una fila de producto vacía se ignora en vez de romper el guardado', function () {
    $response = test()->post(route('admin.leads.store'), datosDeLead([
        'title'    => $titulo = 'Fila vacía '.uniqid(),
        'products' => [
            'product_0' => ['product_id' => null, 'quantity' => 1, 'price' => 0],
        ],
    ]));

    expect($response->getStatusCode())->not->toBe(500);

    $lead = Lead::where('title', $titulo)->first();

    // Lo que importa: el pedido se creó igual, sin la fila incompleta.
    expect($lead)->not->toBeNull()
        ->and($lead->products()->count())->toBe(0);
});

it('el error ya no habla de un campo oculto que el asesor no puede ver', function () {
    $response = test()->post(route('admin.leads.store'), datosDeLead([
        'products' => [
            'product_0' => ['product_id' => null, 'quantity' => 1, 'price' => 0],
        ],
    ]));

    $response->assertSessionHasNoErrors();
});

it('las filas completas se guardan aunque venga una vacía al lado', function () {
    $producto = Webkul\Product\Models\Product::first();

    if (! $producto) {
        test()->markTestSkipped('No hay productos en la base.');
    }

    test()->post(route('admin.leads.store'), datosDeLead([
        'title'    => $titulo = 'Mixto '.uniqid(),
        'products' => [
            'product_0' => ['product_id' => null, 'quantity' => 1, 'price' => 0],
            'product_1' => [
                'product_id' => $producto->id,
                'name'       => $producto->name,
                'quantity'   => 2,
                'price'      => 50,
            ],
        ],
    ]));

    $lead = Lead::where('title', $titulo)->first();

    expect($lead)->not->toBeNull()
        ->and($lead->products()->count())->toBe(1)
        ->and((float) $lead->products()->first()->amount)->toBe(100.0);
});

it('adjuntar un producto sin elegirlo devuelve 422, no un 500 de MySQL', function () {
    $lead = Lead::first();

    if (! $lead) {
        test()->markTestSkipped('No hay leads en la base.');
    }

    test()->putJson(route('admin.leads.product.add', $lead->id), [
        'product_id' => null,
        'quantity'   => 1,
        'price'      => 10,
    ])->assertStatus(422);
});
