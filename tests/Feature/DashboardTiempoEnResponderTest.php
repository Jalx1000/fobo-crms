<?php

use Illuminate\Support\Facades\DB;

/*
 * "Tiempo en responder" suma el tiempo que el pedido estuvo en las etapas que
 * movió el PROPIO asesor del lead. No cuenta lo del agente ni lo de terceros.
 * Una etapa final cuenta hasta el fin del día en que se cerró; una intermedia
 * abierta queda fuera de la suma y se informa aparte como "en curso".
 *
 * Antes la tarjeta usaba COALESCE(closed_at, confirmed_at, ahora). Esas columnas
 * no guardan quién las escribió y el bot las escribe en los primeros minutos:
 * el #4345 del 9-oct-2026 marcaba 3.3 min porque el bot puso confirmed_at a los
 * 3 min, cuando la asesora no lo había tocado en más de una hora.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();
    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

/**
 * Asesor recién creado, no uno real: si el asesor tuviera leads propios en la
 * ventana del test, su promedio los mezclaría con el lead de prueba y el
 * resultado dependería de los datos de producción. Se va con el rollback.
 */
function asesorDePrueba(string $sufijo = ''): object
{
    $rolId = DB::table('roles')->where('name', 'Encargado')->value('id');

    $id = DB::table('users')->insertGetId([
        'name'       => 'Asesor Tiempo Respuesta '.($sufijo ?: 'A').' (test)',
        'email'      => 'asesor-tr-'.strtolower($sufijo ?: 'a').'-test@example.com',
        'password'   => bcrypt('secret'),
        'status'     => 1,
        'role_id'    => $rolId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('users')->where('id', $id)->select('id', 'name')->first();
}

function etapaPorCode(string $code): object
{
    return DB::table('lead_pipeline_stages')->where('code', $code)->first();
}

/**
 * Lead de prueba con dueño, creado hace $haceMinutos, en "No atendido".
 */
function leadDePrueba(int $ownerId, int $haceMinutos): int
{
    $creado = now()->subMinutes($haceMinutos);
    $etapa = etapaPorCode('no-atendido');

    return DB::table('leads')->insertGetId([
        'title'                  => 'Tiempo en responder (test)',
        'user_id'                => $ownerId,
        'lead_pipeline_id'       => $etapa->lead_pipeline_id,
        'lead_pipeline_stage_id' => $etapa->id,
        'status'                 => 1,
        'created_at'             => $creado,
        'updated_at'             => $creado,
    ]);
}

/**
 * Registra el movimiento de etapa tal como lo graba el log de actividades, y
 * deja el lead en esa etapa.
 */
function moverEtapa(int $leadId, ?int $userId, string $code, float $haceMinutos): void
{
    $etapa = etapaPorCode($code);
    $cuando = now()->subSeconds((int) round($haceMinutos * 60));

    $id = DB::table('activities')->insertGetId([
        'title'      => 'Actualizado Etapa',
        'type'       => 'system',
        'user_id'    => $userId,
        'additional' => json_encode([
            'attribute' => 'Etapa',
            'new'       => ['value' => (string) $etapa->id],
        ]),
        'created_at' => $cuando,
        'updated_at' => $cuando,
    ]);

    DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => $leadId]);

    DB::table('leads')->where('id', $leadId)->update([
        'lead_pipeline_stage_id' => $etapa->id,
        'lead_pipeline_id'       => $etapa->lead_pipeline_id,
    ]);
}

/**
 * Segundos que debe sumar una etapa final abierta hace $haceMinutos: hasta el
 * fin de ese día, o hasta ahora si todavía es el mismo día.
 */
function finalHastaFinDeDia(float $haceMinutos): int
{
    $entro = now()->subSeconds((int) round($haceMinutos * 60));

    return (int) $entro->diffInSeconds(min(now(), $entro->copy()->endOfDay()));
}

function idDelAgente(): ?int
{
    return DB::table('users as u')
        ->join('roles as r', 'r.id', '=', 'u.role_id')
        ->where('r.name', 'Sistema')
        ->value('u.id');
}

