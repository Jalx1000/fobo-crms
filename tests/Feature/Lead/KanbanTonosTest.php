<?php

use Webkul\Lead\Models\Stage;

/**
 * Kanban con colores por etapa (pedido del 16/10/2026): amarillo para lo que
 * está en curso, verde para lo entregado y rojo para lo cerrado sin venta.
 * El tono sale del CÓDIGO de la etapa (el nombre ya cambió una vez:
 * «NO ATENIDOS» → «NO ATENDIDOS»).
 */
it('cada etapa del pedido tiene su tono', function () {
    $tono = fn (string $code) => (new Stage(['code' => $code]))->tono();

    expect($tono('no-atendidos'))->toBe('en-curso')
        ->and($tono('no-atenidos'))->toBe('en-curso')
        ->and($tono('solicitud-recibida'))->toBe('en-curso')
        ->and($tono('cotizacion-enviada'))->toBe('en-curso')
        ->and($tono('entregados'))->toBe('ganado')
        ->and($tono('sin-inters'))->toBe('perdido')
        ->and($tono('cancelados'))->toBe('perdido')
        ->and($tono('otros-servicios'))->toBe('perdido')
        ->and($tono('etapa-nueva'))->toBeNull();
});

it('el kanban recibe el tono de cada columna', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    $columnas = collect(test()->getJson(route('admin.leads.get', 1))->assertOk()->json())
        ->mapWithKeys(fn ($c) => [$c['code'] => $c['tono'] ?? null]);

    expect($columnas['no-atendidos'])->toBe('en-curso')
        ->and($columnas['entregados'])->toBe('ganado')
        ->and($columnas['cancelados'])->toBe('perdido');
});

it('el kanban trae los estilos de los tonos', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    $html = test()->get(route('admin.leads.index', ['view_type' => 'kanban']))->assertOk()->getContent();

    expect($html)->toContain('.etapa-en-curso')->toContain('.etapa-ganado')->toContain('.etapa-perdido');
});
