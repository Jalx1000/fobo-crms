<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\DataGrid\Exports\DataGridExport;

// Corre contra la base real: todo lo que crea se deshace al terminar.
require_once __DIR__.'/helpers/personas.php';

uses(DatabaseTransactions::class);

/**
 * These tests exercise the prospect (persons) export and require a database
 * connection. They cover the two reported bugs:
 *   1. lookup-backed custom attributes (city) must export their label, not id.
 *   2. the export link must carry the active date/city filter.
 *
 * The city attribute + person helpers are shared with PersonListFilterTest.
 */
function cityAttribute(): Attribute
{
    ensureCityAttribute();

    return Attribute::where('code', 'ciudad')
        ->where('entity_type', 'persons')
        ->first();
}

/**
 * Resolve (creating if needed) a pipeline id by name, so we have a known
 * city label to assert against.
 */
function pipelineIdByName(string $name): int
{
    $id = DB::table('lead_pipelines')->where('name', $name)->value('id');

    if (! $id) {
        $id = DB::table('lead_pipelines')->insertGetId([
            'name'       => $name,
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $id;
}

it('exports the city custom attribute as its label, not the raw id', function () {
    test()->actingAs(getDefaultAdmin());

    $attribute = cityAttribute();

    // Cochabamba (pipeline 8): la ciudad se guarda como opción del atributo
    // `ciudad`, y el export tiene que mostrar el nombre, no el id de la opción.
    $personId = createPerson('Export City Prospect '.rand(1000, 9999), now(), $attribute->id, 8);

    $dataGrid = app(\Webkul\Admin\DataGrids\Contact\PersonDataGrid::class);
    $dataGrid->prepareColumns();

    $export = new DataGridExport($dataGrid);

    // La fila real que arma la grilla (map() lee todas las columnas: nombre,
    // emails, teléfonos…); antes se le pasaba un objeto con solo el id.
    $fila = $dataGrid->prepareQueryBuilder()->where('persons.id', $personId)->first();
    $cells = collect($export->headings())->combine($export->map($fila));

    expect($cells->get($attribute->name))->toBe('Cochabamba');
});

it('carries the active date/city filter in the prospect export link', function () {
    test()->actingAs(getDefaultAdmin());

    $response = test()->get(route('admin.contacts.persons.index', [
        'start'       => '2026-07-01',
        'end'         => '2026-07-07',
        'pipeline_id' => 7,
    ]));

    $response->assertOk();

    // The export button's src must carry the filter so the download matches
    // the on-screen list (regression guard for the export ignoring filters).
    $response->assertSee('start=2026-07-01', false);
    $response->assertSee('end=2026-07-07', false);
    $response->assertSee('pipeline_id=7', false);
});
