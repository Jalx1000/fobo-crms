<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le da etapas al pipeline «Sin ciudad», copiadas de una ciudad.
 *
 * El pedido que se abre con el primer mensaje (LeadOpener) va a «Sin ciudad» cuando
 * todavía no se sabe la zona, y un pipeline sin etapas no puede tener pedidos. Con
 * los mismos `code` que las ciudades, cuando el agente fija la zona el pedido se muda
 * a esa ciudad y conserva su etapa (CitySyncService remapea por code).
 *
 * Solo si «Sin ciudad» existe y todavía no tiene etapas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sinCiudad = DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');

        if (! $sinCiudad || DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $sinCiudad)->exists()) {
            return;
        }

        $modelo = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', '!=', $sinCiudad)
            ->value('lead_pipeline_id');

        if (! $modelo) {
            return;
        }

        foreach (DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $modelo)->orderBy('sort_order')->get() as $etapa) {
            DB::table('lead_pipeline_stages')->insert([
                'code'             => $etapa->code,
                'name'             => $etapa->name,
                'probability'      => $etapa->probability,
                'sort_order'       => $etapa->sort_order,
                'lead_pipeline_id' => $sinCiudad,
            ]);
        }
    }

    public function down(): void
    {
        $sinCiudad = DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');

        if ($sinCiudad && ! DB::table('leads')->where('lead_pipeline_id', $sinCiudad)->exists()) {
            DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $sinCiudad)->delete();
        }
    }
};
