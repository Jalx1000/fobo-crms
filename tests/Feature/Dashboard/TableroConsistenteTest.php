<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * Tablero consistente (spec docs/specs/tablero-consistente.md): cada total
 * del tablero es igual a la suma de su desglose.
 *
 * Helpers en helpers.php. Todo en enero de 2020, para no mezclarse con los
 * datos reales.
 */
require_once __DIR__.'/helpers.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(User::findOrFail(1), 'user');

    $this->asesor = User::whereHas('role', fn ($q) => $q->where('name', 'Ejecutivo Comercial'))
        ->where('status', 1)->firstOrFail();

    $this->sinCiudad = (int) DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');
    $this->fecha = '2020-01-15 10:00:00';

    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Tablero '.uniqid(), 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);
});

function totalSolicitados(): float
{
    return (float) tarjeta('over-all')['total_services']['current'];
}

it('productos más solicitados suma las unidades cotizadas de cada producto', function () {
    // 4 cotizaciones de Magia Verde que suman 29 bolsas (ejemplo del usuario).
    foreach ([10, 9, 6, 4] as $bolsas) {
        tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00002', $bolsas);
    }

    $magia = collect(tarjeta('quantity-products'))->firstWhere('name', 'Bolsas Magia Verde');

    expect((float) $magia['total_qty_ordered'])->toBe(29.0)
        ->and($magia['solicitudes'])->toBe(4);
});

it('total productos solicitados es la suma de la torta de solicitados', function () {
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00002', 29);
    tarjetaCotizacion($this, tarjetaPedido($this, 7, 'cotizacion-enviada'), 'CM_00001', 12);
    // Un producto cargado en el pedido, sin cotización: no es un solicitado.
    $sinCotizar = tarjetaPedido($this, 1, 'solicitud-recibida');
    DB::table('lead_products')->insert([
        'lead_id'    => $sinCotizar, 'product_id' => DB::table('products')->where('sku', 'CM_00002')->value('id'),
        'quantity'   => 500, 'price' => 0, 'amount' => 0, 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);

    $torta = collect(tarjeta('quantity-products'))->sum(fn ($p) => (float) $p['total_qty_ordered']);

    expect($torta)->toBe(41.0)
        ->and(totalSolicitados())->toBe(41.0);
});

it('solicitados: KPI y torta dejan afuera Sin ciudad por igual (P2 pendiente)', function () {
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00001', 5);
    tarjetaCotizacion($this, tarjetaPedido($this, $this->sinCiudad, 'cotizacion-enviada'), 'CM_00001', 100);

    $torta = collect(tarjeta('quantity-products'))->sum(fn ($p) => (float) $p['total_qty_ordered']);

    expect($torta)->toBe(5.0)
        ->and(totalSolicitados())->toBe(5.0);
});

it('solicitados con filtro de ciudad: la torta y el KPI son los de esa ciudad', function () {
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00001', 5);
    tarjetaCotizacion($this, tarjetaPedido($this, 7, 'cotizacion-enviada'), 'CM_00001', 8);

    $params = ['start' => '2020-01-01', 'end' => '2020-01-31', 'pipeline_id' => 7];
    $get = fn ($tipo) => test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => $tipo] + $params))->assertOk()->json('statistics');

    expect(collect($get('quantity-products'))->sum(fn ($p) => (float) $p['total_qty_ordered']))->toBe(8.0)
        ->and((float) $get('over-all')['total_services']['current'])->toBe(8.0);
});

function totalVendidos(): float
{
    return (float) tarjeta('over-all')['total_products_sold']['current'];
}

it('productos vendidos salen de las cotizaciones de los pedidos entregados', function () {
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'entregados'), 'CM_00001', 10);
    // Cotizado pero no entregado: es solicitado, no vendido.
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00001', 7);
    // Entregado sin cotización: suma 0 (D6), aunque tenga producto cargado en el pedido.
    $sinCotizar = tarjetaPedido($this, 1, 'entregados');
    DB::table('lead_products')->insert([
        'lead_id'    => $sinCotizar, 'product_id' => DB::table('products')->where('sku', 'CM_00002')->value('id'),
        'quantity'   => 50, 'price' => 0, 'amount' => 0, 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);

    $torta = collect(tarjeta('quantity-products-sold'));

    expect($torta->pluck('name')->all())->toBe(['Stretch Film'])
        ->and((float) $torta->first()['total_qty_ordered'])->toBe(10.0)
        ->and(totalVendidos())->toBe(10.0);
});

