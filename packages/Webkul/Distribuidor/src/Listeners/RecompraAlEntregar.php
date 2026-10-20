<?php

namespace Webkul\Distribuidor\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Distribuidor\Services\Recordatorios;

/**
 * Un pedido pasó a "entregado" desde el CRM: se programa el recordatorio de recompra.
 *
 * El botón "Entregado" del Punto ya lo programa. Esto cubre los otros caminos —el
 * kanban, la ficha del pedido, la API—, que antes no programaban nada: el cliente
 * quedaba sin recordatorio según por dónde se hubiera marcado la entrega.
 *
 * Escucha `lead.city.synced`, por el que pasa cualquier alta o edición de un pedido.
 * Como puede llegar varias veces por el mismo pedido, usa programarSiCorresponde(),
 * que no reprograma una entrega ya programada.
 */
class RecompraAlEntregar
{
    public function __construct(
        protected Recordatorios $recordatorios,
    ) {}

    public function handle($lead): void
    {
        if (! $lead?->person_id || ! $lead->lead_pipeline_stage_id) {
            return;
        }

        $esEntregado = DB::table('lead_pipeline_stages')
            ->where('id', $lead->lead_pipeline_stage_id)
            ->value('code') === config('distribuidor.etapas.entregado');

        if (! $esEntregado) {
            return;
        }

        try {
            $this->recordatorios->programarSiCorresponde(
                (int) $lead->person_id,
                Carbon::parse($lead->closed_at ?? $lead->updated_at ?? now()),
            );
        } catch (\Throwable $e) {
            // Marcar el pedido como entregado es el trabajo; el recordatorio es un extra.
            Log::warning('No se pudo programar el recordatorio de recompra', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
