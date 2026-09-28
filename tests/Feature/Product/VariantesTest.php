<?php

use Webkul\Product\Models\ProductVariante;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ProductVarianteRepository;
use Webkul\Product\Support\Variantes;

/**
 * Tarea 3 del formato RG-VCO-005: las variantes y su resolución.
 *
 * Lo que se protege: que la variante se encuentre mirando SOLO los ejes de
 * identidad (en bolsas el TR4 no depende del canal ni la zona), con la misma
 * tolerancia que el precio, y que no exista nunca sea un null y no un error.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type'    => 'products',
        'name'           => 'Variantes '.uniqid(),
        'sku'            => 'TEST-VAR-'.uniqid(),
        'price'          => 0,
        'quantity'       => 0,
        'ejes_precio'    => ['Tamaño', 'Canal', 'Zona'],
        'ejes_identidad' => ['Tamaño'],
    ]);

    app(ProductVarianteRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Tamaño' => '35 L'], 'codigo_tr4' => '020117-0044', 'codigo_barra' => '7779970880051', 'medida' => '(60×63) cm', 'unidad' => 'PZA', 'orden' => 0],
        ['valores' => ['Tamaño' => '50 L'], 'codigo_tr4' => '020117-0035', 'codigo_barra' => '7779970880037', 'medida' => '(65×80) cm', 'unidad' => 'PZA', 'orden' => 1],
        ['valores' => ['Tamaño' => '75 L'], 'codigo_tr4' => null, 'orden' => 2], // sin código todavía
    ]);

    $this->producto = $this->producto->fresh(['variantes']);
});

afterEach(function () {
    if (isset($this->producto)) {
        $this->producto->newQuery()->whereKey($this->producto->id)->delete();
    }
});

it('resuelve mirando solo los ejes de identidad e ignora canal y zona', function () {
    $v = Variantes::resolver($this->producto, ['Tamaño' => '50 L', 'Canal' => 'HORECA', 'Zona' => 'Santa Cruz']);

    expect($v)->not->toBeNull();
    expect($v->codigo_tr4)->toBe('020117-0035');
    expect($v->foto())->toMatchArray([
        'codigo_tr4'   => '020117-0035',
        'codigo_barra' => '7779970880037',
        'medida'       => '(65×80) cm',
        'unidad'       => 'PZA',
        'variante'     => ['Tamaño' => '50 L'],
    ]);
});

it('tolera acentos, mayúsculas y espacios como el precio', function () {
    // El agente manda lo que el modelo generó; la misma elección no puede
    // tener precio y no tener código.
    expect(Variantes::resolver($this->producto, ['tamano' => '50l'])?->codigo_tr4)->toBe('020117-0035');
});

it('devuelve null si falta un eje de identidad, en vez de adivinar', function () {
    expect(Variantes::resolver($this->producto, ['Canal' => 'HORECA']))->toBeNull();
});

it('devuelve null si la variante no existe', function () {
    expect(Variantes::resolver($this->producto, ['Tamaño' => '999 L']))->toBeNull();
});

it('una variante sin TR4 se resuelve igual, con el código vacío', function () {
    // Bloquearla dejaría sin cotizar a todo producto al que todavía no le
    // cargaron los códigos.
    $v = Variantes::resolver($this->producto, ['Tamaño' => '75 L']);

    expect($v)->not->toBeNull();
    expect($v->codigo_tr4)->toBeNull();
    expect($v->foto()['unidad'])->toBe('PZA');
});

it('un producto sin ejes de identidad no tiene variantes que resolver', function () {
    $this->producto->forceFill(['ejes_identidad' => null])->save();

    expect(Variantes::resolver($this->producto->fresh(['variantes']), ['Tamaño' => '50 L']))->toBeNull();
});

it('borrar el producto se lleva sus variantes', function () {
    $id = $this->producto->id;

    $this->producto->newQuery()->whereKey($id)->delete();
    unset($this->producto);

    expect(ProductVariante::where('product_id', $id)->count())->toBe(0);
});

it('normalizar descarta la fila vacía pero conserva la que no tiene TR4', function () {
    $filas = Variantes::normalizarFilas([
        ['valores' => ['Tamaño' => '35 L'], 'codigo_tr4' => '020117-0044'],
        ['valores' => ['Tamaño' => '50 L'], 'codigo_tr4' => ''],
        ['valores' => ['Tamaño' => ''], 'codigo_tr4' => '999'],
    ], ['Tamaño']);

    expect($filas)->toHaveCount(2);
    expect($filas[1]['codigo_tr4'])->toBeNull();
    expect($filas[1]['unidad'])->toBe('PZA');
});

it('los ejes de identidad tienen que ser ejes de precio', function () {
    // Un eje de identidad que no es eje de precio no tendría de dónde tomar su
    // valor al cotizar.
    expect(Variantes::normalizarEjes(['Tamaño', 'Color', ''], ['Tamaño', 'Canal']))->toBe(['Tamaño']);
    expect(Variantes::normalizarEjes(['Color'], ['Tamaño', 'Canal']))->toBeNull();
});

it('la etiqueta sigue el orden de los ejes, no el de MySQL', function () {
    $v = $this->producto->variantes->first();

    expect(Variantes::etiqueta($this->producto, $v))->toBe('35 L');
});
