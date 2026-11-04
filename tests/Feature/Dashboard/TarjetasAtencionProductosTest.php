<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * Tablero: «Atendidos vs no atendidos por encargado» y «Productos más solicitados».
 *
 * - No atendidos salía siempre en 0: se buscaba la etapa «no atendido» y en el
 *   CRM se llama NO ATENIDOS (código `no-atenidos`).
 * - Productos más solicitados contaba los productos cargados en el pedido, que
 *   casi nunca tiene ninguno (en octubre: 4 Stretch Film). Los productos están
 *   en las cotizaciones; se suman sus unidades (D3, 15/10/2026).
 * - Atendidos vs no atendidos SÍ cuenta los pedidos «Sin ciudad» (16/10/2026: el
 *   kanban de Bulmaro tenía 48 no atendidos y la tarjeta mostraba 2); productos
 *   más solicitados sigue sin contarlos.
 *
 * Todo en enero de 2020, para no mezclarse con los datos reales.
 */
require_once __DIR__.'/helpers.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(User::findOrFail(1), 'user');

    $this->asesor = User::whereHas('role', fn ($q) => $q->whereNotIn('name', ['Administrador', 'Supervisores', 'Sistema']))
        ->where('status', 1)->where('name', '!=', 'Agente')->firstOrFail();

    $this->sinCiudad = (int) DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');
    $this->fecha = '2020-01-15 10:00:00';

    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Tarjeta '.uniqid(), 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);
});

it('cuenta los NO ATENIDOS como no atendidos', function () {
    tarjetaPedido($this, 1, 'no-atendidos');
    tarjetaPedido($this, 1, 'no-atendidos');
    tarjetaPedido($this, 1, 'solicitud-recibida');

    $s = tarjeta('leads-attention-by-users');
    $i = array_search($this->asesor->name, $s['labels']);

    expect($s['not_attended'][$i])->toBe(2)
        ->and($s['attended'][$i])->toBe(1);
});

it('atendidos vs no atendidos cuenta también los pedidos Sin ciudad', function () {
    tarjetaPedido($this, 1, 'no-atendidos');
    tarjetaPedido($this, $this->sinCiudad, 'no-atendidos');
    tarjetaPedido($this, $this->sinCiudad, 'solicitud-recibida');

    $s = tarjeta('leads-attention-by-users');
    $i = array_search($this->asesor->name, $s['labels']);

    expect($s['not_attended'][$i])->toBe(2)
        ->and($s['attended'][$i])->toBe(1);
});

// Desde el 15/10/2026 (D3) la torta se ordena por unidades cotizadas, no por
// cantidad de cotizaciones: 40 rollos de Stretch van antes que 15 bolsas.
it('productos más solicitados ordena por unidades cotizadas e informa las cotizaciones', function () {
    $lead = tarjetaPedido($this, 1, 'solicitud-recibida');
    tarjetaCotizacion($this, $lead, 'CM_00002', 10);
    tarjetaCotizacion($this, $lead, 'CM_00002', 5);
    tarjetaCotizacion($this, $lead, 'CM_00001', 40);

    $s = collect(tarjeta('quantity-products'));

    expect($s->pluck('name')->first())->toBe('Stretch Film')
        ->and($s->firstWhere('name', 'Bolsas Magia Verde')['solicitudes'])->toBe(2)
        ->and((int) $s->firstWhere('name', 'Bolsas Magia Verde')['total_qty_ordered'])->toBe(15)
        ->and($s->firstWhere('name', 'Stretch Film')['solicitudes'])->toBe(1);
});

it('productos más solicitados no cuenta cotizaciones de pedidos Sin ciudad', function () {
    $conCiudad = tarjetaPedido($this, 1, 'solicitud-recibida');
    $sinCiudad = tarjetaPedido($this, $this->sinCiudad, 'solicitud-recibida');

    tarjetaCotizacion($this, $conCiudad, 'CM_00003', 1);
    tarjetaCotizacion($this, $sinCiudad, 'CM_00003', 1);
    tarjetaCotizacion($this, $sinCiudad, 'CM_00004', 1);

    $s = collect(tarjeta('quantity-products'));

    expect($s->firstWhere('name', 'Hules')['solicitudes'])->toBe(1)
        ->and($s->pluck('name'))->not->toContain('Tapas');
});

it('pedidos por ciudad cuenta todas las etapas y la porción Sin ciudad, con su total', function () {
    // La torta debe cuadrar con el kanban: no solo los no atendidos.
    tarjetaPedido($this, 1, 'no-atendidos');
    tarjetaPedido($this, $this->sinCiudad, 'no-atendidos');
    tarjetaPedido($this, $this->sinCiudad, 'no-atendidos');
    tarjetaPedido($this, 1, 'solicitud-recibida');
    tarjetaPedido($this, 1, 'cotizacion-enviada');
    tarjetaPedido($this, 1, 'cancelados');

    $s = tarjeta('pedidos-por-ciudad');
    $porCiudad = array_combine($s['labels'], $s['data']);

    expect($porCiudad['Sin ciudad'])->toBe(2)
        ->and($porCiudad[DB::table('lead_pipelines')->where('id', 1)->value('name')])->toBe(4)
        ->and($s['total'])->toBe(array_sum($s['data']))
        ->and($s['total'])->toBe(collect(tarjeta('open-leads-by-states-fixed'))->sum('total'));
});

it('atendidos vs no atendidos no trae la barra del usuario del agente', function () {
    expect(tarjeta('leads-attention-by-users')['labels'])->not->toContain('Agente')
        ->not->toContain('Sin ciudad');
});

it('los prospectos son los pedidos en NO ATENIDOS', function () {
    tarjetaPedido($this, 1, 'no-atendidos');
    tarjetaPedido($this, 1, 'no-atendidos');
    tarjetaPedido($this, 1, 'solicitud-recibida');

    $total = app(\Webkul\Admin\Helpers\Reporting\Lead::class)
        ->getTotalLeads(\Carbon\Carbon::parse('2020-01-01'), \Carbon\Carbon::parse('2020-01-31 23:59:59'));

    expect($total)->toBe(2);
});

it('el segmento que sigue a los no atendidos son las cotizaciones enviadas', function () {
    tarjetaPedido($this, 1, 'cotizacion-enviada');
    tarjetaPedido($this, 1, 'cotizacion-enviada');
    tarjetaPedido($this, 1, 'solicitud-recibida');

    $serie = app(\Webkul\Admin\Helpers\Reporting\Lead::class)
        ->setStartDate(\Illuminate\Support\Carbon::parse('2020-01-01'))
        ->setEndDate(\Illuminate\Support\Carbon::parse('2020-01-31 23:59:59'))
        ->getTotalConfirmedLeadsOverTime('day');

    expect(collect($serie)->sum('count'))->toBe(2);
});
