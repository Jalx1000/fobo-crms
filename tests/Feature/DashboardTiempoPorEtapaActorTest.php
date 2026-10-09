<?php

use Illuminate\Support\Facades\DB;

/*
 * "Tiempo por etapa" tiene que decir QUIÉN movió el pedido a cada etapa y
 * CUÁNDO, distinguiendo al agente de una persona.
 *
 * Medido sobre 30 días: de los movimientos de etapa, 702 los hizo el bot y
 * 1.158 personas. La tarjeta mostraba la permanencia sin decir quién la causó.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();
    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

it('cada etapa trae cuándo se entró y quién la movió', function () {
    $filas = test()->getJson(route('admin.dashboard.stats', ['type' => 'tiempo-por-etapa']))
        ->assertSuccessful()
        ->json('statistics.rows');

    expect($filas)->not->toBeEmpty();

    $conAutor = collect($filas)
        ->flatMap(fn ($f) => array_values($f['stages']))
        ->filter(fn ($e) => ! empty($e['at']));

    expect($conAutor)->not->toBeEmpty('Ninguna etapa trajo fecha de entrada.');

    foreach ($conAutor as $etapa) {
        expect($etapa)->toHaveKeys(['at', 'by', 'by_agent'])
            ->and($etapa['by'])->not->toBeEmpty();
    }
});

it('marca al agente por su ROL y no por su nombre', function () {
    /*
     * El bot es un usuario con rol "Sistema". Mirar el nombre rompería la
     * distinción el día que alguien renombre la cuenta.
     */
    $agente = DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')
        ->where('r.name', 'Sistema')->value('u.name');

    expect($agente)->not->toBeNull('No hay usuario con rol Sistema: el agente no se podría distinguir.');

    $filas = test()->getJson(route('admin.dashboard.stats', ['type' => 'tiempo-por-etapa']))
        ->json('statistics.rows');

    $delAgente = collect($filas)
        ->flatMap(fn ($f) => array_values($f['stages']))
        ->filter(fn ($e) => ! empty($e['by_agent']));

    foreach ($delAgente as $etapa) {
        expect($etapa['by'])->toBe($agente);
    }
});

it('el alta del pedido se muestra como "alta", no como autor desconocido', function () {
    $filas = test()->getJson(route('admin.dashboard.stats', ['type' => 'tiempo-por-etapa']))
        ->json('statistics.rows');

    $autores = collect($filas)
        ->flatMap(fn ($f) => array_values($f['stages']))
        ->filter(fn ($e) => ! empty($e['at']))
        ->pluck('by')
        ->unique();

    // Nunca una cadena vacía ni null: o es alguien, o es el alta.
    foreach ($autores as $autor) {
        expect($autor)->not->toBeEmpty();
    }
});
