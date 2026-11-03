<?php

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Repositories\LeadRepository;

/*
 * Fecha de cierre (closed_at) de un pedido.
 *
 * Antes sólo Entregado/Cancelado la grababan, y sólo si el cambio pasaba por
 * LeadRepository::update. "Cliente sin interés" y "Otros Servicios" quedaban
 * sin fecha y "Tiempo en responder" los medía hasta AHORA. La regla vive ahora
 * en el modelo, así que vale para kanban, mass update, actividades y agente.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(fn () => DB::beginTransaction());

afterEach(fn () => DB::rollBack());

function etapaDeCierre(string $code): int
{
    $pipelineId = (int) DB::table('lead_pipelines')->where('name', 'Tarija')->value('id');

    return (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->where('code', $code)
        ->value('id');
}

function pedidoAbierto(): Lead
{
    $etapa = etapaDeCierre('no-atendido');

    return Lead::create([
        'title'                  => 'Test fecha de cierre',
        'lead_value'             => 0,
        'status'                 => 1,
        'lead_pipeline_id'       => DB::table('lead_pipeline_stages')->where('id', $etapa)->value('lead_pipeline_id'),
        'lead_pipeline_stage_id' => $etapa,
    ]);
}

it('graba closed_at al pasar a una etapa de cierre', function (string $code) {
    $lead = pedidoAbierto();

    expect($lead->closed_at)->toBeNull();

    // Mismo camino que el mass update del kanban: update directo del modelo.
    $lead->update(['lead_pipeline_stage_id' => etapaDeCierre($code)]);

    expect($lead->fresh()->closed_at)->not->toBeNull();
})->with(['cliente-sin-inters', 'otros-servicios', 'pedidos-entregados', 'pedidos-cancelados']);

it('graba closed_at también por LeadRepository::update', function () {
    $lead = pedidoAbierto();

    app(LeadRepository::class)->update([
        'entity_type'            => 'leads',
        'lead_pipeline_stage_id' => etapaDeCierre('cliente-sin-inters'),
    ], $lead->id, ['lead_pipeline_stage_id']);

    expect($lead->fresh()->closed_at)->not->toBeNull();
});

it('borra closed_at si el pedido se reabre', function () {
    $lead = pedidoAbierto();
    $lead->update(['lead_pipeline_stage_id' => etapaDeCierre('cliente-sin-inters')]);

    $lead->update(['lead_pipeline_stage_id' => etapaDeCierre('cliente-contactado')]);

    expect($lead->fresh()->closed_at)->toBeNull();
});

it('respeta la fecha de cierre elegida a mano', function () {
    $lead = pedidoAbierto();

    $lead->update([
        'lead_pipeline_stage_id' => etapaDeCierre('pedidos-entregados'),
        'closed_at'              => '2026-10-01 10:00:00',
    ]);

    expect($lead->fresh()->closed_at->toDateTimeString())->toBe('2026-10-01 10:00:00');
});

it('no toca closed_at si se edita el pedido sin cambiar de etapa', function () {
    $lead = pedidoAbierto();
    $lead->update(['lead_pipeline_stage_id' => etapaDeCierre('otros-servicios')]);
    DB::table('leads')->where('id', $lead->id)->update(['closed_at' => '2026-10-02 09:00:00']);

    $lead->fresh()->update(['title' => 'Editado']);

    expect($lead->fresh()->closed_at->toDateTimeString())->toBe('2026-10-02 09:00:00');
});

it('graba confirmed_at aunque el pedido se cree ya confirmado', function () {
    $etapa = etapaDeCierre('cliente-confirmado');

    $lead = Lead::create([
        'title'                  => 'Test creado confirmado',
        'lead_value'             => 0,
        'status'                 => 1,
        'lead_pipeline_id'       => DB::table('lead_pipeline_stages')->where('id', $etapa)->value('lead_pipeline_id'),
        'lead_pipeline_stage_id' => $etapa,
    ]);

    expect($lead->fresh()->confirmed_at)->not->toBeNull()
        ->and($lead->fresh()->closed_at)->toBeNull();
});
