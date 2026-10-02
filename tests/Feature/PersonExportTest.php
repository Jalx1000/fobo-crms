<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\DataGrid\Exports\DataGridExport;

/*
 * Dentro de una transacción.
 *
 * Sin esto el test insertaba un `lead_pipeline` con nombre aleatorio
 * ("Bogotá Export 6799") y una persona en la base REAL, y no los borraba: cada
 * corrida de la suite dejaba una ciudad fantasma más. Llegaron a aparecer tres
 * en el selector de ciudades del Punto de Distribuidor y en el ruteo de
 * derivaciones de WhatsApp, donde quedaban sin asesor.
 */

/**
 * These tests exercise the prospect (persons) export and require a database
 * connection. They cover the two reported bugs:
 *   1. lookup-backed custom attributes (city) must export their label, not id.
 *   2. the export link must carry the active date/city filter.
 *
 * The city attribute + person helpers are shared with PersonListFilterTest.
 */
uses(DatabaseTransactions::class);

function cityAttribute(): Attribute
{
    ensureCityAttribute();

    return Attribute::where('code', 'cliente_ciudad')
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
    $pipelineId = pipelineIdByName('Bogotá Export '.rand(1000, 9999));
    $pipelineName = DB::table('lead_pipelines')->where('id', $pipelineId)->value('name');

    $personId = createPerson('Export City Prospect '.rand(1000, 9999), now(), $attribute->id, $pipelineId);

    $dataGrid = app(\Webkul\Admin\DataGrids\Contact\PersonDataGrid::class);
    $dataGrid->prepareColumns();

    $export = new DataGridExport($dataGrid);

    // Zip the export headings with the mapped row and read the city cell by its
    // column label, so the assertion is exact (no substring false positives).
    $cells = collect($export->headings())->combine($export->map((object) ['id' => $personId]));

    expect($cells->get($attribute->name))->toBe($pipelineName);
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
