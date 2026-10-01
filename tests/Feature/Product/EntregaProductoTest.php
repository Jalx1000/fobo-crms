<?php

use Webkul\Product\Repositories\ProductRepository;

/**
 * Tarea 2 del formato RG-VCO-005: las condiciones de entrega viven en el
 * producto y se editan desde su formulario.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Entrega '.uniqid(),
        'sku'         => 'TEST-ENT-'.uniqid(),
        'price'       => 1,
        'quantity'    => 0,
    ]);
});

afterEach(function () {
    if (isset($this->producto)) {
        $this->producto->newQuery()->whereKey($this->producto->id)->delete();
    }
});

function formProducto($p, array $extra = []): array
{
    return array_merge([
        'entity_type'              => 'products',
        'name'                     => $p->name,
        'sku'                      => $p->sku,
        'price'                    => 1,
        'quantity'                 => 0,
        'precios_enviados'         => 1,
        'caracteristicas_enviadas' => 1,
    ], $extra);
}

it('arranca con los defaults de la migración', function () {
    expect($this->producto->refresh()->tiempo_entrega)->toBe('Inmediata')
        ->and($this->producto->lugar_entrega)->toBe('Puesto almacén cliente')
        ->and($this->producto->entrega_domicilio_desde)->toBeNull();
});

it('guarda las condiciones desde el formulario', function () {
    test()->put(route('admin.products.update', $this->producto->id), formProducto($this->producto, [
        'tiempo_entrega'          => '20 días',
        'lugar_entrega'           => 'Recoge en planta',
        'entrega_domicilio_desde' => '50',
    ]))->assertRedirect();

    $this->producto->refresh();

    expect($this->producto->tiempo_entrega)->toBe('20 días')
        ->and($this->producto->lugar_entrega)->toBe('Recoge en planta')
        ->and($this->producto->entrega_domicilio_desde)->toBe(50);
});

it('vacío en "a domicilio desde" es null, no cero', function () {
    // null = nunca va a domicilio; 0 significaría siempre, que es lo contrario.
    test()->put(route('admin.products.update', $this->producto->id), formProducto($this->producto, [
        'tiempo_entrega'          => 'Inmediata',
        'lugar_entrega'           => 'Puesto almacén cliente',
        'entrega_domicilio_desde' => '',
    ]))->assertRedirect();

    expect($this->producto->refresh()->entrega_domicilio_desde)->toBeNull();
});

it('un guardado parcial no toca la entrega', function () {
    // El editor inline de atributos postea sin estos campos: no puede resetearlos.
    $this->producto->forceFill(['tiempo_entrega' => '20 días', 'entrega_domicilio_desde' => 5])->save();

    test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->putJson(route('admin.products.update', $this->producto->id), [
            'entity_type' => 'products', 'name' => $this->producto->name, 'sku' => $this->producto->sku, 'price' => 2, 'quantity' => 0,
        ])->assertOk();

    $this->producto->refresh();

    expect($this->producto->tiempo_entrega)->toBe('20 días')
        ->and($this->producto->entrega_domicilio_desde)->toBe(5);
});

it('el formulario de edición muestra el bloque', function () {
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->toContain('name="tiempo_entrega"')
        ->and($html)->toContain('name="lugar_entrega"')
        ->and($html)->toContain('name="entrega_domicilio_desde"');
});
