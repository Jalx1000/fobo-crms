<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

function stagesOverTime(array $params = []): array
{
    return test()->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', array_merge(['type' => 'total-leads-by-stages-over-time'], $params)))
        ->assertStatus(200)
        ->json('statistics');
}

/**
 * "Comportamiento de etapas" expone las series por etapa más el versus con el
 * período anterior: totales actuales y anteriores alineados punto a punto,
 * total agregado de ambos períodos y la variación %.
 */
it('expone datasets, totales y el versus con el período anterior', function () {
    $stats = stagesOverTime([
        'start' => now()->subDays(29)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ]);

    expect($stats)->toHaveKeys([
        'labels', 'datasets', 'totals', 'previous_totals', 'total', 'previous_total',
        'current_range', 'previous_range', 'progress',
    ]);

    // Series alineadas: mismos puntos para etiquetas, totales y período anterior.
    $points = count($stats['labels']);
    expect(count($stats['totals']))->toBe($points);
    expect(count($stats['previous_totals']))->toBe($points);

    foreach ($stats['datasets'] as $dataset) {
        expect(count($dataset['data']))->toBe($points);
    }

    // El total agregado es la suma de los puntos.
    expect($stats['total'])->toBe(array_sum($stats['totals']));
    expect($stats['previous_total'])->toBe(array_sum($stats['previous_totals']));
});

/**
 * El "Comportamiento de etapas" muestra Cancelado (para ver la fuga de leads)
 * y oculta las etapas intermedias "En proceso" y "Paciente (Atendido)".
 */
it('incluye Cancelado y oculta En proceso y Paciente (Atendido)', function () {
    $labels = array_column(stagesOverTime()['datasets'], 'label');

    expect($labels)->toContain('Cancelado');
    expect($labels)->not->toContain('En proceso');
    expect($labels)->not->toContain('Paciente (Atendido)');
});

/**
 * El versus: un lead creado dentro del rango anterior debe sumar en
 * previous_total y no en total.
 */
it('cuenta los leads del rango anterior en previous_total', function () {
    $stage = DB::table('lead_pipeline_stages')->where('code', 'consultas')->first();

    $baseline = stagesOverTime([
        'start' => now()->subDays(6)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ]);

    // Lead en el rango actual (hoy) y lead en el rango anterior (hace 8 días).
    Lead::create([
        'title'                  => 'Lead actual '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stage->id,
    ]);

    $previous = Lead::create([
        'title'                  => 'Lead anterior '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stage->id,
    ]);
    $previous->created_at = now()->subDays(8);
    $previous->save();

    $stats = stagesOverTime([
        'start' => now()->subDays(6)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ]);

    expect($stats['total'])->toBe($baseline['total'] + 1);
    expect($stats['previous_total'])->toBe($baseline['previous_total'] + 1);
});

/**
 * Granularidad adaptativa: 7 días → por día; 30/90 → por semana; 2 años → por
 * trimestre (etiquetas T1..T4), nunca cientos de puntos.
 *
 * Nota: un it() por rango. El controller (y su helper con las fechas leídas en
 * el constructor) queda memoizado en el objeto Route, así que dentro de un mismo
 * test un segundo request reutilizaría las fechas del primero.
 */
it('agrupa por día con un rango de 7 días', function () {
    $stats = stagesOverTime([
        'start' => now()->subDays(6)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ]);

    expect(count($stats['labels']))->toBe(7);
});

it('agrupa por semana con un rango de 30 días', function () {
    $stats = stagesOverTime([
        'start' => now()->subDays(29)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ]);

    expect(count($stats['labels']))->toBeLessThanOrEqual(6);
    expect($stats['labels'][0])->toStartWith('Sem ');
});

it('agrupa por trimestre con un rango de 2 años', function () {
    $stats = stagesOverTime([
        'start' => now()->subDays(729)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ]);

    expect(count($stats['labels']))->toBeLessThanOrEqual(10);
    expect($stats['labels'][0])->toMatch('/^T[1-4] \d{4}$/');
});

/**
 * Sin filtro propio de "2 años": el card sigue el filtro global del tablero,
 * igual que Evolución (los rangos largos entran por los date pickers).
 */
it('el tablero no muestra un botón de 2 años', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertDontSee('setQuickRange(730)', false)
        ->assertDontSee('2 años', false);
});
