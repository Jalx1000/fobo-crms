<?php

use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;


/*
| PELIGRO: este archivo escribe sin transacción. Los tests corren contra la
| base de producción (no hay .env.testing), y el 16/10/2026 dejó datos de
| prueba en producción. Solo corre contra una base de prueba (nombre que
| termine en «_test» o «_testing»).
*/
beforeEach(function () {
    if (! preg_match('/_test(ing)?$/', (string) config('database.connections.'.config('database.default').'.database'))) {
        $this->markTestSkipped('Escribe sin transacción: solo contra una base de prueba.');
    }
});

it('muestra el atributo una sola vez en la vista de producto', function () {
    // Crear producto mínimo
    $product = Product::create([
        'name'        => 'Test Product',
        'sku'         => 'TEST-SKU-1',
        'description' => 'Desc',
        'quantity'    => 5,
        'price'       => 10.5,
    ]);

    // Asegurar un atributo custom para products
    $attributeId = DB::table('attributes')->insertGetId([
        'code'            => 'custom_attr_one',
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
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    // Renderizar la sección de atributos
    $html = view('admin::products.view.attributes', ['product' => $product])->render();

    // Verifica que el atributo custom se muestre exactamente una vez
    expect(substr_count($html, 'Custom One'))
        ->toBe(1);

    // Verifica que atributos por defecto no se dupliquen
    expect(substr_count($html, __('installer::app.seeders.attributes.products.price')) <= 1)
        ->toBeTrue();
    expect(substr_count($html, __('installer::app.seeders.attributes.products.quantity')) <= 1)
        ->toBeTrue();
    expect(substr_count($html, __('installer::app.seeders.attributes.products.sku')) <= 1)
        ->toBeTrue();
});
