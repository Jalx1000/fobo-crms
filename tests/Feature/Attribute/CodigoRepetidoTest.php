<?php

use Webkul\Attribute\Repositories\AttributeRepository;

/**
 * Un mismo código de atributo en dos entidades distintas.
 *
 * `getAttributeByCode()` buscaba solo por código y cacheaba por código. Con
 * `nit` cargado en `persons` y en `quotes`, la cotización leía el id del
 * atributo de la persona y devolvía null para un valor que sí estaba guardado.
 *
 * No era un problema teórico: apareció al segundo atributo con nombre repetido
 * del proyecto, y va a volver a aparecer con «ciudad» o «dirección».
 */
it('devuelve el atributo de la entidad que se le pide, no el primero que encuentra', function () {
    $repo = app(AttributeRepository::class);

    $persona = $repo->getAttributeByCode('nit', 'persons');
    $cotizacion = $repo->getAttributeByCode('nit', 'quotes');

    expect($persona)->not->toBeNull();
    expect($cotizacion)->not->toBeNull();
    expect($persona->entity_type)->toBe('persons');
    expect($cotizacion->entity_type)->toBe('quotes');
    expect($persona->id)->not->toBe($cotizacion->id);
});

it('la caché no le fija la respuesta de una entidad a la otra', function () {
    // Consultar primero personas no puede hacer que cotizaciones herede ese id
    // por el resto del request: la caché se indexa por entidad + código.
    $repo = app(AttributeRepository::class);

    $primero = $repo->getAttributeByCode('nit', 'persons');
    $segundo = $repo->getAttributeByCode('nit', 'quotes');
    $tercero = $repo->getAttributeByCode('nit', 'persons');

    expect($tercero->id)->toBe($primero->id);
    expect($segundo->id)->not->toBe($primero->id);
});

it('sin entidad se comporta como antes', function () {
    // Compatibilidad: el parámetro es opcional y nadie más lo pasa todavía.
    expect(app(AttributeRepository::class)->getAttributeByCode('sucursal'))
        ->not->toBeNull();
});
