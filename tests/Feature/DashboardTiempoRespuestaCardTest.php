<?php

use Illuminate\Support\Facades\DB;

/*
 * La tarjeta de tiempo de respuesta se dibuja en el tablero.
 *
 * La primera versión usaba this.$route.query para leer el filtro global, pero
 * el tablero no monta vue-router: $route venía undefined y la tarjeta moría en
 * el navegador con "Cannot read properties of undefined (reading 'query')".
 * El filtro llega por el evento `reporting-filter-updated`, como en las demás.
 */
beforeEach(function () {
    DB::beginTransaction();
    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

it('el tablero carga con la tarjeta dentro', function () {
    $html = test()->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->getContent();

    expect($html)->toContain('v-dashboard-tiempo-respuesta-whatsapp')
        ->and($html)->toContain('Tiempo de respuesta en WhatsApp');
});

it('toma el filtro del evento del tablero, no de un router que no existe', function () {
    $html = test()->get(route('admin.dashboard.index'))->getContent();

    $inicio = strpos($html, "app.component('v-dashboard-tiempo-respuesta-whatsapp'");
    $siguiente = strpos($html, 'app.component(', $inicio + 20);

    $card = substr($html, $inicio, $siguiente ? $siguiente - $inicio : 4000);

    /*
     * Se afirma lo que el código HACE, no la ausencia de un texto: la primera
     * versión de este test buscaba que no apareciera "$route" y fallaba contra
     * el comentario que explica justamente ese bug.
     */
    expect($card)->toContain("this.\$emitter.on('reporting-filter-updated', this.get)")
        ->and($card)->toContain('Object.assign({}, filtros')
        ->and($card)->toContain('this.get({})');
});

it('el endpoint responde con las dos ramas', function () {
    $r = test()->getJson(route('admin.dashboard.stats', ['type' => 'tiempo-respuesta-whatsapp']))
        ->assertSuccessful()
        ->json('statistics');

    expect($r)->toHaveKeys(['agente', 'asesor', 'por_asesor']);
});
