<?php

use Illuminate\Support\Facades\DB;

/*
 * "Distribución del tiempo de respuesta": cuánto tarda cada movimiento de etapa
 * cerrado, desde que el lead entra a una etapa hasta que pasa a la siguiente.
 * No cuenta el tramo abierto ni los pasos de menos de 1 minuto.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción,
 * y las aserciones van filtradas por un encargado de prueba, porque "Todos"
 * mezcla los datos reales.
 */
beforeEach(function () {
    DB::beginTransaction();
    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

function encargadoDeDistribucion(string $sufijo = 'A'): int
{
    return DB::table('users')->insertGetId([
        'name'       => 'Encargado Distribucion '.$sufijo.' (test)',
        'email'      => 'encargado-dtr-'.strtolower($sufijo).'-test@example.com',
        'password'   => bcrypt('secret'),
        'status'     => 1,
        'role_id'    => DB::table('roles')->where('name', 'Encargado')->value('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function leadDeDistribucion(int $ownerId, float $haceMinutos): int
{
    $etapa = DB::table('lead_pipeline_stages')->where('code', 'no-atendido')->first();
    $creado = now()->subSeconds((int) round($haceMinutos * 60));

    return DB::table('leads')->insertGetId([
        'title'                  => 'Distribución del tiempo (test)',
        'user_id'                => $ownerId,
        'lead_pipeline_id'       => $etapa->lead_pipeline_id,
        'lead_pipeline_stage_id' => $etapa->id,
        'status'                 => 1,
        'created_at'             => $creado,
        'updated_at'             => $creado,
    ]);
}

/**
 * Movimiento de etapa tal como lo graba el log de actividades.
 */
function movimientoDeDistribucion(int $leadId, string $code, float $haceMinutos): void
{
    $etapa = DB::table('lead_pipeline_stages')->where('code', $code)->first();
    $cuando = now()->subSeconds((int) round($haceMinutos * 60));

    $id = DB::table('activities')->insertGetId([
        'title'      => 'Actualizado Etapa',
        'type'       => 'system',
        'user_id'    => null,
        'additional' => json_encode([
            'attribute' => 'Etapa',
            'new'       => ['value' => (string) $etapa->id],
        ]),
        'created_at' => $cuando,
        'updated_at' => $cuando,
    ]);

    DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => $leadId]);
}

function distribucion(?int $userId = null, int $diasAtras = 1): array
{
    return test()->getJson(route('admin.dashboard.stats', [
        'type'    => 'distribucion-tiempo-respuesta',
        'start'   => now()->subDays($diasAtras)->toDateString(),
        'end'     => now()->toDateString(),
        'user_id' => $userId,
    ]))->assertSuccessful()->json('statistics');
}

it('marca sin datos al encargado que no tiene movimientos, sin inventar un 0', function () {
    $id = encargadoDeDistribucion();

    $stats = distribucion($id);

    $fila = collect($stats['users'])->firstWhere('id', $id);

    expect($fila['has_data'])->toBeFalse()
        ->and($fila['count'])->toBe(0)
        ->and($stats['total'])->toBe(0)
        ->and($stats['min'])->toBeNull()
        ->and($stats['avg'])->toBeNull()
        ->and($stats['median'])->toBeNull()
        ->and($stats['max'])->toBeNull()
        ->and($stats['buckets'])->toHaveCount(14);
});

it('mide un solo movimiento cerrado y no el tramo abierto', function () {
    $id = encargadoDeDistribucion();
    $lead = leadDeDistribucion($id, 130);

    // Entra a contactado hace 120 min y pasa a confirmado hace 60: 1 hora.
    movimientoDeDistribucion($lead, 'cliente-contactado', 120);
    movimientoDeDistribucion($lead, 'cliente-confirmado', 60);

    // Sigue en confirmado: ese tramo abierto no se mide.
    $stats = distribucion($id);

    expect($stats['user_id'])->toBe($id)
        ->and($stats['total'])->toBe(1)
        ->and($stats['min'])->toBe(3600)
        ->and($stats['max'])->toBe(3600)
        ->and($stats['avg'])->toBe(3600)
        ->and($stats['median'])->toBe(3600)
        ->and($stats['count_under_24h'])->toBe(1)
        ->and($stats['pct_under_24h'])->toEqual(100)
        ->and($stats['buckets'][0]['count'])->toBe(1)
        ->and($stats['buckets'][0]['percent'])->toEqual(100);
});

it('no cuenta los pasos de menos de 1 minuto', function () {
    $id = encargadoDeDistribucion();
    $lead = leadDeDistribucion($id, 130);

    // El agente lo mueve dos veces en 30 segundos: paso automático.
    movimientoDeDistribucion($lead, 'cliente-contactado', 120);
    movimientoDeDistribucion($lead, 'cliente-confirmado', 119.5);
    movimientoDeDistribucion($lead, 'pedidos-entregados', 59.5);

    $stats = distribucion($id);

    // Sólo queda la hora en confirmado; los 30 s no bajan el mejor a 0.
    expect($stats['total'])->toBe(1)
        ->and($stats['min'])->toBe(3600);
});

it('agrupa los valores extremos en 13+ y calcula la mediana con cantidad par', function () {
    $id = encargadoDeDistribucion();
    $lead = leadDeDistribucion($id, 21 * 1440 + 10);

    // 20 días en contactado (extremo), 2 h en confirmado y 1 h en otros servicios.
    movimientoDeDistribucion($lead, 'cliente-contactado', 21 * 1440);
    movimientoDeDistribucion($lead, 'cliente-confirmado', 1440 + 180);
    movimientoDeDistribucion($lead, 'otros-servicios', 1440 + 60);
    movimientoDeDistribucion($lead, 'pedidos-entregados', 1440);

    $stats = distribucion($id, 22);

    $veinteDias = 21 * 86400 - (86400 + 3 * 3600);

    expect($stats['total'])->toBe(3)
        ->and($stats['min'])->toBe(3600)
        ->and($stats['max'])->toBe($veinteDias)
        ->and($stats['median'])->toBe(2 * 3600)
        ->and($stats['buckets'][13]['label'])->toBe('13+')
        ->and($stats['buckets'][13]['count'])->toBe(1)
        ->and($stats['buckets'][0]['count'])->toBe(2)
        ->and($stats['count_under_24h'])->toBe(2);
});

it('cada movimiento pesa igual en Todos y por encargado suma lo suyo', function () {
    $a = encargadoDeDistribucion('A');
    $b = encargadoDeDistribucion('B');

    $leadA = leadDeDistribucion($a, 130);
    movimientoDeDistribucion($leadA, 'cliente-contactado', 120);
    movimientoDeDistribucion($leadA, 'cliente-confirmado', 60);

    $leadB = leadDeDistribucion($b, 400);
    movimientoDeDistribucion($leadB, 'cliente-contactado', 390);
    movimientoDeDistribucion($leadB, 'cliente-confirmado', 330);
    movimientoDeDistribucion($leadB, 'otros-servicios', 270);

    $todos = distribucion();
    $filas = collect($todos['users'])->keyBy('id');

    expect($filas[$a]['count'])->toBe(1)
        ->and($filas[$b]['count'])->toBe(2)
        ->and($todos['user_id'])->toBeNull()
        ->and($todos['total'])->toBeGreaterThanOrEqual(3);
});

it('ignora un user_id que no es un encargado visible', function () {
    $agente = DB::table('users as u')
        ->join('roles as r', 'r.id', '=', 'u.role_id')
        ->where('r.name', 'Sistema')
        ->value('u.id');

    $stats = distribucion($agente);

    expect($stats['user_id'])->toBeNull()
        ->and(collect($stats['users'])->pluck('id'))->not->toContain($agente);
});
