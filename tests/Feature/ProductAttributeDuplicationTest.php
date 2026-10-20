<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;

/*
 * Dentro de una transacción: la suite corre contra la base REAL.
 *
 * Sin esto, cada corrida dejaba filas permanentes. Medido: 17 personas "Test
 * Person", 7 "Export City Prospect" y 3 pipelines "Bogotá Export" acumulados en
 * producción, que además aparecían en los selectores del Punto de Distribuidor y
 * en el ruteo de derivaciones de WhatsApp.
 */
uses(DatabaseTransactions::class);

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
        // Sin timestamps: attribute_values no los tiene en este esquema.
    ]);

    // Renderizar la sección de atributos
    $html = view('admin::products.view.attributes', ['product' => $product])->render();

    /*
     * Verifica que el atributo custom se muestre exactamente una vez.
     *
     * Se cuenta la ETIQUETA visible y no el texto suelto: el mismo campo repite su
     * nombre en los atributos `label="…"` y `placeholder="…"` del editor en línea, así
     * que contar "Custom One" daba 3 para un único campo en pantalla.
     */
    expect(substr_count($html, '>Custom One</div>'))
        ->toBe(1);

    // Verifica que atributos por defecto no se dupliquen
    // Lo mismo para los atributos por defecto: etiquetas visibles, no texto suelto.
    foreach (['price', 'quantity', 'sku'] as $codigo) {
        $etiqueta = '>'.__('installer::app.seeders.attributes.products.'.$codigo).'</div>';

        expect(substr_count($html, $etiqueta))->toBeLessThanOrEqual(1);
    }
});
