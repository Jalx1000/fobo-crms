<?php

namespace Webkul\Distribuidor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Distribuidor\Services\Recordatorios;
use Webkul\Lead\Services\CitySyncService;

/**
 * Programa el recordatorio de recompra a los clientes que YA tenían una entrega.
 *
 * El recordatorio se programa al entregar un pedido. Los clientes entregados antes de
 * encender los recordatorios no tienen nada programado y nunca recibirían uno: esto
 * los pone al día, una vez.
 *
 * Sólo clientes cuyo ÚLTIMO pedido está entregado y que no tienen otro en curso. Su
 * recordatorio queda a 4 días de esa entrega; si ya pasaron, sale en la próxima
 * corrida de distribuidor:recordatorios.
 *
 * Sin --aplicar sólo muestra cuántos y a quiénes.
 */
class ProgramarRecompra extends Command
{
    protected $signature = 'distribuidor:programar-recompra {--aplicar : Escribe la programación. Sin esto, sólo muestra el plan.}';

    protected $description = 'Programa el recordatorio de recompra a los clientes con su último pedido entregado.';

    public function handle(Recordatorios $recordatorios): int
    {
        if (! $recordatorios->habilitado()) {
            $this->warn('Los recordatorios están apagados (DISTRIBUIDOR_RECORDATORIOS o la configuración del CRM). No se programa nada.');

            return self::SUCCESS;
        }

        $entregado = DB::table('lead_pipeline_stages')
            ->where('code', config('distribuidor.etapas.entregado'))
            ->pluck('id');

        $cerradas = DB::table('lead_pipeline_stages')
            ->whereIn('code', CitySyncService::CLOSED_STAGE_CODES)
            ->pluck('id');

        // El último pedido de cada cliente.
        $ultimos = DB::table('leads')
            ->whereNotNull('person_id')
            ->whereIn('id', DB::table('leads')->selectRaw('max(id)')->whereNotNull('person_id')->groupBy('person_id'))
            ->whereIn('lead_pipeline_stage_id', $entregado)
            // Y ningún otro pedido en curso.
            ->whereNotExists(fn ($q) => $q->from('leads as abiertos')
                ->whereColumn('abiertos.person_id', 'leads.person_id')
                ->whereNotIn('abiertos.lead_pipeline_stage_id', $cerradas))
            ->get(['id', 'person_id', 'closed_at', 'updated_at']);

        $this->info($ultimos->count().' clientes con su último pedido entregado y nada en curso.');

        if (! $this->option('aplicar')) {
            $this->line('Modo prueba: no se programó nada. Usá --aplicar.');

            return self::SUCCESS;
        }

        $programados = 0;

        foreach ($ultimos as $lead) {
            $fila = $recordatorios->programarSiCorresponde(
                (int) $lead->person_id,
                Carbon::parse($lead->closed_at ?? $lead->updated_at),
            );

            $programados += $fila ? 1 : 0;
        }

        $this->info("{$programados} recordatorios programados.");

        return self::SUCCESS;
    }
}
