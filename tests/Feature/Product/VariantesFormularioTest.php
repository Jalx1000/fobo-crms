<?php

use Webkul\Product\Models\ProductVariante;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ProductVarianteRepository;

/**
 * Tarea 4 del formato RG-VCO-005: el formulario de variantes en el producto.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Form variantes '.uniqid(),
        'sku'         => 'TEST-VF-'.uniqid(),
        'price'       => 0,
        'quantity'    => 0,
        'ejes_precio' => ['Tamaño', 'Canal'],
    ]);
});

afterEach(function () {
    if (isset($this->producto)) {
        $this->producto->newQuery()->whereKey($this->producto->id)->delete();
    }
});

function formConVariantes($p, array $extra = []): array
{
    return array_merge([
        'entity_type'              => 'products',
        'name'                     => $p->name,
        'sku'                      => $p->sku,
        'price'                    => 0,
        'quantity'                 => 0,
        'precios_enviados'         => 1,
        'caracteristicas_enviadas' => 1,
        'variantes_enviadas'       => 1,
        'ejes_precio'              => ['Tamaño', 'Canal'],
        'precios'                  => [['valores' => ['Tamaño' => '35 L', 'Canal' => 'HORECA'], 'precio' => '7.5']],
    ], $extra);
}

it('guarda ejes de identidad y variantes desde el formulario', function () {
    test()->put(route('admin.products.update', $this->producto->id), formConVariantes($this->producto, [
        'ejes_identidad' => ['Tamaño'],
        'variantes'      => [
            ['valores' => ['Tamaño' => '35 L'], 'codigo_tr4' => '020117-0044', 'codigo_barra' => '7779970880051', 'medida' => '(60×63) cm', 'unidad' => 'PZA', 'nombre_erp' => 'BSA 35L'],
            ['valores' => ['Tamaño' => '50 L'], 'codigo_tr4' => '020117-0035', 'unidad' => ''],
            ['valores' => ['Tamaño' => ''], 'codigo_tr4' => 'basura'],
        ],
    ]))->assertRedirect();

    $this->producto->refresh();

    expect($this->producto->ejes_identidad)->toBe(['Tamaño']);

    $v = ProductVariante::where('product_id', $this->producto->id)->orderBy('orden')->get();

    expect($v)->toHaveCount(2);
    expect($v[0]->codigo_tr4)->toBe('020117-0044');
    expect($v[0]->nombre_erp)->toBe('BSA 35L');
    expect($v[1]->unidad)->toBe('PZA'); // vacío → PZA
});

it('un eje de identidad que no es eje de precio se descarta', function () {
    test()->put(route('admin.products.update', $this->producto->id), formConVariantes($this->producto, [
        'ejes_identidad' => ['Color'],
        'variantes'      => [['valores' => ['Color' => 'Rojo'], 'codigo_tr4' => 'x']],
    ]))->assertRedirect();

    expect($this->producto->refresh()->ejes_identidad)->toBeNull();
    expect(ProductVariante::where('product_id', $this->producto->id)->count())->toBe(0);
});

it('un guardado parcial no borra las variantes', function () {
    app(ProductVarianteRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Tamaño' => '35 L'], 'codigo_tr4' => '020117-0044', 'orden' => 0],
    ]);
    $this->producto->forceFill(['ejes_identidad' => ['Tamaño']])->save();

    test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->putJson(route('admin.products.update', $this->producto->id), [
            'entity_type' => 'products', 'name' => $this->producto->name, 'sku' => $this->producto->sku, 'price' => 1, 'quantity' => 0,
        ])->assertOk();

    expect(ProductVariante::where('product_id', $this->producto->id)->count())->toBe(1);
    expect($this->producto->refresh()->ejes_identidad)->toBe(['Tamaño']);
});

it('la edición renderiza la tabla con las variantes cargadas, server-side', function () {
    app(ProductVarianteRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Tamaño' => '35 L'], 'codigo_tr4' => '020117-0044', 'orden' => 0],
    ]);
    $this->producto->forceFill(['ejes_identidad' => ['Tamaño']])->save();

    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->toContain('name="variantes_enviadas"')
        ->and($html)->toContain('name="variantes[0][valores][Tamaño]"')
        ->and($html)->toContain('value="020117-0044"')
        ->and($html)->toContain('name="ejes_identidad[]"');

    // El script llega entero (ninguna directiva Blade lo corta).
    $pos = strpos($html, 'const cuerpo   = () => document.getElementById(\'variantes-filas\')');
    expect($pos)->not->toBeFalse();
    $apertura = strrpos(substr($html, 0, $pos), '<script');
    $cierre = strrpos(substr($html, 0, $pos), '</script>');
    expect($apertura)->toBeGreaterThan($cierre === false ? -1 : $cierre);
});
