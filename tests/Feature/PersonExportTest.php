<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\DataGrid\Exports\DataGridExport;

/*
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una
 * transacción, así lo que el test crea se deshace al terminar.
 *
 * Creaba un pipeline sin etapas que después rompía a otros tests.
 */
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

    /*
     * La fila real que produce el DataGrid, no un objeto armado a mano.
     *
     * Antes se le pasaba (object) ['id' => $personId] y el export leía cada
     * columna por su índice: al agregarse `person_name` a la grilla, el stub
     * quedó corto y reventaba con "Undefined property". Tomando la fila de la
     * propia consulta, cualquier columna nueva viene incluida.
     */
    $registro = $dataGrid->prepareQueryBuilder()
        ->where('persons.id', $personId)
        ->first();

    expect($registro)->not->toBeNull('El DataGrid no devolvió la persona creada.');

    // Zip the export headings with the mapped row and read the city cell by its
    // column label, so the assertion is exact (no substring false positives).
    $cells = collect($export->headings())->combine($export->map($registro));

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
