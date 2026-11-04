<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Etapas del pipeline de Citas de Tessa. Garantiza la etapa "Cita Completada"
 * (atendida) y un orden lógico:
 *   Consultas → Cita Confirmada → Cita Completada → Cita Perdida → Cita Cancelada
 *
 * "Cita Completada" se fija en id=5 para calzar con SMD_STAGE_COMPLETED=5
 * (ver config/smd.php stage_map). Idempotente: updateOrInsert por code.
 */
class TessaPipelineStagesSeeder extends Seeder
{
    public function run(): void
    {
        $pipelineId = DB::table('lead_pipelines')->orderBy('id')->value('id') ?? 1;

        // code => [id (null = autoincrement), name, probability, sort_order]
        $stages = [
            'consultas'       => [null, 'Consultas', 0, 1],
            'cita-confirmada' => [null, 'Cita Confirmada', 100, 2],
            'cita-completada' => [5, 'Cita Completada', 100, 3],
            'cita-perdida'    => [null, 'Cita Perdida', 0, 4],
            'cita-cancelada'  => [null, 'Cita Cancelada', 0, 5],
        ];

        foreach ($stages as $code => [$id, $name, $probability, $sort]) {
            $exists = DB::table('lead_pipeline_stages')->where('code', $code)->first();

            $values = [
                'name'             => $name,
                'probability'      => $probability,
                'sort_order'       => $sort,
                'lead_pipeline_id' => $pipelineId,
            ];

            if ($exists) {
                DB::table('lead_pipeline_stages')->where('id', $exists->id)->update($values);

                continue;
            }

            // Nueva: fija el id solo si está libre (para "Cita Completada" = 5).
            if ($id && ! DB::table('lead_pipeline_stages')->where('id', $id)->exists()) {
                $values['id'] = $id;
            }

            DB::table('lead_pipeline_stages')->insert($values + ['code' => $code]);
        }

        $this->command?->info('Tessa: etapas del pipeline de Citas aseguradas (incl. "Cita Completada").');
    }
}
