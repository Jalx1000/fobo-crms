<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Tablero: las tortas con su tabla (formato de Kolberg) y «no atendidos» =
 * etapa Prospectos.
 */
uses(DatabaseTransactions::class);

function tortaStats(string $tipo, array $extra = []): array
{
    return test()->actingAs(getDefaultAdmin(), 'user')
        ->getJson(route('admin.dashboard.stats', array_merge(['type' => $tipo, 'start' => now()->subDay()->toDateString(), 'end' => now()->addDay()->toDateString()], $extra)))
        ->assertOk()
        ->json('statistics');
}

function tortaPedido(string $etapa, int $productoId, int $cantidad): int
{
    $ciudad = (int) DB::table('lead_pipelines')->where('name', 'like', 'Palpal%')->value('id')
        ?: test()->markTestSkipped('No existe la ciudad Palpalá.');

    $leadId = (int) DB::table('leads')->insertGetId([
        'title' => 'Torta '.uniqid(), 'lead_value' => 0, 'status' => 1,
        'lead_pipeline_id' => $ciudad,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $ciudad)->where('code', $etapa)->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('lead_products')->insert([
        'lead_id' => $leadId, 'product_id' => $productoId, 'quantity' => $cantidad, 'price' => 1, 'amount' => $cantidad,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $leadId;
}

it('la torta de productos trae todos los productos y los totales de la tabla', function () {
    $producto = (int) DB::table('products')->value('id') ?: test()->markTestSkipped('No hay productos.');
    $antes = tortaStats('quantity-products', ['pipeline_id' => 'all']);

    tortaPedido('prospectos', $producto, 3);

    $stats = tortaStats('quantity-products', ['pipeline_id' => 'all']);

    expect($stats)->toHaveKeys(['products', 'total_qty', 'total_leads'])
        ->and($stats['total_qty'])->toBe($antes['total_qty'] + 3)
        ->and($stats['total_leads'])->toBe($antes['total_leads'] + 1)
        ->and($stats['total_qty'])->toBe(collect($stats['products'])->sum('total_qty_ordered'));
});

it('un pedido con dos productos cuenta una sola vez en el total de pedidos', function () {
    $productos = DB::table('products')->limit(2)->pluck('id');
    $productos->count() === 2 || test()->markTestSkipped('Hacen falta dos productos.');

    $antes = tortaStats('quantity-products', ['pipeline_id' => 'all']);

    $lead = tortaPedido('prospectos', $productos[0], 1);
    DB::table('lead_products')->insert([
        'lead_id' => $lead, 'product_id' => $productos[1], 'quantity' => 1, 'price' => 1, 'amount' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(tortaStats('quantity-products', ['pipeline_id' => 'all'])['total_leads'])->toBe($antes['total_leads'] + 1);
});

it('la torta de vendidos solo cuenta pedidos entregados', function () {
    $producto = (int) DB::table('products')->value('id') ?: test()->markTestSkipped('No hay productos.');
    $antes = tortaStats('quantity-products-sold', ['pipeline_id' => 'all']);

    tortaPedido('prospectos', $producto, 5);
    tortaPedido('pedido-entregado', $producto, 2);

    expect(tortaStats('quantity-products-sold', ['pipeline_id' => 'all'])['total_qty'])->toBe($antes['total_qty'] + 2);
});

it('«no atendidos» son los pedidos en Prospectos, en la tarjeta por encargado y en la torta por ciudad', function () {
    $producto = (int) DB::table('products')->value('id') ?: test()->markTestSkipped('No hay productos.');

    $porCiudad = fn () => collect(tortaStats('leads-no-atendidos-por-ciudad', ['pipeline_id' => 'all']))->only(['labels', 'data']);
    $porEncargado = fn () => tortaStats('leads-attention-by-users', ['pipeline_id' => 'all']);

    $ciudadAntes = array_sum($porCiudad()['data'] ?? []);
    $encargadoAntes = array_sum($porEncargado()['not_attended']);

    // A nombre de un distribuidor: los pedidos sin dueño no entran en estas métricas.
    $dueno = (int) DB::table('distribuidor_zonas')->value('user_id') ?: test()->markTestSkipped('No hay distribuidores.');

    $prospecto = tortaPedido('prospectos', $producto, 1);
    $confirmado = tortaPedido('pedidos-confirmado', $producto, 1);
    DB::table('leads')->whereIn('id', [$prospecto, $confirmado])->update(['user_id' => $dueno]);

    expect(array_sum($porCiudad()['data'] ?? []))->toBe($ciudadAntes + 1)
        ->and(array_sum($porEncargado()['not_attended']))->toBe($encargadoAntes + 1);
});
