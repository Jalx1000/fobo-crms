<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Webkul\User\Models\User;

/*
 * Dentro de una transacción: la suite corre contra la base REAL.
 *
 * Sin esto, cada corrida dejaba filas permanentes. Medido: 17 personas "Test
 * Person", 7 "Export City Prospect" y 3 pipelines "Bogotá Export" acumulados en
 * producción, que además aparecían en los selectores del Punto de Distribuidor y
 * en el ruteo de derivaciones de WhatsApp.
 */
uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| Estos tests necesitan una tabla `products` vacía
|--------------------------------------------------------------------------
| Afirman totales absolutos sobre toda la tabla —"la respuesta trae 2
| productos", "el total es 5"— y el endpoint además incluye siempre los
| productos con valor 10. Contra la base real, con 29 productos cargados, esos
| asserts no pueden cerrar: por eso la versión anterior vaciaba la tabla antes
| de cada caso.
|
| Vaciar producción para que un assert pase no es una opción. Mientras la suite
| apunte a la base real, estos casos se saltean. Recuperan sentido en cuanto
| exista una base descartable (DB_DATABASE en phpunit.xml).
|
| Los tres primeros casos —auth y validación de parámetros— no tocan datos y
| siguen corriendo siempre.
*/

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function setupCiudadAtribute(): int
{
    $attrId = DB::table('attributes')->insertGetId([
        'code'            => 'ciudad_producto_sucursal',
        'name'            => 'Ciudad Producto Sucursal',
        'type'            => 'lookup',
        'entity_type'     => 'products',
        'is_required'     => 0,
        'is_unique'       => 0,
        'quick_add'       => 0,
        'is_user_defined' => 1,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $GLOBALS['__atributosDePrueba'][] = $attrId;

    return $attrId;
}

function createProduct(string $sku, string $name, float $price = 10.00): int
{
    $id = DB::table('products')->insertGetId([
        'sku'        => $sku,
        'name'       => $name,
        'price'      => $price,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Se registra para que el teardown borre exactamente esto y nada más.
    $GLOBALS['__productosDePrueba'][] = $id;

    return $id;
}

function assignCiudadValue(int $productId, int $attrId, int $value): void
{
    DB::table('attribute_values')->insert([
        'entity_id'     => $productId,
        'entity_type'   => 'products',
        'attribute_id'  => $attrId,
        'integer_value' => $value,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
}

/**
 * Borra SOLO lo que estos tests crearon.
 *
 * La versión anterior hacía `truncate` de `products` y borraba todos los
 * `attribute_values` de productos y el atributo `ciudad_producto_sucursal`
 * entero. Contra la base real de kohlberg eso son 29 productos, 319 valores de
 * atributo y la definición del atributo que usa el endpoint en producción.
 */
/**
 * Puerta de entrada de los casos que dependen del estado de la tabla.
 *
 * Contra la base real se saltean: sus asserts son totales absolutos sobre toda
 * la tabla `products`, y hacerlos pasar exigiría vaciarla. Contra una base
 * descartable corren normalmente, dejándola limpia primero.
 */
function requiereTablaDeProductosVacia(): void
{
    if (apuntaAProduccion()) {
        test()->markTestSkipped(
            'Necesita una tabla `products` vacía y la suite apunta a la base real. '.
            'Configurá DB_DATABASE en phpunit.xml para correrlo.'
        );
    }

    teardownProductTables();
}

function teardownProductTables(): void
{
    $productos = $GLOBALS['__productosDePrueba'] ?? [];
    $atributos = $GLOBALS['__atributosDePrueba'] ?? [];

    if ($productos) {
        DB::table('attribute_values')
            ->where('entity_type', 'products')
            ->whereIn('entity_id', $productos)
            ->delete();

        DB::table('products')->whereIn('id', $productos)->delete();
    }

    if ($atributos) {
        DB::table('attribute_values')->whereIn('attribute_id', $atributos)->delete();
        DB::table('attributes')->whereIn('id', $atributos)->delete();
    }

    $GLOBALS['__productosDePrueba'] = [];
    $GLOBALS['__atributosDePrueba'] = [];
}

function authenticatedUser(): User
{
    $user = User::factory()->create([
        'status' => 1,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('requires authentication', function () {
    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 5]));

    $response->assertStatus(401);
});

it('returns 400 when ciudad_producto_sucursal param is missing', function () {
    authenticatedUser();

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal'));

    $response->assertStatus(400)
        ->assertJsonFragment(['message' => 'El parámetro ciudad_producto_sucursal es requerido y debe ser un entero positivo']);
});

it('returns 400 when ciudad_producto_sucursal param is zero or negative', function () {
    authenticatedUser();

    test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 0]))
        ->assertStatus(400);

    test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => -3]))
        ->assertStatus(400);
});

