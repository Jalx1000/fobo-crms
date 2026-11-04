<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * Tablero: «Distribución del tiempo de respuesta», la de kohlberg (20/10/2026,
 * spec docs/specs/distribucion-tiempo-respuesta.md), en horas hábiles y sin
 * los movimientos en bloque. Todo en enero de 2020 (el 15 es miércoles).
 */
require_once __DIR__.'/helpers.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(User::findOrFail(1), 'user');

    $this->asesor = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->fecha = '2020-01-15 10:00:00';
});

/**
 * Un «Actualizado Etapa» del pedido, como lo deja el CRM.
 */
function moverPedido(int $lead, string $cuando, ?int $usuario = null): void
{
    $id = DB::table('activities')->insertGetId([
        'title'      => 'Actualizado Etapa', 'type' => 'system', 'is_done' => 1, 'user_id' => $usuario,
        'additional' => json_encode(['new' => ['label' => 'X', 'value' => 1], 'old' => ['label' => null, 'value' => null], 'attribute' => 'Etapa']),
        'created_at' => $cuando, 'updated_at' => $cuando,
    ]);

    DB::table('lead_activities')->insert(['lead_id' => $lead, 'activity_id' => $id]);
}

it('mide cada paso entre etapas en horas hábiles, con el histograma por días hábiles', function () {
    $lead = tarjetaPedido($this, 1, 'cotizacion-enviada');

    moverPedido($lead, '2020-01-15 10:00:00');                         // nace (NO ATENDIDOS)
    moverPedido($lead, '2020-01-15 12:00:00', $this->asesor->id);      // 2 h
    moverPedido($lead, '2020-01-17 18:00:00', $this->asesor->id);      // 7 + 11 + 10 = 28 h
    moverPedido($lead, '2020-01-20 09:00:00', $this->asesor->id);      // viernes 18 → lunes 9: 2 h

    $s = tarjeta('distribucion-tiempo-respuesta');

    expect($s['total'])->toBe(3)
        ->and($s['segundos_por_dia'])->toBe(11 * 3600)
        ->and($s['min'])->toBe(2 * 3600)
        ->and($s['median'])->toBe(2 * 3600)
        ->and($s['max'])->toBe(28 * 3600)
        ->and($s['avg'])->toBe(intdiv(32 * 3600, 3))
        ->and($s['count_under_1_day'])->toBe(2)
        ->and($s['buckets'][0]['count'])->toBe(2)
        ->and($s['buckets'][2]['count'])->toBe(1);   // 28 h = 2,5 días hábiles
});

it('no cuenta los movimientos en bloque ni los de menos de un minuto', function () {
    // Un usuario mueve 5 pedidos en el mismo minuto: limpieza, no respuesta.
    foreach (range(1, 5) as $i) {
        $lead = tarjetaPedido($this, 1, 'solicitud-recibida');
        moverPedido($lead, '2020-01-15 10:00:00');
        moverPedido($lead, '2020-01-16 17:21:'.str_pad((string) ($i * 5), 2, '0', STR_PAD_LEFT), $this->asesor->id);
    }

    // 4 en el mismo minuto sí cuentan; y un paso de 30 s no.
    foreach (range(1, 4) as $i) {
        $lead = tarjetaPedido($this, 1, 'solicitud-recibida');
        moverPedido($lead, '2020-01-15 10:00:00');
        moverPedido($lead, '2020-01-15 11:00:0'.$i, $this->asesor->id);
    }

    $rapido = tarjetaPedido($this, 1, 'solicitud-recibida');
    moverPedido($rapido, '2020-01-15 10:00:00');
    moverPedido($rapido, '2020-01-15 10:00:30', $this->asesor->id);

    $s = tarjeta('distribucion-tiempo-respuesta');

    expect($s['total'])->toBe(4)
        ->and($s['excluidos_en_bloque'])->toBe(5)
        ->and($s['max'])->toBe(3600 + 4);
});

it('cuenta solo los movimientos del período y de la ciudad elegida', function () {
    $antes = tarjetaPedido($this, 1, 'solicitud-recibida');
    moverPedido($antes, '2019-12-31 10:00:00');
    moverPedido($antes, '2020-01-02 10:00:00', $this->asesor->id);   // termina en el período: cuenta
    moverPedido($antes, '2020-02-03 10:00:00', $this->asesor->id);   // fuera del período

    $otraCiudad = tarjetaPedido($this, 7, 'solicitud-recibida');
    moverPedido($otraCiudad, '2020-01-15 10:00:00');
    moverPedido($otraCiudad, '2020-01-15 11:00:00', $this->asesor->id);

    $s = test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', [
            'type' => 'distribucion-tiempo-respuesta', 'start' => '2020-01-01', 'end' => '2020-01-31', 'pipeline_id' => 1,
        ]))
        ->assertOk()
        ->json('statistics');

    expect($s['total'])->toBe(1)
        ->and($s['max'])->toBe(22 * 3600);   // 31/12 10:00 → 02/01 10:00 (sin feriados)
});

it('la tarjeta está en el tablero', function () {
    expect(test()->get(route('admin.dashboard.index'))->assertOk()->getContent())
        ->toContain('Distribución del tiempo de respuesta');
});