function tiempoDe(string $asesor, int $diasAtras = 1): array
{
    $stats = test()->getJson(route('admin.dashboard.stats', [
        'type'  => 'tiempo-por-vendedor',
        'start' => now()->subDays($diasAtras)->toDateString(),
        'end'   => now()->toDateString(),
    ]))->assertSuccessful()->json('statistics');

    $i = array_search($asesor, $stats['labels'], true);

    expect($i)->not->toBeFalse("El asesor {$asesor} no aparece en la tarjeta.");

    return [
        'segundos'  => $stats['data'][$i],
        'promedio'  => $stats['average'][$i],
        'leads'     => $stats['leads'][$i],
        'pendiente' => $stats['pending'][$i],
        'en_curso'  => $stats['pending_seconds'][$i],
        'sin_tocar' => $stats['untouched'][$i],
    ];
}

it('suma sólo los tramos que movió el propio asesor', function () {
    $asesor = asesorDePrueba();
    $leadId = leadDePrueba($asesor->id, 120);

    // El agente lo trabaja y lo deja confirmado: ese tramo no es del asesor.
    moverEtapa($leadId, idDelAgente(), 'cliente-confirmado', 118);

    // El asesor lo pasa a "Cliente contactado" y ahí se queda 18 min...
    moverEtapa($leadId, $asesor->id, 'cliente-contactado', 100);

    // ...hasta que él mismo lo pasa a "Otros Servicios", donde sigue abierto.
    moverEtapa($leadId, $asesor->id, 'otros-servicios', 82);

    $t = tiempoDe($asesor->name);

    /*
     * Sólo los 18 min del tramo cerrado. Los 82 min de "Otros Servicios"
     * siguen abiertos: van aparte como en curso. Los 2 min del agente no entran.
     */
    expect($t['segundos'])->toBe(18 * 60)
        ->and($t['pendiente'])->toBe(1)
        ->and($t['en_curso'])->toBeGreaterThanOrEqual(82 * 60 - 5)
        ->and($t['en_curso'])->toBeLessThanOrEqual(82 * 60 + 5);
});

it('no le carga al asesor los tramos que movió otra persona', function () {
    /*
     * El #4345 del 9-oct: Sofopolis (administrador) movió una etapa del lead de
     * María Rene. Esos segundos son de Sofopolis, no de ella.
     */
    $asesor = asesorDePrueba('A');
    $otro = asesorDePrueba('B');
    $leadId = leadDePrueba($asesor->id, 120);

    moverEtapa($leadId, $otro->id, 'cliente-contactado', 118);

    $t = tiempoDe($asesor->name);

    // El dueño no movió nada: no entra a la suma, se cuenta como sin tocar.
    expect($t['segundos'])->toBe(0)
        ->and($t['sin_tocar'])->toBe(1);
});

it('una etapa final cuenta sólo hasta el fin del día en que se cerró', function () {
    /*
     * Un pedido entregado hace 3 días no puede seguir sumando 24 h por día:
     * en septiembre eso era el 70 % de la tarjeta.
     */
    $asesor = asesorDePrueba();
    $leadId = leadDePrueba($asesor->id, 3 * 1440 + 10);

    moverEtapa($leadId, $asesor->id, 'pedidos-entregados', 3 * 1440);

    $t = tiempoDe($asesor->name, 4);

    expect($t['segundos'])->toBe(finalHastaFinDeDia(3 * 1440))
        ->and($t['segundos'])->toBeLessThan(86400)
        ->and($t['pendiente'])->toBe(0);
});

