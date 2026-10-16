<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

// Corre contra la base real: todo lo que crea se deshace al terminar.
require_once __DIR__.'/helpers/personas.php';

uses(DatabaseTransactions::class);

/**
 * Resolves the "Sin ciudad" pipeline id, creating it if the environment
 * doesn't already have it.
 */
function noCityPipelineId(): int
{
    $id = DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');

    if (! $id) {
        $id = DB::table('lead_pipelines')->insertGetId([
            'name'       => 'Sin ciudad',
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $id;
}

/**
 * Issues the datagrid AJAX request the same way the browser does (the datagrid
 * forwards the page query string), sorted by newest id first so freshly created
 * records land on the first page.
 */
function fetchPersonIds(array $query): array
{
    $query = array_merge([
        'sort'       => ['column' => 'id', 'order' => 'desc'],
        'pagination' => ['per_page' => 100],
    ], $query);

    $response = test()
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('admin.contacts.persons.index', $query));

    $response->assertOk();

    return collect($response->json('records'))->pluck('id')->all();
}

it('filters the prospect list by created_at date range', function () {
    test()->actingAs(getDefaultAdmin());

    $attributeId = ensureCityAttribute();

    $recent = createPerson('Recent Prospect '.rand(1000, 9999), now(), $attributeId, 7);
    $old = createPerson('Old Prospect '.rand(1000, 9999), now()->subDays(200), $attributeId, 7);

    $ids = fetchPersonIds([
        'start' => now()->toDateString(),
        'end'   => now()->toDateString(),
    ]);

    expect($ids)->toContain($recent);
    expect($ids)->not->toContain($old);
});

it('filters the prospect list by city (atributo ciudad, por nombre)', function () {
    test()->actingAs(getDefaultAdmin());

    $attributeId = ensureCityAttribute();

    $inCity = createPerson('City 7 Prospect '.rand(1000, 9999), now(), $attributeId, 7);
    $otherCity = createPerson('City 1 Prospect '.rand(1000, 9999), now(), $attributeId, 1);

    $ids = fetchPersonIds([
        'pipeline_id' => 7,
        'start'       => now()->toDateString(),
        'end'         => now()->toDateString(),
    ]);

    expect($ids)->toContain($inCity);
    expect($ids)->not->toContain($otherCity);
});

it('treats prospects with no city as "Sin ciudad" when filtering', function () {
    test()->actingAs(getDefaultAdmin());

    $attributeId = ensureCityAttribute();
    $noCityId = noCityPipelineId();

    // «Sin ciudad» = sin ciudad cargada. Desde el 15/09 el atributo `ciudad`
    // no tiene una opción «Sin ciudad» (antes había un caso «explícito»).
    $withoutCity = createPersonWithoutCity('No City Prospect '.rand(1000, 9999), now());
    $otherCity = createPerson('City 7 Prospect '.rand(1000, 9999), now(), $attributeId, 7);

    $ids = fetchPersonIds([
        'pipeline_id' => $noCityId,
        'start'       => now()->toDateString(),
        'end'         => now()->toDateString(),
    ]);

    expect($ids)->toContain($withoutCity);

    // A prospect from another city is excluded.
    expect($ids)->not->toContain($otherCity);

    // And an empty-city prospect must NOT leak into another city's filter.
    $city7Ids = fetchPersonIds([
        'pipeline_id' => 7,
        'start'       => now()->toDateString(),
        'end'         => now()->toDateString(),
    ]);

    expect($city7Ids)->not->toContain($withoutCity);
});

it('returns all prospects when no date/city filter is applied', function () {
    test()->actingAs(getDefaultAdmin());

    $attributeId = ensureCityAttribute();

    $recent = createPerson('Unfiltered Prospect '.rand(1000, 9999), now(), $attributeId, 7);

    $ids = fetchPersonIds([]);

    expect($ids)->toContain($recent);
});

it('el KPI «Total de contactos» del tablero respeta el filtro de ciudad', function () {
    test()->actingAs(getDefaultAdmin());

    $attributeId = ensureCityAttribute();
    $dia = '2020-01-15 10:00:00';

    createPerson('KPI La Paz '.rand(1000, 9999), $dia, $attributeId, 7);
    createPerson('KPI Santa Cruz '.rand(1000, 9999), $dia, $attributeId, 1);
    createPersonWithoutCity('KPI sin ciudad '.rand(1000, 9999), $dia);

    $total = fn (array $filtro) => test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => 'over-all', 'start' => '2020-01-15', 'end' => '2020-01-15'] + $filtro))
        ->assertOk()->json('statistics.total_persons.current');

    expect($total([]))->toBe(3)
        ->and($total(['pipeline_id' => 7]))->toBe(1)
        ->and($total(['pipeline_id' => noCityPipelineId()]))->toBe(1);
});
