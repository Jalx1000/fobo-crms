<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\FiltroDistribuidor;
use Webkul\Lead\Models\LeadProxy;

/**
 * Filtro «Distribuidor» del tablero: llega a todas las tarjetas y solo dentro
 * del tablero.
 */
uses(DatabaseTransactions::class);

afterEach(fn () => FiltroDistribuidor::desactivar());

function filtroDistribuidores(): array
{
    $ids = DB::table('distribuidor_zonas')->join('users', 'users.id', '=', 'distribuidor_zonas.user_id')
        ->where('users.status', 1)->distinct()->limit(2)->pluck('users.id')->map(fn ($id) => (int) $id)->all();

    count($ids) === 2 || test()->markTestSkipped('Hacen falta dos distribuidores activos.');

    return $ids;
}

function filtroPedido(int $dueno, int $cantidad): void
{
    $ciudad = (int) DB::table('distribuidor_zonas')->where('user_id', $dueno)->value('lead_pipeline_id');
    $producto = (int) DB::table('products')->value('id') ?: test()->markTestSkipped('No hay productos.');

    $lead = DB::table('leads')->insertGetId([
        'title' => 'Filtro '.uniqid(), 'lead_value' => 0, 'status' => 1, 'user_id' => $dueno,
        'lead_pipeline_id' => $ciudad,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $ciudad)->orderBy('sort_order')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('lead_products')->insert([
        'lead_id' => $lead, 'product_id' => $producto, 'quantity' => $cantidad, 'price' => 1, 'amount' => $cantidad,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function filtroStats(string $tipo, $distribuidor): array
{
    return test()->actingAs(getDefaultAdmin(), 'user')
        ->getJson(route('admin.dashboard.stats', [
            'type' => $tipo, 'pipeline_id' => 'all', 'distribuidor_id' => $distribuidor,
            'start' => now()->subDay()->toDateString(), 'end' => now()->addDay()->toDateString(),
        ]))
        ->assertOk()
        ->json('statistics');
}

it('las tortas de productos cuentan solo los pedidos del distribuidor elegido', function () {
    [$a, $b] = filtroDistribuidores();
    $antesA = filtroStats('quantity-products', $a)['total_qty'];

    filtroPedido($a, 4);
    filtroPedido($b, 7);

    expect(filtroStats('quantity-products', $a)['total_qty'])->toBe($antesA + 4);
});

it('la tarjeta por encargado muestra solo al distribuidor elegido', function () {
    [$a, $b] = filtroDistribuidores();
    filtroPedido($a, 1);
    filtroPedido($b, 1);

    $stats = filtroStats('leads-attention-by-users', $a);

    expect($stats['labels'])->toBe([(string) DB::table('users')->where('id', $a)->value('name')]);
});

it('«Todos los distribuidores» no filtra', function () {
    [$a, $b] = filtroDistribuidores();

    $todos = filtroStats('quantity-products', '')['total_qty'];
    $soloA = filtroStats('quantity-products', $a)['total_qty'];
    $soloB = filtroStats('quantity-products', $b)['total_qty'];

    expect($todos)->toBeGreaterThanOrEqual($soloA + $soloB);
});

it('después del tablero, los pedidos ya no quedan filtrados', function () {
    [$a] = filtroDistribuidores();

    filtroStats('quantity-products', $a);

    expect(LeadProxy::modelClass()::count())->toBe(DB::table('leads')->count());
});

it('un distribuidor no puede usar el filtro', function () {
    [$a, $b] = filtroDistribuidores();

    test()->actingAs(\Webkul\User\Models\User::find($a), 'user');

    expect(FiltroDistribuidor::elegido(request()->merge(['distribuidor_id' => $b])))->toBeNull()
        ->and(FiltroDistribuidor::opciones())->toBe([]);
});