it('suma y promedia como María Rene el 9-oct', function () {
    /*
     * #4753: el agente lo deja confirmado; ella lo pasa a sin interés (3 s) y
     * luego a entregados (33 m hasta ahora).
     * #4345: el agente lo confirma, Sofopolis lo pasa a sin interés (no es de
     * ella) y ella lo pasa a cancelados (31 m hasta ahora).
     * Suma: 3 s + 33 m + 31 m = 1 h 04 m 03 s; promedio por lead: 32 m 02 s.
     */
    $asesora = asesorDePrueba('A');
    $sofopolis = asesorDePrueba('B');

    $a = leadDePrueba($asesora->id, 39);
    moverEtapa($a, idDelAgente(), 'cliente-confirmado', 35);
    moverEtapa($a, $asesora->id, 'cliente-sin-inters', 33.05);
    moverEtapa($a, $asesora->id, 'pedidos-entregados', 33);

    $b = leadDePrueba($asesora->id, 125);
    moverEtapa($b, idDelAgente(), 'cliente-confirmado', 122);
    moverEtapa($b, $sofopolis->id, 'cliente-sin-inters', 32);
    moverEtapa($b, $asesora->id, 'pedidos-cancelados', 31);

    $t = tiempoDe($asesora->name);

    // Mismo día: 3 + 1980 + 1860 = 3843 s. Cerca de medianoche, el tope corta.
    $esperado = 3 + finalHastaFinDeDia(33) + finalHastaFinDeDia(31);

    expect($t['segundos'])->toBeGreaterThanOrEqual($esperado - 5)
        ->and($t['segundos'])->toBeLessThanOrEqual($esperado + 5)
        ->and($t['promedio'])->toBe((int) round($t['segundos'] / 2))
        ->and($t['leads'])->toBe(2);
});

it('una etapa intermedia abierta queda fuera de la suma', function () {
    /*
     * El lead parado en "Cliente contactado" hace 10 h: corre, pero se informa
     * aparte. Si entrara a la suma, uno olvidado hace semanas taparía todo.
     */
    $asesor = asesorDePrueba();
    $leadId = leadDePrueba($asesor->id, 600);

    moverEtapa($leadId, $asesor->id, 'cliente-contactado', 590);

    $t = tiempoDe($asesor->name);

    expect($t['segundos'])->toBe(0)
        ->and($t['leads'])->toBe(0)
        ->and($t['pendiente'])->toBe(1)
        ->and($t['en_curso'])->toBeGreaterThan(9 * 3600);
});

it('no cuenta los movimientos del agente', function () {
    $asesor = asesorDePrueba();
    $leadId = leadDePrueba($asesor->id, 120);

    moverEtapa($leadId, idDelAgente(), 'cliente-confirmado', 118);

    $t = tiempoDe($asesor->name);

    expect($t['segundos'])->toBe(0)
        ->and($t['sin_tocar'])->toBe(1);
});

it('no cuenta el alta del lead, que no tiene usuario', function () {
    $asesor = asesorDePrueba();
    $leadId = leadDePrueba($asesor->id, 120);

    // Las actividades del alta van con user_id nulo.
    moverEtapa($leadId, null, 'cliente-confirmado', 120);

    $t = tiempoDe($asesor->name);

    expect($t['segundos'])->toBe(0)
        ->and($t['sin_tocar'])->toBe(1);
});

it('distingue al agente por su rol y no por su id', function () {
    /*
     * Si mañana se recrea la cuenta del bot con otro id, la métrica no debe
     * empezar a contar sus movimientos como trabajo del asesor.
     */
    $fuente = file_get_contents(
        base_path('packages/Webkul/Admin/src/Helpers/Reporting/Lead.php')
    );

    $pos = strpos($fuente, 'function getAverageResponseTimeByUsers');
    $metodo = substr($fuente, $pos, strpos($fuente, 'function stageGroups') - $pos);

    /*
     * `confirmed_at` no debe volver: lo escribe el bot a los minutos y era
     * justo lo que congelaba el reloj del asesor.
     */
    expect($metodo)->not->toContain('confirmed_at')
        ->and($metodo)->not->toContain('closed_at');
});

it('la tarjeta expone los totales de pendientes y sin tocar', function () {
    $stats = test()->getJson(route('admin.dashboard.stats', [
        'type' => 'tiempo-por-vendedor',
    ]))->assertSuccessful()->json('statistics');

    expect($stats)->toHaveKeys([
        'labels', 'data', 'previous_data', 'average', 'leads', 'total_seconds',
        'total_leads', 'pending', 'pending_total', 'pending_seconds',
        'pending_seconds_total',
        'untouched', 'untouched_total',
    ])
        ->and($stats['pending'])->toHaveCount(count($stats['labels']))
        ->and($stats['untouched'])->toHaveCount(count($stats['labels']))
        ->and($stats['pending_total'])->toBe(array_sum($stats['pending']))
        ->and($stats['untouched_total'])->toBe(array_sum($stats['untouched']));
});
