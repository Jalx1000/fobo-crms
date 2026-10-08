<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una
 * transacción, así lo que el test crea se deshace al terminar.
 *
 * Dejó 9 personas "Explicit Sin Ciudad" en la base real.
 */
uses(DatabaseTransactions::class);

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

it('filters the prospect list by city (cliente_ciudad attribute)', function () {
    test()->actingAs(getDefaultAdmin());

    $attributeId = ensureCityAttribute();

    $inCity = createPerson('City 7 Prospect '.rand(1000, 9999), now(), $attributeId, 7);
    $otherCity = createPerson('City 3 Prospect '.rand(1000, 9999), now(), $attributeId, 3);

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

    $withoutCity = createPersonWithoutCity('No City Prospect '.rand(1000, 9999), now());
    $explicitNoCity = createPerson('Explicit Sin Ciudad '.rand(1000, 9999), now(), $attributeId, $noCityId);
    $otherCity = createPerson('City 7 Prospect '.rand(1000, 9999), now(), $attributeId, 7);

    $ids = fetchPersonIds([
        'pipeline_id' => $noCityId,
        'start'       => now()->toDateString(),
        'end'         => now()->toDateString(),
    ]);

    // Both the empty-city and the explicit "Sin ciudad" prospects show up.
    expect($ids)->toContain($withoutCity);
    expect($ids)->toContain($explicitNoCity);

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
