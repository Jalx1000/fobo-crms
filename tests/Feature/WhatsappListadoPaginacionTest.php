<?php

use Illuminate\Support\Facades\DB;

/*
 * Paginación del listado de conversaciones.
 *
 * El bug: el listado se refresca solo cada 10 s, y ese refresco pedía siempre
 * `page: 1` y reemplazaba el array entero. Quien apretaba "cargar más" veía 60
 * filas y, a los diez segundos, volvía a 30 y la lista saltaba al principio.
 *
 * El arreglo es que el refresco pida de una el bloque completo que el usuario
 * ya tiene en pantalla. Estos tests fijan el contrato del backend que eso
 * necesita; la parte de Vue se verifica en el navegador.
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
 * Va por HTTP y no llamando al controller directo: `paginate()` resuelve la
 * página desde el request GLOBAL, así que invocando el método a mano todas las
 * llamadas devuelven la página 1 y un test de paginación no probaría nada.
 */
function listado(array $params = []): array
{
    return test()->getJson(route('admin.whatsapp.conversations', array_merge(['filter' => 'all'], $params)))
        ->assertSuccessful()
        ->json();
}

it('devuelve 30 por página cuando no le piden otra cosa', function () {
    expect(listado()['data'])->toHaveCount(30);
});

it('devuelve el bloque completo que le piden', function () {
    // Es lo que hace el refresco para no perder lo que el usuario cargó.
    expect(listado(['per_page' => 90])['data'])->toHaveCount(90);
});

it('tiene techo: no manda el listado entero aunque se lo pidan', function () {
    /*
     * El bloque viaja cada 10 segundos por pestaña abierta. 30 filas son ~10 KB,
     * así que sin techo un usuario con todo cargado se traería ~240 KB cada
     * diez segundos.
     */
    expect(listado(['per_page' => 9999])['data'])->toHaveCount(300);
});

it('ignora un per_page más chico que la página', function () {
    expect(listado(['per_page' => 5])['data'])->toHaveCount(30);
});

it('ignora un per_page que no es un número', function () {
    expect(listado(['per_page' => 'todas'])['data'])->toHaveCount(30);
});

it('informa el total y el techo, que es con lo que el front decide', function () {
    $r = listado();

    /*
     * El front NO puede usar last_page: el refresco pide bloques de tamaño
     * variable, así que last_page cambia en cada llamada.
     */
    expect($r['total'])->toBeGreaterThan(0)
        ->and($r['max_pages'])->toBe(10)
        ->and($r['total'])->toBe(listado(['per_page' => 300])['total']);
});

it('el bloque grande empieza por las mismas conversaciones que el chico', function () {
    // Si no, "cargar más" reordenaría lo que el usuario ya estaba mirando.
    $chico = collect(listado(['per_page' => 30])['data'])->pluck('id');
    $grande = collect(listado(['per_page' => 90])['data'])->pluck('id')->take(30);

    expect($grande->all())->toBe($chico->all());
});

it('la segunda página no repite lo que trajo la primera', function () {
    $primera = collect(listado(['page' => 1])['data'])->pluck('id');
    $segunda = collect(listado(['page' => 2])['data'])->pluck('id');

    expect($primera->intersect($segunda))->toBeEmpty();
});
