<?php

use Webkul\Product\Models\ProductPrice;
use Webkul\Product\Repositories\ProductPriceRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * El camino que recorre de verdad el asesor: el formulario del CRM.
 *
 * Los tests de la capa de datos no ven un Blade roto ni un campo que el
 * repositorio descarta en silencio. Estos piden la página y postean el form.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Precios form '.uniqid(),
        'sku'         => 'TEST-PF-'.uniqid(),
        'price'       => 0,
    ]);
});

afterEach(function () {
    if (! isset($this->producto)) {
        return;
    }

    ProductPrice::where('product_id', $this->producto->id)->delete();

    $this->producto->newQuery()->whereKey($this->producto->id)->delete();
});

/** Lo que manda SIEMPRE la pantalla de edición completa. */
function formBase($producto): array
{
    return [
        'entity_type' => 'products',
        // El marcador que distingue el formulario completo de un guardado
        // parcial. Lo emite _precios.blade.php.
        'precios_enviados'         => 1,
        'caracteristicas_enviadas' => 1,
        'name'                     => $producto->name,
        'sku'                      => $producto->sku,
        'price'                    => $producto->price,
        'quantity'                 => 0,
    ];
}

it('la edición renderiza la tabla de precios', function () {
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->toContain('name="ejes_precio[]"')
        ->and($html)->toContain('precios-tabla')
        ->and($html)->toContain('precios-cargados');
});

it('guarda la lista completa desde el formulario', function () {
    test()->put(route('admin.products.update', $this->producto->id), formBase($this->producto) + [
        'ejes_precio' => ['Tamaño', 'Canal', ''],
        'precios'     => [
            ['valores' => ['Tamaño' => '35 L', 'Canal' => 'TRADICIONAL'], 'precio' => '120', 'unidad' => 'por bulto'],
            ['valores' => ['Tamaño' => '35 L', 'Canal' => 'HORECA'], 'precio' => '110,50'],
            // La fila en blanco que el formulario siempre muestra al final.
            ['valores' => ['Tamaño' => '', 'Canal' => ''], 'precio' => ''],
        ],
    ])->assertRedirect();

    $this->producto->refresh();

    expect($this->producto->ejes_precio)->toBe(['Tamaño', 'Canal']);

    $filas = ProductPrice::where('product_id', $this->producto->id)->orderBy('orden')->get();

    expect($filas)->toHaveCount(2);
    expect((float) $filas[1]->precio)->toBe(110.5);
    expect($filas[0]->unidad)->toBe('por bulto');
});

it('vaciar la tabla devuelve el producto a precio fijo', function () {
    app(ProductPriceRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Color' => 'Negro'], 'precio' => 260, 'orden' => 0],
    ]);

    test()->put(route('admin.products.update', $this->producto->id), formBase($this->producto) + [
        'ejes_precio' => ['Color'],
        'precios'     => [['valores' => ['Color' => ''], 'precio' => '']],
    ])->assertRedirect();

    $this->producto->refresh();

    // Los ejes se van con las filas: un producto que anuncia cotizarse por Color
    // y no tiene ningún color le hace prometer al agente una pregunta inútil.
    expect($this->producto->ejes_precio)->toBeNull();
    expect(ProductPrice::where('product_id', $this->producto->id)->count())->toBe(0);
});

it('borrar el producto se lleva su lista de precios', function () {
    app(ProductPriceRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Color' => 'Negro'], 'precio' => 260, 'orden' => 0],
    ]);

    $id = $this->producto->id;

    $this->producto->newQuery()->whereKey($id)->delete();

    unset($this->producto);

    expect(ProductPrice::where('product_id', $id)->count())->toBe(0);
});

it('un guardado parcial no borra la lista de precios ni las características', function () {
    /*
     * Regresión: la vista del producto tiene un editor inline de atributos que
     * postea a products.update con un puñado de campos —sin `precios` ni
     * `caracteristicas`—. Sin distinguir «no vino el campo» de «vino vacío»,
     * cambiar el SKU desde ahí borraba las 25 combinaciones de precio y todas
     * las características del producto, en silencio.
     */
    app(ProductPriceRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Color' => 'Negro'], 'precio' => 260, 'orden' => 0],
    ]);

    $this->producto->forceFill([
        'ejes_precio'     => ['Color'],
        'caracteristicas' => ['Micronaje' => '75 micrones'],
    ])->save();

    // Exactamente lo que manda el editor inline: sin marcador de formulario.
    test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->putJson(route('admin.products.update', $this->producto->id), [
            'entity_type' => 'products',
            'name'        => $this->producto->name,
            'sku'         => $this->producto->sku,
            'price'       => 15,
            'quantity'    => 0,
        ])->assertOk();

    $this->producto->refresh();

    expect(ProductPrice::where('product_id', $this->producto->id)->count())->toBe(1);
    expect($this->producto->ejes_precio)->toBe(['Color']);
    expect($this->producto->caracteristicas)->toBe(['Micronaje' => '75 micrones']);
    expect((float) $this->producto->price)->toBe(15.0);
});
