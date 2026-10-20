<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Core\Services\AlcanceCiudades;

/**
 * En qué ciudades reparte un distribuidor: lo que se elige en "Distribuye en" del
 * formulario de usuario (Configuración → Usuarios).
 *
 * Antes se deducía del nombre del grupo, y el grupo hacía dos trabajos a la vez —en
 * qué ciudad reparte y qué ve—. Cinco distribuidores de Palpalá en el grupo
 * "Palpalá" terminaban viendo lo de los otros. Ahora son dos datos:
 *
 *   "Distribuye en"   -> en qué ciudades recibe y entrega pedidos (esta tabla).
 *   permiso + grupo   -> qué ve: individual, sólo lo suyo; grupo, lo de su EMPRESA.
 */
class ZonasDelDistribuidor
{
    public const ROL = 'Distribuidor';

    public function esRolDistribuidor(?int $roleId): bool
    {
        return $roleId !== null
            && DB::table('roles')->where('id', $roleId)->value('name') === self::ROL;
    }

    /** @return array<int, int> */
    public function ciudadesDe(int $userId): array
    {
        if (! Schema::hasTable('distribuidor_zonas')) {
            return [];
        }

        return DB::table('distribuidor_zonas')->where('user_id', $userId)->orderBy('lead_pipeline_id')
            ->pluck('lead_pipeline_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Los errores del formulario para un distribuidor, campo => mensaje. Vacío si
     * está bien o si no es distribuidor.
     *
     * @return array<string, string>
     */
    public function errores(?int $roleId, ?string $permiso, array $ciudades, array $grupos): array
    {
        if (! $this->esRolDistribuidor($roleId)) {
            return [];
        }

        $errores = [];

        if (! array_filter($ciudades)) {
            $errores['ciudades_distribucion'] = trans('admin::app.settings.users.index.create.distribuidor-sin-ciudad');
        }

        if ($permiso === 'global') {
            $errores['view_permission'] = trans('admin::app.settings.users.index.create.distribuidor-global');
        }

        /*
         * Con permiso de grupo, un grupo que se llama como una ciudad juntaría en una
         * misma "empresa" a todos los distribuidores de esa ciudad: es exactamente
         * cómo se veían entre sí.
         */
        if ($permiso === 'group' && $grupos) {
            $ciudades = DB::table('lead_pipelines')->pluck('name')
                ->map(fn ($n) => AlcanceCiudades::normalizar((string) $n))->all();

            $deCiudad = DB::table('groups')->whereIn('id', $grupos)->pluck('name')
                ->first(fn ($n) => in_array(AlcanceCiudades::normalizar((string) $n), $ciudades, true));

            if ($deCiudad) {
                $errores['groups[]'] = trans('admin::app.settings.users.index.create.distribuidor-grupo-ciudad', ['grupo' => $deCiudad]);
            }
        }

        return $errores;
    }

    /**
     * Deja las zonas del usuario exactamente en esas ciudades. Si no es distribuidor,
     * sin zonas: dejó de repartir.
     *
     * @param  array<int, int|string>  $ciudades
     */
    public function guardar(int $userId, ?int $roleId, array $ciudades): void
    {
        if (! Schema::hasTable('distribuidor_zonas')) {
            return;
        }

        $nuevas = $this->esRolDistribuidor($roleId)
            ? array_values(array_unique(array_map('intval', array_filter($ciudades))))
            : [];

        $actuales = $this->ciudadesDe($userId);

        if ($sobran = array_diff($actuales, $nuevas)) {
            DB::table('distribuidor_zonas')->where('user_id', $userId)->whereIn('lead_pipeline_id', $sobran)->delete();
        }

        foreach (array_diff($nuevas, $actuales) as $pipelineId) {
            DB::table('distribuidor_zonas')->insert([
                'lead_pipeline_id' => $pipelineId,
                'user_id'          => $userId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }
}
