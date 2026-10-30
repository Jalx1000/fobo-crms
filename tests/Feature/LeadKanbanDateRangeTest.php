<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Helper: crea un lead en el stage dado con un created_at específico.
 */
function crearLeadFecha($leadRepo, $pipeline, $stage, $person, Carbon $createdAt): int
{
    $lead = $leadRepo->create([
        'title'                  => 'Lead Fecha '.uniqid(),
        'entity_type'            => 'leads',
        'lead_pipeline_id'       => $pipeline->id,
        'lead_pipeline_stage_id' => $stage->id,
        'user_id'                => 1,
        'person'                 => ['id' => $person->id],
    ]);

    $lead->forceFill(['created_at' => $createdAt])->save();

    return $lead->id;
}

/**
 * El kanban de leads filtra por rango personalizado start_date / end_date
 * sobre created_at.
 */
it('el kanban de leads filtra por rango personalizado start_date/end_date', function () {
    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();
    $stage = $pipeline->stages->first();
    $leadRepo = app(LeadRepository::class);
    $person = Person::create(['name' => 'Paciente Rango '.uniqid(), 'entity_type' => 'persons']);

    $base = Carbon::now()->startOfMonth()->addDays(10);

    $dentro = crearLeadFecha($leadRepo, $pipeline, $stage, $person, $base->copy());
    $fuera = crearLeadFecha($leadRepo, $pipeline, $stage, $person, $base->copy()->subDays(60));

    $response = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.leads.get', ['pipeline_id' => $pipeline->id]).'?'.http_build_query([
            'pipeline_stage_id' => $stage->id,
            'start_date'        => $base->copy()->subDays(3)->format('Y-m-d'),
            'end_date'          => $base->copy()->addDays(3)->format('Y-m-d'),
            'limit'             => 100,
        ]))
        ->assertStatus(200);

    $column = collect($response->json())->firstWhere('id', $stage->id);
    $ids = collect($column['leads']['data'])->pluck('id');

    expect($ids)->toContain($dentro)->not->toContain($fuera);
});

/**
 * Un rango personalizado tiene precedencia sobre el quick range (date_range):
 * aunque se envíe date_range=90, el start_date/end_date acota igual.
 */
it('el rango personalizado tiene precedencia sobre date_range', function () {
    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();
    $stage = $pipeline->stages->first();
    $leadRepo = app(LeadRepository::class);
    $person = Person::create(['name' => 'Paciente Prec '.uniqid(), 'entity_type' => 'persons']);

    // Lead de hace 45 días: entra en date_range=90 pero NO en un custom de últimos 7 días.
    $viejo = crearLeadFecha($leadRepo, $pipeline, $stage, $person, Carbon::now()->subDays(45));

    $response = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.leads.get', ['pipeline_id' => $pipeline->id]).'?'.http_build_query([
            'pipeline_stage_id' => $stage->id,
            'date_range'        => '90',
            'start_date'        => Carbon::now()->subDays(7)->format('Y-m-d'),
            'end_date'          => Carbon::now()->format('Y-m-d'),
            'limit'             => 100,
        ]))
        ->assertStatus(200);

    $column = collect($response->json())->firstWhere('id', $stage->id);
    $ids = collect($column['leads']['data'])->pluck('id');

    expect($ids)->not->toContain($viejo);
});

/**
 * El toolbar del kanban muestra la nueva UI: botones rápidos y el rango Desde/Hasta.
 */
it('el listado de leads muestra los filtros de fecha con Desde/Hasta', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.leads.index'))
        ->assertStatus(200)
        ->assertSee('applyCustomRange', false)
        ->assertSee('quickRangeStyle', false)
        ->assertSee('Desde', false)
        ->assertSee('Hasta', false)
        // Usa el helper/cookie compartida para sincronizar con Tablero y Pacientes.
        ->assertSee('OdontoDateRange', false);
});
