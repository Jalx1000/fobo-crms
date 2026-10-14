<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién es el asesor asignado de un pedido: el distribuidor que reparte en esa ciudad.
 *
 * 🔴 El problema que resuelve: `LeadRepository::create()` asigna el pedido a quien esté
 * autenticado, y cuando el pedido lo crea el agente de IA por la API, el autenticado es
 * el DUEÑO DEL TOKEN —un administrador—. Así, todos los pedidos del agente quedaban a
 * nombre de un admin en vez del distribuidor de la zona, y el reparto no aparecía en la
 * lista de quien tiene que salir a entregarlo.
 *
 * La ciudad del pedido ya se conoce (`lead_pipeline_id`): lo único que faltaba era
 * usarla para decidir el dueño.
 */
class AsignacionDeZona
{
    /**
     * Si la tabla ya existe.
     *
     * El código y las migraciones se despliegan por separado, y entre uno y otro crear
     * un pedido no puede fallar: sin la tabla, la asignación queda como estaba.
     */
    protected function tablaLista(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('distribuidor_zonas');
    }

    /**
     * El distribuidor que reparte en esa ciudad, o null si no hay ninguno cargado.
     */
    public function distribuidorDe(?int $pipelineId): ?int
    {
        if (! $pipelineId || ! $this->tablaLista()) {
            return null;
        }

        $userId = DB::table('distribuidor_zonas')
            ->join('users', 'users.id', '=', 'distribuidor_zonas.user_id')
            // Un distribuidor dado de baja no puede quedar como responsable de un
            // reparto: el pedido desaparecería de la lista de todo el mundo.
            ->where('users.status', 1)
            ->where('distribuidor_zonas.lead_pipeline_id', $pipelineId)
            ->value('users.id');

        return $userId ? (int) $userId : null;
    }

    /**
     * Todas las zonas, para pantallas y para el payload del agente.
     *
     * @return array<int, array{pipeline_id:int, ciudad:string, user_id:int, distribuidor:string}>
     */
    public function zonas(): array
    {
        if (! $this->tablaLista()) {
            return [];
        }

        return DB::table('distribuidor_zonas')
            ->join('lead_pipelines', 'lead_pipelines.id', '=', 'distribuidor_zonas.lead_pipeline_id')
            ->join('users', 'users.id', '=', 'distribuidor_zonas.user_id')
            ->where('users.status', 1)
            ->orderBy('lead_pipelines.name')
            ->get([
                'distribuidor_zonas.lead_pipeline_id as pipeline_id',
                'lead_pipelines.name as ciudad',
                'users.id as user_id',
                'users.name as distribuidor',
            ])
            ->map(fn ($z) => [
                'pipeline_id'  => (int) $z->pipeline_id,
                'ciudad'       => (string) $z->ciudad,
                'user_id'      => (int) $z->user_id,
                'distribuidor' => (string) $z->distribuidor,
            ])
            ->all();
    }

    /**
     * ¿Hay que cambiarle el asesor a este pedido? Devuelve el id nuevo, o null.
     *
     * Las reglas, y por qué cada una:
     *
     *   sin asesor                     -> se asigna. Nadie lo ve en su lista.
     *   el asesor no es distribuidor   -> se reasigna. Es el caso del bug: lo creó el
     *                                     agente y quedó a nombre del dueño del token.
     *   es distribuidor de OTRA ciudad -> se reasigna. Chaile no reparte en Jujuy.
     *   es el distribuidor de ESTA     -> se deja. Puede haberlo puesto una persona.
     *
     * La última regla es la que evita pelearse con una asignación manual: si alguien
     * eligió a mano al distribuidor correcto, esto no lo toca nunca más.
     */
    public function asesorQueCorresponde($lead): ?int
    {
        if (! $this->tablaLista()) {
            return null;
        }

        $delaZona = $this->distribuidorDe($lead->lead_pipeline_id ? (int) $lead->lead_pipeline_id : null);

        // Ciudad sin distribuidor cargado (por ejemplo "Sin ciudad"): no hay a quién
        // asignarle, y dejarlo sin dueño es mejor que inventar uno.
        if (! $delaZona) {
            return null;
        }

        $actual = $lead->user_id ? (int) $lead->user_id : null;

        if ($actual === $delaZona) {
            return null;
        }

        if (! $actual) {
            return $delaZona;
        }

        return $this->esDistribuidorDe($actual, (int) $lead->lead_pipeline_id)
            ? null
            : $delaZona;
    }

    /** ¿Este usuario es el distribuidor de esta ciudad? */
    protected function esDistribuidorDe(int $userId, int $pipelineId): bool
    {
        return $this->distribuidorDe($pipelineId) === $userId;
    }
}
