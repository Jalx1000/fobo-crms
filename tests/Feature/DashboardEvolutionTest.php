<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * El card "Evolución" compara el período actual contra el período anterior real,
 * punto a punto. El endpoint debe devolver ambas series alineadas (misma
 * longitud que las etiquetas) más totales y rangos.
 */
it('el endpoint evolution devuelve series actual y anterior alineadas', function () {
    $response = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', [
            'type'  => 'evolution',
            'start' => now()->subDays(13)->format('Y-m-d'),
            'end'   => now()->format('Y-m-d'),
        ]))
        ->assertStatus(200);

    $stats = $response->json('statistics');

    expect($stats)->toHaveKeys([
        'labels', 'current', 'previous', 'total', 'previous_total',
        'current_range', 'previous_range', 'period', 'progress',
    ]);

    // Las dos series y las etiquetas deben tener la misma longitud (comparables 1 a 1).
    expect(count($stats['current']))->toBe(count($stats['labels']));
    expect(count($stats['previous']))->toBe(count($stats['labels']));

    // El total declarado debe ser la suma de la serie actual.
    expect($stats['total'])->toBe(array_sum($stats['current']));
    expect($stats['previous_total'])->toBe(array_sum($stats['previous']));
});
