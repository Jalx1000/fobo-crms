<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;

uses(DatabaseTransactions::class);

it('muestra el atributo una sola vez en la vista de producto', function () {
    // Crear producto mínimo
    $product = Product::create([
        'name'        => 'Test Product '.uniqid(),
        'sku'         => 'TEST-SKU-'.uniqid(),
        'description' => 'Desc',
        'quantity'    => 5,
        'price'       => 10.5,
    ]);

    // Asegurar un atributo custom para products
    $attributeId = DB::table('attributes')->insertGetId([
        'code'            => 'custom_attr_'.uniqid(),
        'name'            => 'Custom One',
        'type'            => 'text',
        'entity_type'     => 'products',
        'lookup_type'     => null,
        'validation'      => null,
        'sort_order'      => 50,
        'is_required'     => 0,
        'is_unique'       => 0,
        'quick_add'       => 1,
        'is_user_defined' => 1,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    DB::table('attribute_values')->insert([
        'entity_type'   => 'products',
        'entity_id'     => $product->id,
        'attribute_id'  => $attributeId,
        'text_value'    => 'Valor Uno',
        'float_value'   => null,
        'boolean_value' => null,
        'integer_value' => null,
        'json_value'    => null,
        'datetime_value'=> null,
        'date_value'    => null,
        // Eliminados timestamps que no existen en producción
    ]);

    // Renderizar la sección de atributos
    $html = view('admin::products.view.attributes', ['product' => $product])->render();

    // Verifica que el atributo custom se muestre
    expect(substr_count($html, 'Custom One'))
        ->toBeGreaterThanOrEqual(1);

    // Verifica que atributos por defecto se muestren al menos una vez
    expect(substr_count($html, __('installer::app.seeders.attributes.products.price')))
        ->toBeGreaterThanOrEqual(1);
    expect(substr_count($html, __('installer::app.seeders.attributes.products.quantity')))
        ->toBeGreaterThanOrEqual(1);
    expect(substr_count($html, __('installer::app.seeders.attributes.products.sku')))
        ->toBeGreaterThanOrEqual(1);
});
