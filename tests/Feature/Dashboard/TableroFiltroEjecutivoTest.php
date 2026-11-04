<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * Tablero: selector «Ejecutivo comercial» al lado de la ciudad (16/10/2026,
 * spec docs/specs/pedidos-filtro-ejecutivo.md). Solo Administrador y
 * Supervisores; el administrador que elige a Bulmaro ve exactamente lo que
 * ve Bulmaro, en todas las tarjetas y en el Excel. Todo en enero de 2020.
 */
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();

    $this->fecha = '2020-01-15 10:00:00';
    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Filtro tablero '.uniqid(), 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);

    // Un pedido cotizado de cada uno, más uno sin atender de Eduardo.
    $this->asesor = $this->bulmaro;
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00001', 5);
    $this->asesor = $this->eduardo;
    tarjetaCotizacion($this, tarjetaPedido($this, 7, 'cotizacion-enviada'), 'CM_00002', 8);
    tarjetaPedido($this, 7, 'no-atendidos');
});

/**
 * Todas las tarjetas del tablero, como $usuario y con $extra en la URL.
 */
function todasLasTarjetas(User $usuario, array $extra = []): array
{
    $tipos = array_keys((new ReflectionClass(\Webkul\Admin\Http\Controllers\DashboardController::class))
        ->getDefaultProperties()['typeFunctions']);

    test()->actingAs($usuario, 'user');

    return collect($tipos)->mapWithKeys(fn ($tipo) => [$tipo => test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => $tipo, 'start' => '2020-01-01', 'end' => '2020-01-31'] + $extra))
        ->assertOk()
        ->json('statistics')])->all();
}

it('el administrador que elige a un ejecutivo ve en cada tarjeta lo mismo que ese ejecutivo', function () {
    $this->freezeTime(); // «Tiempo en responder» mide contra ahora.

    $comoBulmaro = todasLasTarjetas($this->bulmaro);
    $filtrado = todasLasTarjetas(getDefaultAdmin(), ['ejecutivo' => $this->bulmaro->id]);

    $distintas = collect($comoBulmaro)->filter(fn ($datos, $tipo) => $datos != $filtrado[$tipo])->keys()->all();

    expect($distintas)->toBe([]);
});

it('sin elegir a nadie el administrador sigue viendo el total', function () {
    $s = todasLasTarjetas(getDefaultAdmin())['leads-attention-by-users'];

    expect($s['labels'])->toContain('Bulmaro Vaca', 'Eduardo Lujan');
});

it('un supervisor también filtra por ejecutivo', function () {
    $s = todasLasTarjetas($this->mariano, ['ejecutivo' => $this->eduardo->id])['leads-attention-by-users'];

    expect($s['labels'])->toBe(['Eduardo Lujan'])
        ->and($s['not_attended'])->toBe([1]);
});

it('un ejecutivo que pide a otro en la URL sigue viendo solo lo suyo', function () {
    $this->freezeTime();

    expect(todasLasTarjetas($this->bulmaro, ['ejecutivo' => $this->eduardo->id]))
        ->toEqual(todasLasTarjetas($this->bulmaro));
});

it('el selector está al lado de la ciudad solo para Administrador y Supervisores', function () {
    $html = fn (User $u) => test()->actingAs($u, 'user')->get(route('admin.dashboard.index'))->assertOk()->getContent();

    expect($html(getDefaultAdmin()))->toContain('aria-label="Ejecutivo comercial"')->toContain('Bulmaro Vaca')
        ->and($html($this->mariano))->toContain('aria-label="Ejecutivo comercial"')
        ->and($html($this->bulmaro))->not->toContain('aria-label="Ejecutivo comercial"');
});

it('el Excel del administrador respeta el ejecutivo elegido', function () {
    test()->actingAs(getDefaultAdmin(), 'user');
    request()->merge([
        'date_filter' => 'custom', 'date_from' => '2020-01-01', 'date_to' => '2020-01-31', 'pipeline_id' => 'all',
        'ejecutivo'   => $this->eduardo->id,
    ]);

    $filas = collect((new \Webkul\Admin\Exports\Dashboard\DashboardOrdersSheet)->array())
        ->map(fn ($fila) => implode('|', array_map('strval', $fila)));

    expect($filas)->toHaveCount(2)
        ->and($filas->every(fn ($f) => str_contains($f, 'Eduardo Lujan')))->toBeTrue();
});
