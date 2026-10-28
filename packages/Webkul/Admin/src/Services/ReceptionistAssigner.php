<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\DB;

/**
 * Elige la recepcionista dueña de una cita que entra sola (la sync de SMD corre
 * sin usuario en sesión). Sin dueño el lead no lo ve nadie con permisos por
 * usuario, y a una cuenta técnica nadie lo atiende.
 *
 * Reparto por carga del día: le toca a la recepcionista activa con menos citas
 * de SMD asignadas hoy; en empate, a la de menor id. Se deriva de la BD en vez
 * de guardar un "turno", así que se acomoda solo cuando alguien se suma, se
 * desactiva o se le reasigna una cita a mano.
 */
class ReceptionistAssigner
{
    public function next(?int $leadSourceId): ?int
    {
        $receptionists = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', config('smd.assignment.role'))
            ->where('users.status', 1)
            ->orderBy('users.id')
            ->pluck('users.id');

        if ($receptionists->isEmpty()) {
            return null;
        }

        $assignedToday = DB::table('leads')
            ->whereIn('user_id', $receptionists)
            ->where('lead_source_id', $leadSourceId)
            ->where('created_at', '>=', now()->startOfDay())
            ->groupBy('user_id')
            ->pluck(DB::raw('COUNT(*)'), 'user_id');

        return $receptionists->sortBy(fn ($id) => [(int) ($assignedToday[$id] ?? 0), $id])->first();
    }
}
