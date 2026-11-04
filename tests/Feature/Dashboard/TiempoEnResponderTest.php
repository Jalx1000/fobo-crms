<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\HorasHabiles;
use Webkul\User\Models\User;

/**
 * «Tiempo en responder» en horas hábiles: lunes a viernes, 08:00–19:00
 * (spec docs/specs/tiempo-en-responder.md). Antes contaba las 24 h de los 7 días.
 * 2020-01-03 es viernes; 2020-01-06, lunes.
 */
function horas(string $desde, string $hasta): float
{
    return round(HorasHabiles::segundos(Carbon::parse($desde), Carbon::parse($hasta)) / 3600, 2);
}

it('cuenta solo lunes a viernes de 08:00 a 19:00', function () {
    expect(horas('2020-01-03 18:00', '2020-01-06 09:00'))->toBe(2.0)   // viernes → lunes
        ->and(horas('2020-01-05 15:00', '2020-01-06 10:00'))->toBe(2.0) // llega el domingo
        ->and(horas('2020-01-07 20:00', '2020-01-08 08:30'))->toBe(0.5) // martes noche → miércoles
        ->and(horas('2020-01-07 10:00', '2020-01-07 12:30'))->toBe(2.5) // mismo día
        ->and(horas('2020-01-06 08:00', '2020-01-10 19:00'))->toBe(55.0) // semana completa: 5 × 11
        ->and(horas('2020-01-07 12:00', '2020-01-07 10:00'))->toBe(0.0); // al revés: 0
});

it('la tarjeta promedia horas hábiles, no las del fin de semana', function () {
    $this->actingAs(getDefaultAdmin(), 'user');
    $bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();

    // Pedido del viernes 18:00 que sigue en NO ATENDIDOS; «ahora» es el lunes 09:00.
    Carbon::setTestNow('2020-01-06 09:00:00');
    DB::table('leads')->insert([
        'title'                  => 'Fin de semana '.uniqid(), 'user_id' => $bulmaro->id, 'status' => 1, 'lead_pipeline_id' => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', 'no-atendidos')->value('id'),
        'created_at'             => '2020-01-03 18:00:00', 'updated_at' => '2020-01-03 18:00:00',
    ]);

    $s = test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => 'tiempo-por-vendedor', 'start' => '2020-01-01', 'end' => '2020-01-05']))
        ->assertOk()->json('statistics');

    $i = array_search('Bulmaro Vaca', $s['labels']);

    expect(round($s['data'][$i] / 3600, 2))->toBe(2.0);

    Carbon::setTestNow();
});

/**
 * Pedido de Bulmaro creado el viernes 18:00 en la etapa `$codigo`; si
 * `$entro` viene, el historial dice que entró a esa etapa en ese momento.
 * `$pipelineDelHistorial`: el historial apunta a la misma etapa de otra ciudad.
 * Devuelve las horas que muestra la tarjeta con «ahora» = miércoles 12:00.
 */
function horasEnTarjeta(string $codigo, ?string $entro = null, ?int $pipelineDelHistorial = null): float
{
    test()->actingAs(getDefaultAdmin(), 'user');
    $bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $etapa = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', $codigo)->value('id');

    Carbon::setTestNow('2020-01-08 12:00:00');
    $leadId = DB::table('leads')->insertGetId([
        'title'                  => 'Reloj '.uniqid(), 'user_id' => $bulmaro->id, 'status' => 1, 'lead_pipeline_id' => 1,
        'lead_pipeline_stage_id' => $etapa,
        'created_at'             => '2020-01-03 18:00:00', 'updated_at' => '2020-01-08 11:00:00',
    ]);

    if ($entro) {
        $enHistorial = $pipelineDelHistorial
            ? DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineDelHistorial)->where('code', $codigo)->value('id')
            : $etapa;

        $activityId = DB::table('activities')->insertGetId([
            'title'      => 'Actualizado Etapa', 'type' => 'system', 'is_done' => 1,
            'additional' => json_encode(['new' => ['value' => $enHistorial], 'old' => ['value' => 1], 'attribute' => 'Etapa']),
            'created_at' => $entro, 'updated_at' => $entro,
        ]);
        DB::table('lead_activities')->insert(['lead_id' => $leadId, 'activity_id' => $activityId]);
    }

    $s = test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => 'tiempo-por-vendedor', 'start' => '2020-01-01', 'end' => '2020-01-05']))
        ->assertOk()->json('statistics');

    Carbon::setTestNow();

    return round($s['data'][array_search('Bulmaro Vaca', $s['labels'])] / 3600, 2);
}

it('el reloj para cuando el pedido entra a una etapa final', function (string $codigo) {
    // Viernes 18:00 → entró el lunes 10:00: 1 h del viernes + 2 h del lunes.
    expect(horasEnTarjeta($codigo, '2020-01-06 10:00:00'))->toBe(3.0);
})->with(['sin-inters', 'entregados', 'cancelados', 'otros-servicios']);

it('en las demás etapas el reloj sigue hasta ahora', function (string $codigo) {
    // Viernes 18:00 → miércoles 12:00: 1 + 11 (lunes) + 11 (martes) + 4 = 27 h.
    expect(horasEnTarjeta($codigo, '2020-01-06 10:00:00'))->toBe(27.0);
})->with(['no-atendidos', 'solicitud-recibida', 'cotizacion-enviada']);

it('para también si el pedido cambió de ciudad (la etapa de otra ciudad tiene otro id)', function () {
    $otra = DB::table('lead_pipeline_stages')->where('code', 'sin-inters')->where('lead_pipeline_id', '!=', 1)->value('lead_pipeline_id');

    expect(horasEnTarjeta('sin-inters', '2020-01-06 10:00:00', $otra))->toBe(3.0);
});
