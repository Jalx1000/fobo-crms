<?php

/**
 * Al elegir un producto en la cotización, el precio sale de la lista del CRM
 * (21/10/2026: «no se me están precargando los precios»). Se resuelve con
 * ListaPrecios, igual que para el agente. Usa los productos reales, sin
 * escribir nada.
 */
function precioDeLista(int $producto, array $params = []): array
{
    return test()->getJson(route('admin.products.precio', $producto).'?'.http_build_query($params))->assertOk()->json();
}

beforeEach(fn () => test()->actingAs(getDefaultAdmin(), 'user'));

it('resuelve el precio por presentación y tramo de cantidad (Stretch Film)', function () {
    $automatico = DB::table('product_variantes')->where('product_id', 5)->where('valores->Presentación', 'Automático 15,8 kg')->value('id');

    $tramo = fn (int $cantidad) => (float) DB::table('product_prices')->where('product_id', 5)
        ->where('valores->Presentación', 'Automático 15,8 kg')->get()
        ->first(fn ($fila) => \Webkul\Product\Support\ListaPrecios::filtrar(collect([(object) ['valores' => json_decode($fila->valores, true)]]), ['Cantidad' => (string) $cantidad])->isNotEmpty())
        ->precio;

    expect(precioDeLista(5, ['variante_id' => $automatico, 'cantidad' => 1]))->toMatchArray(['estado' => 'resuelto', 'precio' => $tramo(1)])
        ->and(precioDeLista(5, ['variante_id' => $automatico, 'cantidad' => 60]))->toMatchArray(['estado' => 'resuelto', 'precio' => $tramo(60)]);
});

it('pide los ejes que faltan con sus opciones y resuelve cuando llegan (Hules)', function () {
    $variante = DB::table('product_variantes')->where('product_id', 1298)->value('id');

    $ambiguo = precioDeLista(1298, ['variante_id' => $variante, 'cantidad' => 1]);

    expect($ambiguo['estado'])->toBe('ambiguo')
        ->and(array_column($ambiguo['faltan'], 'eje'))->toBe(['Factura']);

    $opcion = $ambiguo['faltan'][0]['opciones'][0];

    expect(precioDeLista(1298, ['variante_id' => $variante, 'cantidad' => 1, 'criterios' => ['Factura' => $opcion]])['estado'])
        ->toBeIn(['resuelto', 'sin_datos']);
});

it('sin variante elegida no pide como eje extra lo que elige el selector de variante', function () {
    $sinVariante = precioDeLista(5, ['cantidad' => 1]);

    expect($sinVariante['estado'])->toBe('ambiguo')
        ->and($sinVariante['faltan'])->toBe([]);
});

it('un producto sin lista devuelve su precio fijo', function () {
    $sinLista = DB::table('products')->insertGetId([
        'sku'        => 'TEST-PRECIO-'.uniqid(), 'name' => 'Sin lista', 'price' => 25, 'quantity' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(precioDeLista($sinLista))->toMatchArray(['estado' => 'fijo', 'precio' => 25]);
});
