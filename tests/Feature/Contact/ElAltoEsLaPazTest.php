<?php

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\CiudadCliente;
use Webkul\Lead\Services\PipelineDeCiudad;
use Webkul\Product\Services\Responsables;

/**
 * El Alto es La Paz (decisión del 16/10/2026): el contacto conserva «El Alto»
 * -la cotización no lo lleva, ciudades_sin_cotizacion-, pero para el pipeline,
 * el reparto y los filtros por ciudad cuenta como La Paz.
 */
require_once __DIR__.'/../helpers/personas.php';

it('El Alto pertenece a la zona de La Paz', function () {
    expect(CiudadCliente::zona('El Alto'))->toBe('La Paz')
        ->and(CiudadCliente::zona('el alto'))->toBe('La Paz')
        ->and(CiudadCliente::zona('Cochabamba'))->toBe('Cochabamba')
        ->and(CiudadCliente::zona(null))->toBeNull();
});

it('un pedido de El Alto nace en el pipeline La Paz', function () {
    expect(app(PipelineDeCiudad::class)->para('El Alto')->name)->toBe('La Paz');
});

it('el reparto trata El Alto como La Paz', function () {
    expect(Responsables::mismaCiudad('El Alto', 'La Paz'))->toBeTrue()
        ->and(Responsables::mismaCiudad('El Alto', 'Cochabamba'))->toBeFalse();
});

it('el filtro de Contactos por La Paz incluye a los de El Alto, y el contacto conserva «El Alto»', function () {
    test()->actingAs(getDefaultAdmin());

    $persona = createPersonWithoutCity('De El Alto '.rand(1000, 9999), now());
    app(CiudadCliente::class)->escribir('persons', $persona, 'El Alto');

    $laPaz = DB::table('lead_pipelines')->where('name', 'La Paz')->value('id');
    $ids = collect(test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('admin.contacts.persons.index', ['pipeline_id' => $laPaz, 'sort' => ['column' => 'id', 'order' => 'desc']]))
        ->assertOk()->json('records'))->pluck('id');

    expect($ids)->toContain($persona)
        ->and(app(CiudadCliente::class)->de('persons', $persona))->toBe('El Alto');
});

it('cuando el cliente dice El Alto, el pedido que espera en «Sin ciudad» pasa a La Paz', function () {
    $sinCiudad = DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');
    $lead = DB::table('leads')->insertGetId([
        'title'                  => 'El Alto '.uniqid(), 'status' => 1, 'lead_pipeline_id' => $sinCiudad,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $sinCiudad)->orderBy('sort_order')->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    expect(app(PipelineDeCiudad::class)->moverSiEspera($lead, 'El Alto'))->toBeTrue()
        ->and(DB::table('lead_pipelines')->where('id', DB::table('leads')->where('id', $lead)->value('lead_pipeline_id'))->value('name'))->toBe('La Paz');
});
