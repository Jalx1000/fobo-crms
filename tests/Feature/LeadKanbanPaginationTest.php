<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * El kanban carga cada columna paginada (10 por página) y el resto por scroll.
 * Este test verifica el contrato del endpoint: recorriendo las páginas de un
 * stage se obtienen TODOS sus leads (no solo los primeros 10). Es lo que el
 * scroll infinito del frontend consume.
 */
it('el endpoint del kanban entrega todos los leads de un stage al paginar', function () {
    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();
    $stage = $pipeline->stages->first();
    $leadRepo = app(LeadRepository::class);

    $person = Person::create(['name' => 'Paciente Kanban '.uniqid(), 'entity_type' => 'persons']);

    // Crear 25 leads en el mismo stage → fuerza varias páginas (>10).
    $createdIds = [];
    for ($i = 0; $i < 25; $i++) {
        $lead = $leadRepo->create([
            'title'                  => 'Lead Kanban '.$i.' '.uniqid(),
            'entity_type'            => 'leads',
            'lead_pipeline_id'       => $pipeline->id,
            'lead_pipeline_stage_id' => $stage->id,
            'user_id'                => 1,
            'person'                 => ['id' => $person->id],
        ]);
        $createdIds[] = $lead->id;
    }

    // Recorrer todas las páginas del stage y juntar los IDs devueltos.
    $collected = [];
    $page = 1;
    $lastPage = 1;

    do {
        $response = $this->actingAs(User::find(1), 'user')
            ->getJson(route('admin.leads.get', ['pipeline_id' => $pipeline->id])
                .'?pipeline_stage_id='.$stage->id.'&page='.$page)
            ->assertStatus(200);

        $column = collect($response->json())->firstWhere('id', $stage->id);

        expect($column)->not->toBeNull();

        foreach ($column['leads']['data'] as $lead) {
            $collected[] = $lead['id'];
        }

        $lastPage = $column['leads']['meta']['last_page'];
        $page++;
    } while ($page <= $lastPage);

    // Todos los leads creados deben haberse obtenido recorriendo las páginas.
    expect(array_intersect($createdIds, $collected))->toHaveCount(count($createdIds));
    // Y debió requerir más de una página (prueba que no se quedó en los primeros 10).
    expect($lastPage)->toBeGreaterThan(1);
});

/**
 * El filtro rápido por rango de fechas (date_range) acota los leads por created_at.
 * Con date_range=7 solo deben volver los leads creados en los últimos 7 días.
 */
it('el filtro date_range acota los leads por created_at', function () {
    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();
    $stage = $pipeline->stages->first();
    $leadRepo = app(LeadRepository::class);

    $person = Person::create(['name' => 'Paciente Rango '.uniqid(), 'entity_type' => 'persons']);

    $makeLead = function (string $when) use ($leadRepo, $pipeline, $stage, $person) {
        $lead = $leadRepo->create([
            'title'                  => 'Lead Rango '.uniqid(),
            'entity_type'            => 'leads',
            'lead_pipeline_id'       => $pipeline->id,
            'lead_pipeline_stage_id' => $stage->id,
            'user_id'                => 1,
            'person'                 => ['id' => $person->id],
        ]);

        $lead->forceFill(['created_at' => $when])->save();

        return $lead->id;
    };

    $recentId = $makeLead(now()->subDays(2));   // dentro de la ventana de 7 días
    $oldId = $makeLead(now()->subDays(40));  // fuera de la ventana de 7 días

    $response = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.leads.get', ['pipeline_id' => $pipeline->id])
            .'?pipeline_stage_id='.$stage->id.'&date_range=7&limit=100')
        ->assertStatus(200);

    $column = collect($response->json())->firstWhere('id', $stage->id);
    $ids = collect($column['leads']['data'])->pluck('id');

    expect($ids)->toContain($recentId);
    expect($ids)->not->toContain($oldId);
});

/**
 * El endpoint respeta el parámetro limit (fuente de verdad única con el frontend),
 * acotado a un máximo razonable.
 */
it('el endpoint del kanban respeta el parametro limit', function () {
    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();
    $stage = $pipeline->stages->first();
    $leadRepo = app(LeadRepository::class);

    $person = Person::create(['name' => 'Paciente Limit '.uniqid(), 'entity_type' => 'persons']);

    for ($i = 0; $i < 6; $i++) {
        $leadRepo->create([
            'title'                  => 'Lead Limit '.$i.' '.uniqid(),
            'entity_type'            => 'leads',
            'lead_pipeline_id'       => $pipeline->id,
            'lead_pipeline_stage_id' => $stage->id,
            'user_id'                => 1,
            'person'                 => ['id' => $person->id],
        ]);
    }

    $response = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.leads.get', ['pipeline_id' => $pipeline->id])
            .'?pipeline_stage_id='.$stage->id.'&limit=3')
        ->assertStatus(200);

    $column = collect($response->json())->firstWhere('id', $stage->id);

    expect($column['leads']['meta']['per_page'])->toBe(3);
    expect(count($column['leads']['data']))->toBe(3);
});
