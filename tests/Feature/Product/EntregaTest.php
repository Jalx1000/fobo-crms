<?php

use Webkul\Product\Support\Entrega;

/**
 * Tarea 6: las reglas de entrega de la reunión del 11/09.
 *
 * Lo que se protege: que el plazo y el lugar salgan del producto y la cantidad,
 * y que combinar varios ítems dé lo más restrictivo — un pedido con tapas
 * impresas tarda 20 días entero, no "inmediata para las bolsas".
 */
function producto(array $attrs = []): object
{
    return (object) array_merge([
        'tiempo_entrega'          => 'Inmediata',
        'lugar_entrega'           => Entrega::LUGAR_ALMACEN,
        'entrega_domicilio_desde' => null,
    ], $attrs);
}

function varianteCon(?string $tiempo): object
{
    return (object) ['tiempo_entrega' => $tiempo];
}

it('resuelve los siete casos de la tabla del negocio', function () {
    $bolsas = producto();
    $hules = producto(['entrega_domicilio_desde' => 5]);
    $stretch = producto(['entrega_domicilio_desde' => 50]);
    $tapas = producto(['lugar_entrega' => Entrega::LUGAR_PLANTA]);

    foreach ([
        'bolsas 10 packs'        => [Entrega::paraItem($bolsas, 10), 'Inmediata', Entrega::LUGAR_ALMACEN],
        'hules 4 rollos'         => [Entrega::paraItem($hules, 4), 'Inmediata', Entrega::LUGAR_ALMACEN],
        'hules 5 rollos'         => [Entrega::paraItem($hules, 5), 'Inmediata', Entrega::LUGAR_DOMICILIO],
        'stretch 49 rollos'      => [Entrega::paraItem($stretch, 49), 'Inmediata', Entrega::LUGAR_ALMACEN],
        'stretch 50 rollos'      => [Entrega::paraItem($stretch, 50), 'Inmediata', Entrega::LUGAR_DOMICILIO],
        'tapas sin impresión'    => [Entrega::paraItem($tapas, 1), 'Inmediata', Entrega::LUGAR_PLANTA],
        'tapas con impresión'    => [Entrega::paraItem($tapas, 30, varianteCon('20 días')), '20 días', Entrega::LUGAR_PLANTA],
    ] as $caso => [$r, $tiempo, $lugar]) {
        expect($r['tiempo'])->toBe($tiempo, $caso);
        expect($r['lugar'])->toBe($lugar, $caso);
    }
});

it('la variante pisa el tiempo del producto, pero solo si lo tiene', function () {
    $tapas = producto(['lugar_entrega' => Entrega::LUGAR_PLANTA]);

    expect(Entrega::paraItem($tapas, 1, varianteCon(null))['tiempo'])->toBe('Inmediata');
    expect(Entrega::paraItem($tapas, 1, varianteCon('20 días'))['tiempo'])->toBe('20 días');
});

it('recoge en planta no se convierte en domicilio por cantidad', function () {
    // Quien recoge en planta, recoge en planta: el umbral solo mueve de almacén
    // a domicilio.
    $tapas = producto(['lugar_entrega' => Entrega::LUGAR_PLANTA, 'entrega_domicilio_desde' => 5]);

    expect(Entrega::paraItem($tapas, 100)['lugar'])->toBe(Entrega::LUGAR_PLANTA);
});

it('un pedido mixto arrastra el peor tiempo y el lugar más restrictivo', function () {
    $r = Entrega::paraCotizacion([
        ['tiempo' => 'Inmediata', 'lugar' => Entrega::LUGAR_ALMACEN],   // bolsas
        ['tiempo' => '20 días',   'lugar' => Entrega::LUGAR_PLANTA],    // tapas impresas
    ]);

    expect($r['tiempo'])->toBe('20 días');
    expect($r['lugar'])->toBe(Entrega::LUGAR_PLANTA);
});

it('solo va a domicilio si TODOS los ítems van a domicilio', function () {
    expect(Entrega::paraCotizacion([
        ['tiempo' => 'Inmediata', 'lugar' => Entrega::LUGAR_DOMICILIO],
        ['tiempo' => 'Inmediata', 'lugar' => Entrega::LUGAR_DOMICILIO],
    ])['lugar'])->toBe(Entrega::LUGAR_DOMICILIO);

    // Uno solo que no llega al umbral obliga a juntar todo en el almacén.
    expect(Entrega::paraCotizacion([
        ['tiempo' => 'Inmediata', 'lugar' => Entrega::LUGAR_DOMICILIO],
        ['tiempo' => 'Inmediata', 'lugar' => Entrega::LUGAR_ALMACEN],
    ])['lugar'])->toBe(Entrega::LUGAR_ALMACEN);
});

it('compara plazos en días aunque estén escritos distinto', function () {
    expect(Entrega::peorTiempo(['Inmediata', '20 días']))->toBe('20 días');
    expect(Entrega::peorTiempo(['20 días', '3 semanas']))->toBe('3 semanas');
    expect(Entrega::peorTiempo(['20 días', '1 mes']))->toBe('1 mes');
    expect(Entrega::peorTiempo(['5 días', '20 días']))->toBe('20 días');
});

it('un plazo sin número gana: "a confirmar" no puede leerse como inmediata', function () {
    expect(Entrega::peorTiempo(['Inmediata', 'A confirmar con planta']))->toBe('A confirmar con planta');
});

it('sin ítems devuelve los valores por defecto en vez de romper', function () {
    expect(Entrega::paraCotizacion([]))->toBe(['tiempo' => 'Inmediata', 'lugar' => Entrega::LUGAR_ALMACEN]);
});

it('el agente recibe la entrega resuelta para la cantidad pedida', function () {
    // Desde el 13/10 Hules cotiza por Color y Factura (con o sin): sin Factura
    // el agente recibe «faltan datos» en vez de la cotización.
    actingAsSanctumAuthenticatedAdmin();

    // Hules: el umbral de 5 rollos es lo que le permite al agente ofrecer
    // "si lleva 5 o más, se lo llevamos" sin inventarlo.
    $r = test()->postJson('/api/v1/productos/catalogo/CM_00003/precio', [
        'criterios' => ['Color' => 'Negro', 'Factura' => 'Sin factura'], 'cantidad' => 4,
    ])->assertOk();

    expect($r->json('entrega'))->toBe([
        'tiempo' => 'Inmediata', 'lugar' => Entrega::LUGAR_ALMACEN, 'domicilio_desde' => 5,
    ]);

    expect(test()->postJson('/api/v1/productos/catalogo/CM_00003/precio', [
        'criterios' => ['Color' => 'Negro', 'Factura' => 'Sin factura'], 'cantidad' => 5,
    ])->json('entrega.lugar'))->toBe(Entrega::LUGAR_DOMICILIO);
});

it('tapas con impresión llegan al agente con los 20 días de su variante', function () {
    actingAsSanctumAuthenticatedAdmin();

    // toMatchArray y no toBe: desde el 14/09 `entrega` trae además la
    // sucursal con su enlace de Maps cuando se retira en planta.
    expect(test()->postJson('/api/v1/productos/catalogo/CM_00004/precio', [
        'criterios' => ['Impresión' => 'Con impresión'], 'cantidad' => 30,
    ])->json('entrega'))->toMatchArray(['tiempo' => '20 días', 'lugar' => Entrega::LUGAR_PLANTA]);

    expect(test()->postJson('/api/v1/productos/catalogo/CM_00004/precio', [
        'criterios' => ['Impresión' => 'Sin impresión'], 'cantidad' => 1,
    ])->json('entrega.tiempo'))->toBe('Inmediata');
});
