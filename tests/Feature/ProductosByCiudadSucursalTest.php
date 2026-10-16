<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Webkul\User\Models\User;

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

    return $attrId;
}

function createProduct(string $sku, string $name, float $price = 10.00): int
{
    return DB::table('products')->insertGetId([
        'sku'        => $sku,
        'name'       => $name,
        'price'      => $price,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
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

function teardownProductTables(): void
{
    DB::table('attribute_values')->where('entity_type', 'products')->delete();
    DB::table('attributes')->where('code', 'ciudad_producto_sucursal')->delete();
    DB::table('products')->truncate();
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


/*
| PELIGRO: este archivo hace DELETE/TRUNCATE sin transacción. Los tests corren
| contra la base de producción (no hay .env.testing), y el 16/10/2026 borró
| datos reales (valores de atributos de productos, doctor_specialty). Solo
| corre contra una base de prueba (nombre que termine en «_test» o «_testing»).
*/
beforeEach(function () {
    if (! preg_match('/_test(ing)?$/', (string) config('database.connections.'.config('database.default').'.database'))) {
        $this->markTestSkipped('Destructivo: solo contra una base de prueba.');
    }
});

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
    teardownProductTables();
    authenticatedUser();

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 5]));

    $response->assertStatus(404)
        ->assertJsonFragment(['message' => 'El atributo ciudad_producto_sucursal no está configurado en el sistema']);
});

it('returns products matching the filter value', function () {
    teardownProductTables();
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
    teardownProductTables();
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
    teardownProductTables();
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
    teardownProductTables();
    authenticatedUser();

    $attrId = setupCiudadAtribute();

    $p1 = createProduct('SKU-A', 'Producto A');
    assignCiudadValue($p1, $attrId, 3);

    $response = test()->getJson(route('api.productos.por-ciudad-sucursal', ['ciudad_producto_sucursal' => 99]));

    $response->assertStatus(200)
        ->assertJson(['data' => [], 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1]]);
});

it('respects pagination parameters', function () {
    teardownProductTables();
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
