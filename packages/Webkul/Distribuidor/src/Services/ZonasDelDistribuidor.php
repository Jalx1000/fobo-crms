<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las ciudades en que reparte un distribuidor, tomadas de cómo se lo cargó en el CRM.
 *
 * Un distribuidor se registra en Configuración → Usuarios: rol "Distribuidor" y, como
 * grupo, el de su ciudad (el grupo se llama igual que la ciudad). Pero el aislamiento
 * y el reparto leen `distribuidor_zonas`, que sólo se sembró una vez al crear la tabla.
 * Cada distribuidor dado de alta después quedaba fuera: el CRM lo trataba como
 * encargado de su grupo y le mostraba todo lo de su ciudad, incluido lo del otro
 * distribuidor.
 *
 * Esto mantiene la tabla igual a lo que dicen el rol y los grupos cada vez que se
 * guarda el usuario, así no hay un segundo lugar que alguien tenga que acordarse de
 * cargar.
 */
class ZonasDelDistribuidor
{
    public const ROL = 'Distribuidor';

    /**
     * Deja las zonas del usuario iguales a sus grupos de ciudad.
     *
     *   rol Distribuidor, con grupos de ciudad -> exactamente esas ciudades.
     *   rol Distribuidor, sin grupo de ciudad  -> no se toca: borrarle las zonas por
     *                                             un grupo mal elegido lo dejaría sin
     *                                             sus clientes de un clic.
     *   otro rol                               -> sin zonas: dejó de repartir.
     *
     * @return array{agregadas: int, quitadas: int}
     */
    public function sincronizar(int $userId): array
    {
        $resultado = ['agregadas' => 0, 'quitadas' => 0];

        if (! Schema::hasTable('distribuidor_zonas')) {
            return $resultado;
        }

        $actuales = DB::table('distribuidor_zonas')->where('user_id', $userId)->pluck('lead_pipeline_id')
            ->map(fn ($id) => (int) $id)->all();

        if (! $this->tieneRolDistribuidor($userId)) {
            $resultado['quitadas'] = DB::table('distribuidor_zonas')->where('user_id', $userId)->delete();

            return $resultado;
        }

        $ciudades = $this->ciudadesDeSusGrupos($userId);

        if (! $ciudades) {
            return $resultado;
        }

        foreach (array_diff($ciudades, $actuales) as $pipelineId) {
            DB::table('distribuidor_zonas')->insert([
                'lead_pipeline_id' => $pipelineId,
                'user_id'          => $userId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $resultado['agregadas']++;
        }

        if ($sobran = array_diff($actuales, $ciudades)) {
            $resultado['quitadas'] = DB::table('distribuidor_zonas')
                ->where('user_id', $userId)
                ->whereIn('lead_pipeline_id', $sobran)
                ->delete();
        }

        return $resultado;
    }

    public function tieneRolDistribuidor(int $userId): bool
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $userId)
            ->where('roles.name', self::ROL)
            ->exists();
    }

    /**
     * Las ciudades cuyos nombres coinciden con los grupos del usuario.
     *
     * Sin distinguir mayúsculas ni espacios de más: "san salvador de jujuy " es la
     * misma ciudad, y un error de tipeo así no puede dejar a alguien viendo de más.
     *
     * @return array<int, int>
     */
    protected function ciudadesDeSusGrupos(int $userId): array
    {
        $normalizar = fn (?string $nombre) => mb_strtolower(trim((string) $nombre));

        $grupos = DB::table('user_groups')
            ->join('groups', 'groups.id', '=', 'user_groups.group_id')
            ->where('user_groups.user_id', $userId)
            ->pluck('groups.name')
            ->map($normalizar)
            ->all();

        if (! $grupos) {
            return [];
        }

        return DB::table('lead_pipelines')->get(['id', 'name'])
            ->filter(fn ($ciudad) => in_array($normalizar($ciudad->name), $grupos, true))
            ->map(fn ($ciudad) => (int) $ciudad->id)
            ->values()
            ->all();
    }
}
