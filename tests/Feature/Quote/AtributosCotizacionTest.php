<?php

use Webkul\Attribute\Repositories\AttributeRepository;

/**
 * Tarea 1 del formato RG-VCO-005: los campos de condiciones comerciales y de
 * cabecera existen como atributos, y el alta arranca con validez de 10 días y
 * forma de pago "Al contado".
 */
beforeEach(fn () => test()->actingAs(getDefaultAdmin()));

it('los seis atributos de la cotización existen con sus opciones', function () {
    $repo = app(AttributeRepository::class);

    foreach ([
        'forma_pago'     => ['select', ['Al contado', 'Crédito 15 días', 'Crédito 30 días']],
        'lugar_entrega'  => ['select', ['Puesto almacén cliente', 'Recoge en planta', 'A domicilio']],
        'tiempo_entrega' => ['text', []],
        'orden_compra'   => ['text', []],
        // La sucursal son los almacenes del módulo Warehouse, no una lista de
        // ciudades: eso último ahora es el atributo `ciudad`.
        'sucursal'       => ['lookup', []],
        'ciudad'         => ['select', null],
        'observaciones'  => ['textarea', []],
    ] as $code => [$tipo, $opciones]) {
        $a = $repo->getAttributeByCode($code, 'quotes');

        expect($a)->not->toBeNull("falta el atributo {$code}");
        expect($a->type)->toBe($tipo);
        expect((bool) $a->is_required)->toBeFalse("{$code} no puede ser obligatorio: rompería crear_cotizacion");

        // `null` = la lista la manda la configuración, no este test.
        expect($a->options->pluck('name')->all())->toBe($opciones ?? config('cotizacion.ciudades_cliente'));
    }
});

it('el alta muestra los campos nuevos y arranca con validez de 10 días y contado', function () {
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    foreach (['forma_pago', 'lugar_entrega', 'tiempo_entrega', 'orden_compra', 'sucursal', 'ciudad', 'observaciones'] as $code) {
        expect($html)->toContain("name=\"{$code}\"");
    }

    expect($html)->toContain('value="'.now()->addDays(10)->toDateString().'"');

    $contado = app(AttributeRepository::class)->getAttributeByCode('forma_pago', 'quotes')
        ->options->firstWhere('name', 'Al contado');

    expect($html)->toContain('label="Forma de pago" value="'.$contado->id.'"');
});

it('la validez viene de config y la comparten pantalla y agente', function () {
    expect(config('cotizacion.validez_dias'))->toBe(10);
    expect(config('cotizacion.documento.codigo'))->toBe('RG-VCO-005');
    expect(config('precios.cotizacion_vence_dias'))->toBeNull();
});
