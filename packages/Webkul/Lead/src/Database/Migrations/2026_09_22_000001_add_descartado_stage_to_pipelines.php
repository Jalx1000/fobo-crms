<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Etapa "Descartado" en todos los pipelines.
 *
 * Existe para los pedidos que el CRM abre solo cuando alguien escribe por
 * WhatsApp y que después quedan sin contenido porque el pedido real se registró
 * aparte. No sirve ninguna de las etapas que ya estaban: "Cliente sin interés"
 * afirma algo que el cliente nunca dijo, y "Pedidos cancelados" implica que
 * hubo un pedido.
 *
 * El code va sin acento ni eñe a propósito: las etapas viejas se generaron con
 * un slugify que dejó "cliente-sin-inters", y un code con caracteres raros es
 * una fuente de bugs difíciles de ver.
 */
return new class extends Migration
{
    public const CODE = 'descartado';

    public function up(): void
    {
        $pipelines = DB::table('lead_pipelines')->pluck('id');

        foreach ($pipelines as $pipelineId) {
            $existe = DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', self::CODE)
                ->exists();

            if ($existe) {
                continue;
            }

            // Al final del kanban: es un cierre, no un paso del embudo.
            $ultimo = (int) DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $pipelineId)
                ->max('sort_order');

            DB::table('lead_pipeline_stages')->insert([
                'code'             => self::CODE,
                'name'             => 'Descartado',
                // 0: un pedido descartado no tiene ninguna chance de cerrarse.
                'probability'      => 0,
                'sort_order'       => $ultimo + 1,
                'lead_pipeline_id' => $pipelineId,
            ]);
        }
    }

    public function down(): void
    {
        /*
         * Sólo se borra si NINGÚN pedido quedó ahí. Si los hay, revertir los
         * dejaría apuntando a una etapa inexistente y desaparecerían del
         * kanban sin que nadie se entere.
         */
        $etapas = DB::table('lead_pipeline_stages')->where('code', self::CODE)->pluck('id');

        if (DB::table('leads')->whereIn('lead_pipeline_stage_id', $etapas)->exists()) {
            throw new RuntimeException(
                'Hay pedidos en la etapa "Descartado". Movelos antes de revertir esta migración.'
            );
        }

        DB::table('lead_pipeline_stages')->whereIn('id', $etapas)->delete();
    }
};
