<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registra en `distribuidor_zonas` a los distribuidores dados de alta después de crearla.
 *
 * La tabla se sembró una vez, con el primer distribuidor de cada ciudad. Los que se
 * cargaron después (rol Distribuidor + grupo de su ciudad) no estaban, y el CRM les
 * mostraba todo lo de su ciudad. Sólo AGREGA: nadie pierde una zona por esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rol = DB::table('roles')->where('name', 'Distribuidor')->value('id');

        if (! $rol) {
            return;
        }

        $ciudades = DB::table('lead_pipelines')->get(['id', 'name'])
            ->mapWithKeys(fn ($c) => [mb_strtolower(trim($c->name)) => (int) $c->id]);

        $filas = DB::table('users')
            ->join('user_groups', 'user_groups.user_id', '=', 'users.id')
            ->join('groups', 'groups.id', '=', 'user_groups.group_id')
            ->where('users.role_id', $rol)
            ->where('users.status', 1)
            ->get(['users.id as user_id', 'groups.name as grupo']);

        foreach ($filas as $fila) {
            $pipelineId = $ciudades[mb_strtolower(trim($fila->grupo))] ?? null;

            if (! $pipelineId) {
                continue;
            }

            DB::table('distribuidor_zonas')->insertOrIgnore([
                'lead_pipeline_id' => $pipelineId,
                'user_id'          => $fila->user_id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: no se sabe qué filas existían antes.
    }
};
