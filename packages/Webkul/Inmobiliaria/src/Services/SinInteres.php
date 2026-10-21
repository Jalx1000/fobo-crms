<?php

namespace Webkul\Inmobiliaria\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Repositories\LeadRepository;

/**
 * Mueve leads a la etapa «Sin interés» y deja constancia en su timeline.
 *
 * Lo usan el endpoint del agente y el comando que barre los leads sin
 * respuesta: los dos caminos tienen que dejar el lead igual y con el motivo a
 * la vista del asesor.
 */
class SinInteres
{
    public function __construct(
        protected LeadRepository $leads,
        protected ActivityRepository $activities,
    ) {}

    /**
     * Mueve el lead a «Sin interés» dentro de su propio pipeline.
     *
     * Devuelve false si ya estaba ahí, si está cerrado o si el pipeline no
     * tiene esa etapa: en ninguno de esos casos hay nada que mover.
     */
    public function mover($lead, string $motivo, ?int $userId = null): bool
    {
        $etapa = $lead->pipeline?->stages()->where('code', config('inmobiliaria.inmuebles.sin_interes.etapa'))->first();

        if (! $etapa || (int) $lead->lead_pipeline_stage_id === (int) $etapa->id || $this->estaCerrado($lead)) {
            return false;
        }

        Event::dispatch('lead.update.before', $lead->id);

        $lead = $this->leads->update([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $etapa->id,
        ], $lead->id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $lead);

        $this->anotar($lead, 'Movido a «Sin interés»: '.$motivo, $userId);

        return true;
    }

    /**
     * Si el lead estaba en «Sin interés», lo devuelve a la primera etapa.
     *
     * Para cuando el cliente vuelve a escribir con interés: se reactiva el
     * mismo lead en vez de crear otro.
     */
    public function reactivar($lead): bool
    {
        if ($lead->stage?->code !== config('inmobiliaria.inmuebles.sin_interes.etapa')) {
            return false;
        }

        $primera = $lead->pipeline->stages()->orderBy('sort_order')->first();

        Event::dispatch('lead.update.before', $lead->id);

        $lead = $this->leads->update([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $primera->id,
        ], $lead->id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $lead);

        $this->anotar($lead, 'Reactivado: el cliente volvió a mostrar interés.', null);

        return true;
    }

    /**
     * Ganado o perdido: un lead cerrado no se mueve a «Sin interés».
     */
    public function estaCerrado($lead): bool
    {
        $cerradas = array_merge(
            (array) config('reporting.stages.won', []),
            (array) config('reporting.stages.lost', []),
        );

        return in_array($lead->stage?->code, $cerradas, true);
    }

    protected function anotar($lead, string $texto, ?int $userId): void
    {
        try {
            Event::dispatch('activity.create.before');

            $activity = $this->activities->create([
                'type'    => 'note',
                'comment' => $texto,
                'is_done' => 1,
                'user_id' => $userId,
            ]);

            LeadProxy::modelClass()::find($lead->id)?->activities()->attach($activity->id);

            if ($lead->person_id) {
                DB::table('person_activities')->insertOrIgnore([
                    'person_id'   => $lead->person_id,
                    'activity_id' => $activity->id,
                ]);
            }

            Event::dispatch('activity.create.after', $activity);
        } catch (Throwable $e) {
            // La nota es constancia, no la operación: si falla, el lead ya se movió.
            Log::warning('No se pudo anotar el movimiento a Sin interés.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
