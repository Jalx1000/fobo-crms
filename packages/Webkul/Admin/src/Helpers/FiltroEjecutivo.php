<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filtro «Ejecutivo comercial» del módulo de pedidos (16/10/2026), al lado del
 * de ciudad. Va en la URL (`?ejecutivo=<id>`) como el de canal: el kanban y la
 * lista reenvían la URL a su endpoint, y la elección sobrevive a recargar.
 *
 * Solo achica lo que el usuario ya puede ver: el permiso de vista
 * (bouncer) se aplica antes, así que un asesor nunca llega a la cartera de
 * otro por acá.
 */
class FiltroEjecutivo
{
    public const PARAMETRO = 'ejecutivo';

    /**
     * Ejecutivos activos que el usuario puede ver, para el selector.
     *
     * @return Collection<int, object{id: int, name: string}>
     */
    public static function opciones(): Collection
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'Ejecutivo Comercial')
            ->where('users.status', 1)
            ->when(bouncer()->getAuthorizedUserIds(), fn ($q, $ids) => $q->whereIn('users.id', $ids))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
    }

    public static function elegido(): ?int
    {
        $valor = request()->query(self::PARAMETRO);

        return is_numeric($valor) ? (int) $valor : null;
    }

    /**
     * @template T
     *
     * @param  T  $query
     * @return T
     */
    public static function aplicar($query, string $columna = 'leads.user_id')
    {
        if ($userId = self::elegido()) {
            $query->where($columna, $userId);
        }

        return $query;
    }
}
