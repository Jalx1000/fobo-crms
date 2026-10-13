<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Los atributos custom en /api/v1/products.
 *
 * El endpoint lo sirve krayin/rest-api y su ProductResource devuelve ocho campos fijos.
 * El controlador usa esa clase por NOMBRE, así que no se puede sustituir por el
 * contenedor, y el provider del paquete se autodescubre —Laravel carga los providers de
 * paquetes antes que los de config/app.php— así que tampoco se gana la ruta registrando
 * otra. De ahí el middleware.
 *
 * El problema concreto: hay dos productos con el MISMO nombre y lo único que los
 * distingue es de qué depósito son, que vive en `ciudad_producto_sucursal`.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    // El REST API usa el guard sanctum.
    $this->actingAs(getDefaultAdmin(), 'sanctum');
});

/** El primer producto de la lista que tenga valores de atributos cargados. */
function productoConAtributos(): ?int
{
    return DB::table('attribute_values as v')
        ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
        ->where('v.entity_type', 'products')
        ->where('a.code', 'ciudad_producto_sucursal')
        ->whereNotNull('v.text_value')
        ->where('v.text_value', '!=', '')
        ->value('v.entity_id');
}

it('la lista devuelve los atributos custom', function () {
    $r = $this->getJson('/api/v1/products?limit=50')->assertOk()->json('data');

    expect($r)->not->toBeEmpty();

    $p = collect($r)->firstWhere('id', productoConAtributos());

    expect($p)->not->toBeNull()
        ->and($p)->toHaveKey('ciudad_producto_sucursal')
        ->and($p)->toHaveKey('product_type')
        ->and($p)->toHaveKey('products');
});

it('el detalle devuelve los atributos custom', function () {
    $id = productoConAtributos();

    if (! $id) {
        $this->markTestSkipped('Ningún producto tiene la ciudad cargada.');
    }

    $p = $this->getJson("/api/v1/products/{$id}")->assertOk()->json('data');

    expect($p)->toHaveKey('ciudad_producto_sucursal')
        ->and($p['ciudad_producto_sucursal'])->toBeArray();
});

it('el buscador devuelve los atributos custom', function () {
    $nombre = (string) DB::table('products')->value('name');

    $r = $this->getJson('/api/v1/products/search?query='.urlencode(mb_substr($nombre, 0, 6)))
        ->assertOk()
        ->json('data');

    expect($r)->not->toBeEmpty()
        ->and($r[0])->toHaveKey('ciudad_producto_sucursal');
});

it('los ids de lookup vienen con su etiqueta al lado', function () {
    $id = productoConAtributos();

    if (! $id) {
        $this->markTestSkipped('Ningún producto tiene la ciudad cargada.');
    }

    $p = $this->getJson("/api/v1/products/{$id}")->assertOk()->json('data');

    /*
     * Un "[1,9]" suelto no le dice nada a nadie: el consumidor tendría que conocer los ids
     * de los depósitos. La etiqueta va en una clave HERMANA para no cambiar el tipo del
     * valor original, que sigue siendo ids por si hay que filtrar.
     */
    expect($p)->toHaveKey('ciudad_producto_sucursal_labels')
        ->and($p['ciudad_producto_sucursal_labels'])->toBeArray()
        ->and($p['ciudad_producto_sucursal_labels'][0] ?? null)->toBeString();
});

it('un select trae el id y el nombre de la opcion', function () {
    $id = productoConAtributos();

    if (! $id) {
        $this->markTestSkipped('Ningún producto tiene la ciudad cargada.');
    }

    $p = $this->getJson("/api/v1/products/{$id}")->assertOk()->json('data');

    // product_type llega como 10; sin la etiqueta nadie sabe que es "Productos Sensia".
    expect($p['product_type'])->toBeInt()
        ->and($p['product_type_label'])->toBeString();
});

it('los campos del resource original no se pisan', function () {
    $id = productoConAtributos();

    if (! $id) {
        $this->markTestSkipped('Ningún producto tiene la ciudad cargada.');
    }

    $p = $this->getJson("/api/v1/products/{$id}")->assertOk()->json('data');
    $crudo = DB::table('products')->where('id', $id)->first();

    /*
     * En Krayin `name`, `sku`, `price`, `quantity` y `description` TAMBIÉN son atributos
     * custom. Si el middleware los sobreescribiera, el precio pasaría del formato del
     * Resource ("7500.0000") al del atributo, y cambiaría la respuesta para quien ya la
     * consume.
     */
    expect($p['name'])->toBe($crudo->name)
        ->and($p['sku'])->toBe($crudo->sku)
        ->and((string) $p['price'])->toBe((string) $crudo->price);
});

it('los dos productos del mismo nombre se distinguen por su zona', function () {
    $repetido = DB::table('products')
        ->select('name')
        ->groupBy('name')
        ->havingRaw('count(*) > 1')
        ->value('name');

    if (! $repetido) {
        $this->markTestSkipped('No hay dos productos con el mismo nombre.');
    }

    $r = collect($this->getJson('/api/v1/products?limit=100')->assertOk()->json('data'))
        ->where('name', $repetido);

    /*
     * Es el caso que motivó todo esto: dos "Botellón de agua purificada 20 L", uno a $7.500
     * y otro a $8.000, y por API no había forma de saber cuál era de qué zona.
     */
    expect($r)->toHaveCount(2);

    foreach ($r as $p) {
        expect($p)->toHaveKey('ciudad_producto_sucursal_labels');
    }

    // Y las zonas tienen que ser distintas, que es lo que los hace distinguibles.
    expect($r->pluck('ciudad_producto_sucursal')->unique()->count())->toBeGreaterThan(1);
});

it('el sub-recurso de depositos queda intacto', function () {
    $id = (int) DB::table('products')->value('id');

    // El middleware sólo toca los tres endpoints de productos: /warehouses no devuelve
    // productos, y enriquecerlo sería corromper otra respuesta.
    $r = $this->getJson("/api/v1/products/{$id}/warehouses")->assertOk()->json();

    expect($r)->not->toHaveKey('ciudad_producto_sucursal');
});
