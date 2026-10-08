<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;

/*
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una
 * transacción, así lo que el test crea se deshace al terminar.
 *
 * Creaba atributos y sus valores sobre el catálogo real.
 */
uses(DatabaseTransactions::class);

it('muestra el atributo una sola vez en la vista de producto', function () {
    // Crear producto mínimo
    /*
     * SKU único por corrida: 'TEST-SKU-1' era fijo y quedó grabado en el
     * catálogo real en una corrida anterior (producto #81, del 22/09), así que
     * desde entonces el test chocaba contra su propia basura.
     */
    $product = Product::create([
        'name'        => 'Test Product '.uniqid(),
        'sku'         => 'TEST-SKU-'.uniqid(),
        'description' => 'Desc',
        'quantity'    => 5,
        'price'       => 10.5,
    ]);

    /*
     * Code y nombre únicos por corrida, por la misma razón que el SKU: el
     * atributo fijo `custom_attr_one` quedó grabado en producción el 01/09
     * (#79, con 3 valores) y desde entonces el test chocaba con él. Peor que
     * romper el test: un atributo de prueba le aparece en el formulario de
     * productos a cualquiera que entre.
     */
    $marca = 'Custom '.uniqid();

    $attributeId = DB::table('attributes')->insertGetId([
        'code'            => 'custom_attr_'.uniqid(),
        'name'            => $marca,
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
        // attribute_values no tiene timestamps en este esquema.
    ]);

    // Renderizar la sección de atributos
    $html = view('admin::products.view.attributes', ['product' => $product])->render();

    /*
     * Se cuenta la ETIQUETA renderizada, no el nombre suelto en el HTML.
     *
     * La versión anterior hacía substr_count($html, $nombre) y esperaba 1, pero
     * cada campo escribe su nombre tres veces: la etiqueta visible más el
     * `label=` y el `placeholder=` del componente de edición inline. O sea que
     * la aserción daba 3 para un campo que se muestra UNA vez, y habría dado 6
     * para uno realmente duplicado: no distinguía las dos cosas.
     *
     * Nunca se notó porque el test moría antes, chocando contra su propia
     * basura de corridas anteriores.
     */
    $etiqueta = fn (string $nombre) => substr_count($html, '>'.$nombre.'</div>');

    expect($etiqueta($marca))->toBe(1);

    // Verifica que atributos por defecto no se dupliquen
    expect($etiqueta(__('installer::app.seeders.attributes.products.price')))->toBeLessThanOrEqual(1);
    expect($etiqueta(__('installer::app.seeders.attributes.products.quantity')))->toBeLessThanOrEqual(1);
    expect($etiqueta(__('installer::app.seeders.attributes.products.sku')))->toBeLessThanOrEqual(1);
});
