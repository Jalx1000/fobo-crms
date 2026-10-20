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
    /*
     * Sin depender de ensureCityAttribute(): esa función vive en PersonListFilterTest,
     * y Pest carga los archivos en orden alfabético — "Export" va antes que
     * "ListFilter", así que acá todavía no existía y el test moría con "undefined
     * function".
     */
    return Attribute::firstOrCreate(
        ['code' => 'cliente_ciudad', 'entity_type' => 'persons'],
        [
            'name'            => 'Ciudad',
            'type'            => 'select',
            'lookup_type'     => 'lead_pipelines',
            'is_required'     => 0,
            'is_unique'       => 0,
            'validation'      => '',
            'sort_order'      => 1,
            'is_user_defined' => 1,
        ],
    );
}

/**
 * Una persona con ciudad. Propia de este archivo por el mismo motivo que
 * cityAttribute(): createPerson() vive en PersonListFilterTest, que Pest carga
 * después de este. Nombre distinto para no chocar cuando corre la suite entera.
 */
function exportPersonaConCiudad(string $name, int $attributeId, int $pipelineId): int
{
    $personId = (int) DB::table('persons')->insertGetId([
        'name'       => $name,
        'emails'     => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('attribute_values')->insert([
        'entity_type'   => 'persons',
        'attribute_id'  => $attributeId,
        'entity_id'     => $personId,
        'integer_value' => $pipelineId,
    ]);

    return $personId;
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

    $personId = exportPersonaConCiudad('Export City Prospect '.rand(1000, 9999), $attribute->id, $pipelineId);

    $dataGrid = app(\Webkul\Admin\DataGrids\Contact\PersonDataGrid::class);
    $dataGrid->prepareColumns();

    $export = new DataGridExport($dataGrid);

    // Zip the export headings with the mapped row and read the city cell by its
    // column label, so the assertion is exact (no substring false positives).
    // La fila REAL de la grilla, como la recibe la exportación: un objeto armado a mano
    // con sólo el id no trae las demás columnas (person_name, emails…) y map() se caía
    // al leerlas, sin llegar a probar la ciudad.
    $record = $dataGrid->prepareQueryBuilder()->where('persons.id', $personId)->first();

    $cells = collect($export->headings())->combine($export->map($record));

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
