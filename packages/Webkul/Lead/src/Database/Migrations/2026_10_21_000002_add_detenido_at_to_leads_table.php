<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `leads.detenido_at`: cuándo el lead entró a una etapa que detiene el reloj de
 * «Tiempo en responder» (config reporting.stages.detienen_tiempo).
 *
 * Los leads que ya están en esas etapas se completan desde el historial de
 * actividades («Actualizado Etapa»): la fecha es la entrada al ÚLTIMO tramo
 * seguido en etapas que detienen. Si pasó de Sin interés a Desistimiento, el
 * reloj se detuvo al entrar a Sin interés. Si no hay historial, se usa
 * updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'detenido_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dateTime('detenido_at')->nullable()->after('first_stage_change_at');
            });
        }

        $etapas = DB::table('lead_pipeline_stages')
            ->whereIn('code', (array) config('reporting.stages.detienen_tiempo', []))
            ->get(['id', 'name']);

        if ($etapas->isEmpty()) {
            return;
        }

        $nombres = $etapas->pluck('name')->unique()->all();

        $leads = DB::table('leads')
            ->whereIn('lead_pipeline_stage_id', $etapas->pluck('id'))
            ->whereNull('detenido_at')
            ->get(['id', 'updated_at']);

        foreach ($leads as $lead) {
            $cambios = DB::table('activities')
                ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
                ->where('lead_activities.lead_id', $lead->id)
                ->where('activities.type', 'system')
                ->where('activities.title', 'Actualizado Etapa')
                ->orderBy('activities.created_at')
                ->orderBy('activities.id')
                ->get(['activities.created_at', 'activities.additional']);

            $desde = null;

            foreach ($cambios as $cambio) {
                $nueva = json_decode((string) $cambio->additional, true)['new']['label'] ?? null;

                if (in_array($nueva, $nombres, true)) {
                    $desde ??= $cambio->created_at;
                } else {
                    $desde = null;
                }
            }

            DB::table('leads')
                ->where('id', $lead->id)
                ->update(['detenido_at' => $desde ?? $lead->updated_at]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'detenido_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('detenido_at');
            });
        }
    }
};
