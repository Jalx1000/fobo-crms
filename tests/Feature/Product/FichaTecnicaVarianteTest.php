<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Product\Models\Product;
use Webkul\Product\Support\FichaTecnica;

/**
 * La ficha técnica vive en la VARIANTE, no en el producto.
 *
 * `products.caracteristicas` es del producto entero, y hay fichas que cambian
 * con la variante: el stretch film manual y el automático comparten ancho y
 * espesor, y difieren en peso, estiramiento, tipo de cono y aplicación. Con un
 * solo juego por producto había que elegir cuál de las dos era «la» ficha.
 */
uses(DatabaseTransactions::class);

it('una línea con dos puntos es un dato y una sin ellos es un encabezado', function () {
    $ficha = FichaTecnica::desdeTexto("Dimensiones del rollo\nColor: Transparente\nAncho: 500 mm ±5 mm");

    expect($ficha)->toBe([
        ['clave' => 'Dimensiones del rollo', 'valor' => null],
        ['clave' => 'Color',                 'valor' => 'Transparente'],
        ['clave' => 'Ancho',                 'valor' => '500 mm ±5 mm'],
    ]);
});

it('solo parte en el primer dos puntos', function () {
    // «Longitud: 510 mm ± 1 mm» y una hora («Entrega: 8:00») no se parten más.
    expect(FichaTecnica::desdeTexto('Entrega: 8:00 a 12:00'))
        ->toBe([['clave' => 'Entrega', 'valor' => '8:00 a 12:00']]);
});

it('ida y vuelta: lo que se carga es lo que se vuelve a ver en el formulario', function () {
    $texto = "Dimensiones del cono (tubo)\nTipo de cono: Cartón 6 mm\nDiámetro: 77,4 mm ±0,4 mm";

    expect(FichaTecnica::aTexto(FichaTecnica::desdeTexto($texto)))->toBe($texto);
});

it('el resumen deja fuera los encabezados, que sin saltos de línea no separan nada', function () {
    $ficha = FichaTecnica::desdeTexto("Dimensiones del rollo\nColor: Transparente\nAncho: 500 mm");

    expect(FichaTecnica::resumen($ficha))->toBe('Color: Transparente · Ancho: 500 mm');
});

it('las dos variantes de stretch film tienen su medida y su propia ficha', function () {
    $producto = Product::where('sku', 'CM_00001')->with('variantes')->first();

    $por = fn (string $presentacion) => $producto->variantes->first(
        fn ($v) => (((array) $v->valores)['Presentación'] ?? null) === $presentacion
    );

    $manual = $por('Manual 4,7 kg');
    $automatico = $por('Automático 15,8 kg');

    expect($manual->medida)->toBe('500 mm × 370 m')
        ->and($automatico->medida)->toBe('500 mm');

    // Lo que las distingue: el manual lleva longitud y estira 150-200 %; el
    // automático no trae longitud y estira 250 %.
    $fichaManual = FichaTecnica::resumen($manual->caracteristicas);
    $fichaAuto = FichaTecnica::resumen($automatico->caracteristicas);

    expect($fichaManual)->toContain('Longitud envolvente: 370 m')
        ->and($fichaManual)->toContain('Estiramiento mínimo: 150 a 200 %')
        ->and($fichaManual)->toContain('Tipo de cono: Cartón 6 mm');

    expect($fichaAuto)->not->toContain('Longitud envolvente')
        ->and($fichaAuto)->toContain('Estiramiento mínimo: 250 %')
        ->and($fichaAuto)->toContain('Tipo de cono: Cartón 8 mm');
});

it('el buscador de la cotización entrega medida y ficha de cada variante', function () {
    test()->actingAs(getDefaultAdmin());

    $data = test()->get(route('admin.products.search', ['query' => 'stretch']))
        ->assertOk()
        ->json('data.0.variantes');

    expect(collect($data)->pluck('medida')->all())->toBe(['500 mm × 370 m', '500 mm']);

    foreach ($data as $variante) {
        expect($variante['ficha'])->toContain('Color: Transparente')
            ->and($variante['caracteristicas'])->not->toBeEmpty();
    }
});

it('el formulario del producto ofrece la ficha por variante', function () {
    test()->actingAs(getDefaultAdmin());

    $producto = Product::where('sku', 'CM_00001')->first();

    $html = test()->get(route('admin.products.edit', $producto->id))->assertOk()->getContent();

    expect($html)->toContain('Ficha técnica')
        ->and($html)->toContain('[caracteristicas]')
        // Se edita como textarea: la ficha es multilínea.
        ->and($html)->toContain('<textarea')
        ->and($html)->toContain('Estiramiento mínimo: 150 a 200 %');
});
