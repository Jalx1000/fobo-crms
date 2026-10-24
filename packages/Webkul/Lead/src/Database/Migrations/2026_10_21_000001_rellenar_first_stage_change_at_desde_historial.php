<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rellena `leads.first_stage_change_at` desde el historial de actividades.
 *
 * Hasta el 21/10/2026 la fecha solo se fijaba al pasar a una etapa ganada,
 * perdida o «confirmado», y ningún nombre de etapa de esta instancia
 * coincidía: ningún lead la tenía, y «Tiempo en responder» medía hasta ahora a
 * leads ya atendidos.
 *
 * El historial sí lo registra: cada cambio de etapa deja una actividad
 * «Actualizado Etapa» con el nombre de la etapa anterior en `additional`. La
 * fecha es la PRIMERA salida de una etapa de no atendidos.
 *
 * Solo completa los vacíos: nunca pisa una fecha existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $etapas = DB::table('lead_pipeline_stages')
            ->whereIn('code', (array) config('reporting.stages.no_atendido', ['prospectos']))
            ->pluck('name')
            ->unique()
            ->values()
            ->all();

        if (! $etapas) {
            return;
        }

        $prefix = DB::getTablePrefix();

        $salidas = DB::table('activities')
            ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->whereNull('leads.first_stage_change_at')
            ->where('activities.type', 'system')
            ->where('activities.title', 'Actualizado Etapa')
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT({$prefix}activities.additional, '$.old.label'))"), $etapas)
            ->groupBy('lead_activities.lead_id')
            ->selectRaw("{$prefix}lead_activities.lead_id as lead_id, MIN({$prefix}activities.created_at) as salida")
            ->get();

        foreach ($salidas as $salida) {
            DB::table('leads')
                ->where('id', $salida->lead_id)
                ->whereNull('first_stage_change_at')
                ->update(['first_stage_change_at' => $salida->salida]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: no se puede distinguir lo rellenado de lo fijado después.
    }
};