it('total productos vendidos es la suma de la torta de más vendidos', function () {
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'entregados'), 'CM_00001', 10);
    tarjetaCotizacion($this, tarjetaPedido($this, 7, 'entregados'), 'CM_00002', 30);
    // Hoy vendidos cuenta «Sin ciudad» (P2 pendiente: cada tarjeta mantiene su regla).
    tarjetaCotizacion($this, tarjetaPedido($this, $this->sinCiudad, 'entregados'), 'CM_00002', 2);

    $torta = collect(tarjeta('quantity-products-sold'))->sum(fn ($p) => (float) $p['total_qty_ordered']);

    expect($torta)->toBe(42.0)
        ->and(totalVendidos())->toBe(42.0);
});

it('evolución: productos vendidos y valor de ventas salen de las cotizaciones de entregados', function () {
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'entregados'), 'CM_00001', 10, 1500);
    tarjetaCotizacion($this, tarjetaPedido($this, $this->sinCiudad, 'entregados'), 'CM_00002', 4, 200);
    // No entregado: no es venta.
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00001', 99, 9999);
    // Entregado con valor en el pedido pero sin cotización: no suma (D6).
    $sinCotizar = tarjetaPedido($this, 1, 'entregados');
    DB::table('leads')->where('id', $sinCotizar)->update(['lead_value' => 700]);

    $evo = tarjeta('evolution');

    expect((float) $evo['productos-vendidos']['current_total'])->toBe(14.0)
        ->and((float) $evo['productos-vendidos']['current_total'])->toBe(totalVendidos())
        ->and((float) $evo['valor-ventas']['current_total'])->toBe(1700.0);
});

it('avisa los pedidos entregados sin cotización, con su enlace', function () {
    $sinCotizar = tarjetaPedido($this, 1, 'entregados');
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'entregados'), 'CM_00001', 3);
    // Sin cotización pero no entregado: no es un aviso de ventas.
    tarjetaPedido($this, 1, 'cotizacion-enviada');

    $aviso = tarjeta('entregados-sin-cotizacion');

    expect($aviso['total'])->toBe(1)
        ->and($aviso['pedidos'][0]['id'])->toBe($sinCotizar)
        ->and($aviso['pedidos'][0]['url'])->toBe(route('admin.leads.view', $sinCotizar))
        ->and($aviso['pedidos'][0]['asesor'])->toBe($this->asesor->name);

    // Al vincularle una cotización, deja de aparecer.
    tarjetaCotizacion($this, $sinCotizar, 'CM_00001', 1);

    expect(tarjeta('entregados-sin-cotizacion')['total'])->toBe(0);
});

it('el aviso de entregados sin cotización respeta el filtro de ciudad', function () {
    tarjetaPedido($this, 1, 'entregados');
    tarjetaPedido($this, 7, 'entregados');

    $aviso = test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => 'entregados-sin-cotizacion', 'start' => '2020-01-01', 'end' => '2020-01-31', 'pipeline_id' => 7]))
        ->assertOk()->json('statistics');

    expect($aviso['total'])->toBe(1);
});

it('el tablero muestra el aviso de entregados sin cotización bajo los KPI y en Más vendidos', function () {
    $html = test()->get(route('admin.dashboard.index'))->assertOk()->getContent();

    expect(substr_count($html, '<v-dashboard-entregados-sin-cotizacion>'))->toBe(2)
        ->and(substr_count($html, 'id="v-dashboard-entregados-sin-cotizacion-template"'))->toBe(1);
});

it('el valor de pedidos ganados (Excel) es el mismo valor de ventas del tablero', function () {
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'entregados'), 'CM_00001', 10, 1500);
    $sinCotizar = tarjetaPedido($this, 1, 'entregados');
    DB::table('leads')->where('id', $sinCotizar)->update(['lead_value' => 700]);

    expect((float) tarjeta('revenue-stats')['total_won_revenue']['current'])->toBe(1500.0)
        ->and((float) tarjeta('evolution')['valor-ventas']['current_total'])->toBe(1500.0);
});
