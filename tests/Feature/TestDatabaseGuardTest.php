<?php

use Illuminate\Support\Facades\DB;

/**
 * El guardarraíl que impide que un test vacíe una tabla real.
 *
 * Existe porque esta suite NO corre contra una base descartable: `phpunit.xml`
 * no sobreescribe ninguna variable DB_*, así que los tests se conectan a la
 * misma base que la aplicación. Un `truncate()` en un teardown no borra datos
 * de prueba, borra el catálogo.
 *
 * Ya pasó: `ProductosByCiudadSucursalTest` truncaba `products`.
 *
 * Estos casos son la prueba de que la red sigue puesta. Si alguno empieza a
 * fallar, la protección se cayó y la suite volvió a ser peligrosa.
 */
it('bloquea un truncate sobre una tabla de negocio', function () {
    expect(fn () => DB::table('products')->truncate())
        ->toThrow(RuntimeException::class, 'TRUNCATE');
});

it('bloquea un delete sin where', function () {
    // El query builder de Laravel no permite un delete sin where de forma
    // directa, así que se emula la sentencia cruda que sí lo haría.
    expect(fn () => DB::statement('DELETE FROM '.DB::getTablePrefix().'products'))
        ->toThrow(RuntimeException::class, 'DELETE sin WHERE');
});

it('bloquea un drop de tabla', function () {
    expect(fn () => DB::statement('DROP TABLE '.DB::getTablePrefix().'products'))
        ->toThrow(RuntimeException::class, 'estructura');
});

it('deja pasar un delete acotado', function () {
    // Un id que no existe: la sentencia es válida y no borra nada.
    expect(fn () => DB::table('products')->where('id', -1)->delete())
        ->not->toThrow(RuntimeException::class);
});

it('no molesta a las tablas que no son de negocio', function () {
    expect(fn () => DB::table('whatsapp_messages')->where('id', -1)->delete())
        ->not->toThrow(RuntimeException::class);
});
