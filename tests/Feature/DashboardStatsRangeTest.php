<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Diagnóstico: cada tarjeta del dashboard pega a admin.dashboard.stats con un
 * type. Verificamos que TODOS los types activos respondan 200 al pasar un rango
 * de fechas (start/end), que es lo que disparan los botones 7/30/90/Este mes.
 * Un 500 aquí explicaría el "vacío como error" (la tarjeta queda en shimmer).
 */
it('todos los types activos del dashboard responden 200 con rango de fechas', function (string $type) {
    $response = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', [
            'type'  => $type,
            'start' => now()->subDays(6)->format('Y-m-d'),
            'end'   => now()->format('Y-m-d'),
        ]));

    expect($response->status())->toBe(200);
})->with([
    'over-all',
    'leads-by-users',
    'tiempo-por-vendedor',
    'open-leads-by-states-fixed',
    'total-services',
    'services-requested',
    'evolution',
    'total-leads-by-stages',
    'total-leads-by-stages-over-time',
]);
