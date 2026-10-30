<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * El card "Total de Consultas" del tablero (over-all) reemplaza al antiguo
 * "Total de Pacientes" y cuenta SOLO los leads que están en la etapa
 * "Consultas" del pipeline, dentro del rango de fechas del filtro.
 */
it('total_consultas cuenta solo los leads en etapa Consultas', function () {
    $stageConsultas = DB::table('lead_pipeline_stages')
        ->where('code', 'consultas')
        ->first();

    $otraEtapa = DB::table('lead_pipeline_stages')
        ->where('id', '!=', $stageConsultas->id)
        ->first();

    $baseline = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', [
            'type'  => 'over-all',
            'start' => now()->subDays(29)->format('Y-m-d'),
            'end'   => now()->format('Y-m-d'),
        ]))
        ->assertStatus(200)
        ->json('statistics.total_consultas.current');

    // Un lead en etapa Consultas debe sumar al contador...
    Lead::create([
        'title'                  => 'Consulta nueva '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => $stageConsultas->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stageConsultas->id,
    ]);

    // ...y un lead en cualquier otra etapa NO debe sumar.
    Lead::create([
        'title'                  => 'Lead en otra etapa '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => $otraEtapa->lead_pipeline_id,
        'lead_pipeline_stage_id' => $otraEtapa->id,
    ]);

    $current = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', [
            'type'  => 'over-all',
            'start' => now()->subDays(29)->format('Y-m-d'),
            'end'   => now()->format('Y-m-d'),
        ]))
        ->assertStatus(200)
        ->json('statistics.total_consultas.current');

    expect($current)->toBe($baseline + 1);
});

/**
 * El payload de over-all ya no expone total_persons: fue reemplazado por
 * total_consultas.
 */
it('over-all expone total_consultas en lugar de total_persons', function () {
    $stats = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'over-all']))
        ->assertStatus(200)
        ->json('statistics');

    expect($stats)->toHaveKey('total_consultas');
    expect($stats)->not->toHaveKey('total_persons');
    expect($stats['total_consultas'])->toHaveKeys(['previous', 'current', 'progress']);
});
