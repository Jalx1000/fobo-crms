<?php

namespace Webkul\Lead\Services;

use Illuminate\Support\Facades\Event;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Repositories\LeadRepository;

/**
 * Mueve un pedido de etapa por código, dentro de su propio pipeline (cada
 * ciudad tiene el suyo, con ids distintos).
 *
 * Solo desde las etapas indicadas: un pedido que el asesor ya llevó más
 * adelante -o cerró- no retrocede por una automatización. Va por el
 * repositorio y con los mismos eventos que el arrastre en el kanban.
 */
class EtapaPedido
{
    public function mover(int $leadId, string $codigo, array $desde): bool
    {
        $lead = LeadProxy::modelClass()::find($leadId);

        if (! $lead || ! in_array($lead->stage?->code, $desde, true)) {
            return false;
        }

        $destino = $lead->pipeline?->stages()->where('code', $codigo)->first();

        if (! $destino || (int) $destino->id === (int) $lead->lead_pipeline_stage_id) {
            return false;
        }

        Event::dispatch('lead.update.before', $lead->id);

        $lead = app(LeadRepository::class)->update([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $destino->id,
        ], $lead->id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $lead);

        return true;
    }
}
