<?php

use Webkul\Product\Support\Caracteristicas;

/**
 * Traducción entre el formulario y la columna JSON `caracteristicas`.
 *
 * Son tests sin base: `Caracteristicas` es lógica pura sobre arrays, y es donde
 * viven los modos de falla que importan — filas vacías que ensucian el JSON y
 * categorías múltiples que dejan campos inalcanzables.
 */
beforeEach(function () {
    config()->set('caracteristicas', [
        'comunes' => [
            'tiempo_produccion' => ['label' => 'Tiempo de producción'],
        ],
        'por_categoria' => [
            'division-de-embalajes' => [
                'gramaje' => ['label' => 'Gramaje', 'sufijo' => 'g/m²'],
            ],
            'gran-formato' => [
                'ancho_maximo' => ['label' => 'Ancho máximo'],
            ],
        ],
    ]);
});

it('ofrece solo las comunes cuando la categoría no tiene mapa propio', function () {
    $campos = Caracteristicas::paraCategorias(['Categoría Inventada']);

    expect($campos)->toHaveKey('tiempo_produccion')
        ->and($campos)->not->toHaveKey('gramaje');
});

it('suma las características de la categoría a las comunes', function () {
    $campos = Caracteristicas::paraCategorias(['División de Embalajes']);

    expect($campos)->toHaveKeys(['tiempo_produccion', 'gramaje']);
});

it('ofrece la unión cuando el producto está en dos categorías', function () {
    // product_type es un multiselect: ofrecer solo la primera categoría dejaría
    // campos inalcanzables sin que nadie entienda por qué.
    $campos = Caracteristicas::paraCategorias(['División de Embalajes', 'Gran Formato']);

    expect($campos)->toHaveKeys(['tiempo_produccion', 'gramaje', 'ancho_maximo']);
});

it('normaliza los arrays paralelos del formulario a un mapa', function () {
    $salida = Caracteristicas::normalizar([
        'clave' => ['gramaje', 'material'],
        'valor' => ['300 g/m²', 'Cartulina'],
    ]);

    expect($salida)->toBe(['gramaje' => '300 g/m²', 'material' => 'Cartulina']);
});

it('descarta la fila vacía que el formulario deja siempre al final', function () {
    // Sin esto se guarda un {"": ""} que después aparece en la ficha como una
    // característica sin nombre.
    $salida = Caracteristicas::normalizar([
        'clave' => ['gramaje', ''],
        'valor' => ['300 g/m²', ''],
    ]);

    expect($salida)->toBe(['gramaje' => '300 g/m²']);
});

it('descarta una clave sin valor y un valor sin clave', function () {
    $salida = Caracteristicas::normalizar([
        'clave' => ['gramaje', 'material', ''],
        'valor' => ['300 g/m²', '', 'huérfano'],
    ]);

    expect($salida)->toBe(['gramaje' => '300 g/m²']);
});

it('devuelve null cuando no quedó ninguna característica', function () {
    // null y no []: la columna es nullable y un array vacío guardaría "[]",
    // que en JSON no distingue de un objeto vacío al releerlo.
    expect(Caracteristicas::normalizar(['clave' => [''], 'valor' => ['']]))->toBeNull()
        ->and(Caracteristicas::normalizar(null))->toBeNull();
});

it('recorta los espacios alrededor de clave y valor', function () {
    $salida = Caracteristicas::normalizar([
        'clave' => ['  gramaje  '],
        'valor' => ['  300 g/m²  '],
    ]);

    expect($salida)->toBe(['gramaje' => '300 g/m²']);
});

it('usa la etiqueta de la config y cae al nombre de la clave si es libre', function () {
    $campos = Caracteristicas::paraCategorias(['División de Embalajes']);

    expect(Caracteristicas::etiqueta('gramaje', $campos))->toBe('Gramaje')
        // Una clave que el asesor inventó no está en la config: se muestra
        // legible en vez de cruda.
        ->and(Caracteristicas::etiqueta('color_pantone', $campos))->toBe('Color Pantone');
});
