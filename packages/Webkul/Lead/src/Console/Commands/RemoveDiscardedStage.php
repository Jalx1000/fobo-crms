<?php

namespace Webkul\Lead\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Lead\Support\DiscardedLeads;

/**
 * Borra los pedidos descartados y la etapa "Descartado" de todos los pipelines.
 *
 * Descartado nació como un lugar donde dejar los duplicados sin perderlos. Al
 * decidir que no hacen falta, se van los dos: los pedidos y la etapa.
 *
 * El orden importa, por las foreign keys:
 *
 *   1. Re-apuntar las conversaciones de WhatsApp al pedido REAL del contacto.
 *      `whatsapp_conversations.lead_id` es ON DELETE SET NULL, así que borrar
 *      sin esto dejaría 100 conversaciones sin pedido — y una conversación sin
 *      lead_id hace que el agente crea que no hay pedido y abra uno nuevo. O
 *      sea: borrar duplicados generaría duplicados.
 *   2. Borrar los pedidos. Actividades, productos, tags y cotizaciones se van
 *      en cascada.
 *   3. Recién ahí borrar las etapas. Al revés, `leads.lead_pipeline_stage_id`
 *      (ON DELETE SET NULL) dejaría pedidos sin etapa, invisibles en el kanban
 *      pero vivos en la base.
 */
class RemoveDiscardedStage extends Command
{
    protected $signature = 'leads:eliminar-descartados
        {--dry-run : Mostrar qué se borraría, sin tocar nada}';

    protected $description = 'Borra los pedidos en "Descartado" y la etapa de todos los pipelines';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');

        $etapas = DB::table('lead_pipeline_stages')->where('code', DiscardedLeads::STAGE_CODE)->pluck('id');

        if ($etapas->isEmpty()) {
            $this->info('No existe la etapa "Descartado" en ningún pipeline: no hay nada que hacer.');

            return self::SUCCESS;
        }

        $leads = DB::table('leads')->whereIn('lead_pipeline_stage_id', $etapas)->pluck('id');

        $this->line('Etapas "Descartado": '.$etapas->count().'   ·   Pedidos en ellas: '.$leads->count()
            .($seco ? '   — SIMULACIÓN' : ''));
        $this->newLine();

        $reapuntar = $this->conversacionesAReapuntar($leads);

        $this->table(
            ['Qué', 'Cuánto'],
            [
                ['conversaciones que se re-apuntan al pedido real', count($reapuntar['con'])],
                ['conversaciones que quedan sin pedido', count($reapuntar['sin'])],
                ['pedidos a borrar', $leads->count()],
                ['actividades que caen en cascada', DB::table('lead_activities')->whereIn('lead_id', $leads)->count()],
                ['líneas de producto que caen en cascada', DB::table('lead_products')->whereIn('lead_id', $leads)->count()],
                ['etapas a borrar', $etapas->count()],
            ]
        );

        if ($seco) {
            $this->newLine();
            $this->info('Simulación. Corré sin --dry-run para aplicarlo.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($etapas, $leads, $reapuntar) {
                foreach ($reapuntar['con'] as $convId => $leadId) {
                    DB::table('whatsapp_conversations')->where('id', $convId)->update(['lead_id' => $leadId]);
                }

                DB::table('whatsapp_conversations')->whereIn('lead_id', $leads)->update(['lead_id' => null]);

                DB::table('leads')->whereIn('id', $leads)->delete();
                DB::table('lead_pipeline_stages')->whereIn('id', $etapas)->delete();
            });
        } catch (Throwable $e) {
            $this->error('No se borró nada: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($leads->count().' pedidos y '.$etapas->count().' etapas borrados. '
            .count($reapuntar['con']).' conversaciones quedaron apuntando a su pedido real.');

        return self::SUCCESS;
    }

    /**
     * A qué pedido queda apuntando cada conversación que hoy mira un descartado.
     *
     * @return array{con: array<int, int>, sin: array<int, int>}
     */
    private function conversacionesAReapuntar($leads): array
    {
        $con = [];
        $sin = [];

        $conversaciones = DB::table('whatsapp_conversations')
            ->whereIn('lead_id', $leads)
            ->get(['id', 'person_id', 'lead_id']);

        foreach ($conversaciones as $conv) {
            $real = DB::table('leads')
                ->where('person_id', $conv->person_id)
                ->whereNotIn('id', $leads)
                ->orderByDesc('id')
                ->value('id');

            if ($real) {
                $con[$conv->id] = (int) $real;
            } else {
                $sin[$conv->id] = (int) $conv->lead_id;
            }
        }

        return ['con' => $con, 'sin' => $sin];
    }
}
