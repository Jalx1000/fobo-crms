<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Product\Models\Product;
use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

// ─────────────────────────────────────────────
// Modelo: persistencia y cast
// ─────────────────────────────────────────────

it('persiste duration_minutes como integer en la base de datos', function () {
    $product = Product::create([
        'name'             => 'Limpieza Dental Test',
        'sku'              => 'TEST-DUR-' . uniqid(),
        'duration_minutes' => 30,
    ]);

    expect($product->fresh()->duration_minutes)->toBe(30);
});

it('devuelve null cuando duration_minutes no está configurado', function () {
    $product = Product::create([
        'name' => 'Producto Sin Duración Test',
        'sku'  => 'TEST-DUR-NULL-' . uniqid(),
    ]);

    expect($product->fresh()->duration_minutes)->toBeNull();
});

it('castea duration_minutes a integer (no string)', function () {
    $product = Product::create([
        'name'             => 'Cast Test',
        'sku'              => 'TEST-CAST-' . uniqid(),
        'duration_minutes' => 45,
    ]);

    $fresh = $product->fresh();
    expect($fresh->duration_minutes)->toBeInt();
    expect($fresh->duration_minutes)->toBe(45);
});

it('permite actualizar duration_minutes', function () {
    $product = Product::create([
        'name'             => 'Update Test',
        'sku'              => 'TEST-UPD-' . uniqid(),
        'duration_minutes' => 60,
    ]);

    $product->update(['duration_minutes' => 90]);

    expect($product->fresh()->duration_minutes)->toBe(90);
});

it('permite asignar null a duration_minutes (reset a default del agente)', function () {
    $product = Product::create([
        'name'             => 'Reset Test',
        'sku'              => 'TEST-RESET-' . uniqid(),
        'duration_minutes' => 60,
    ]);

    $product->update(['duration_minutes' => null]);

    expect($product->fresh()->duration_minutes)->toBeNull();
});

// ─────────────────────────────────────────────
// Endpoint GET /api/public/products
// ─────────────────────────────────────────────

it('GET /api/public/products incluye duration_minutes en la respuesta', function () {
    Product::create([
        'name'             => 'API Duration Test',
        'sku'              => 'TEST-API-' . uniqid(),
        'duration_minutes' => 30,
    ]);

    $response = get('/api/public/products');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'name', 'sku', 'duration_minutes'],
        ],
    ]);
});

it('GET /api/public/products devuelve el valor correcto de duration_minutes', function () {
    $product = Product::create([
        'name'             => 'Servicio 45 min',
        'sku'              => 'TEST-API-VAL-' . uniqid(),
        'duration_minutes' => 45,
    ]);

    $response = get('/api/public/products');

    $response->assertStatus(200);

    $found = collect($response->json('data'))->firstWhere('id', $product->id);

    expect($found)->not->toBeNull();
    expect($found['duration_minutes'])->toBe(45);
});

it('GET /api/public/products devuelve null para productos sin duration_minutes', function () {
    $product = Product::create([
        'name' => 'Producto Sin Duración API',
        'sku'  => 'TEST-API-NULL-' . uniqid(),
    ]);

    $response = get('/api/public/products');

    $response->assertStatus(200);

    $found = collect($response->json('data'))->firstWhere('id', $product->id);

    expect($found)->not->toBeNull();
    expect($found['duration_minutes'])->toBeNull();
});

it('duration_minutes no es string en la respuesta JSON (cast correcto)', function () {
    $product = Product::create([
        'name'             => 'Type Check',
        'sku'              => 'TEST-TYPE-' . uniqid(),
        'duration_minutes' => 120,
    ]);

    $response = get('/api/public/products');

    $found = collect($response->json('data'))->firstWhere('id', $product->id);

    expect($found)->not->toBeNull();
    expect($found['duration_minutes'])->toBeInt();
});