it('returns 404 when attribute does not exist in the system', function () {
    requiereTablaDeProductosVacia();
    authenticatedUser();

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 5]));

    $response->assertStatus(404)
        ->assertJsonFragment(['message' => 'El atributo ciudad_producto_sucursal no está configurado en el sistema']);
});

it('returns products matching the filter value', function () {
    requiereTablaDeProductosVacia();
    authenticatedUser();

    $attrId = setupCiudadAtribute();

    $p1 = createProduct('SKU-A', 'Producto A');
    $p2 = createProduct('SKU-B', 'Producto B');
    $p3 = createProduct('SKU-C', 'Producto C');

    assignCiudadValue($p1, $attrId, 5);
    assignCiudadValue($p2, $attrId, 5);
    assignCiudadValue($p3, $attrId, 7); // different value, not value=10

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 5]));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'sku', 'name', 'price', 'ciudad_producto_sucursal', 'siempre_incluido'],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($p1);
    expect($ids)->toContain($p2);
    expect($ids)->not->toContain($p3);
    expect($response->json('meta.total'))->toBe(2);
});

it('always includes products with ciudad_producto_sucursal value = 10', function () {
    requiereTablaDeProductosVacia();
    authenticatedUser();

    $attrId = setupCiudadAtribute();

    $p1 = createProduct('SKU-A', 'Producto A');  // value = 5 (filter match)
    $p2 = createProduct('SKU-B', 'Producto B');  // value = 10 (always included)
    $p3 = createProduct('SKU-C', 'Producto C');  // value = 7 (excluded)

    assignCiudadValue($p1, $attrId, 5);
    assignCiudadValue($p2, $attrId, 10);
    assignCiudadValue($p3, $attrId, 7);

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 5]));

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($p1);  // matched filter
    expect($ids)->toContain($p2);  // always included (value=10)
    expect($ids)->not->toContain($p3);

    $p2Data = collect($response->json('data'))->firstWhere('id', $p2);
    expect($p2Data['siempre_incluido'])->toBeTrue();
    expect($p2Data['ciudad_producto_sucursal'])->toBe(10);
});

it('does not duplicate products that match both the filter and value 10', function () {
    requiereTablaDeProductosVacia();
    authenticatedUser();

    $attrId = setupCiudadAtribute();

    $p1 = createProduct('SKU-A', 'Producto A');  // value = 10 AND filter is also 10

    assignCiudadValue($p1, $attrId, 10);

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 10]));

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect(array_count_values($ids)[$p1])->toBe(1); // no duplicate

    $p1Data = collect($response->json('data'))->firstWhere('id', $p1);
    // siempre_incluido = false because attrValue === filterValue
    expect($p1Data['siempre_incluido'])->toBeFalse();
});

it('returns empty data when no products match and value 10 has no products', function () {
    requiereTablaDeProductosVacia();
    authenticatedUser();

    $attrId = setupCiudadAtribute();

    $p1 = createProduct('SKU-A', 'Producto A');
    assignCiudadValue($p1, $attrId, 3);

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 99]));

    $response->assertStatus(200)
        ->assertJson(['data' => [], 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1]]);
});

it('respects pagination parameters', function () {
    requiereTablaDeProductosVacia();
    authenticatedUser();

    $attrId = setupCiudadAtribute();

    for ($i = 1; $i <= 5; $i++) {
        $pid = createProduct("SKU-{$i}", "Producto {$i}");
        assignCiudadValue($pid, $attrId, 5);
    }

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', [
        'ciudad_producto_sucursal' => 5,
        'limit'                    => 2,
        'page'                     => 1,
    ]));

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(5);
    expect($response->json('meta.per_page'))->toBe(2);
    expect($response->json('meta.last_page'))->toBe(3);
});
