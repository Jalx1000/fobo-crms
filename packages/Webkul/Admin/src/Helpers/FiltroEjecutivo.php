<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filtro «Ejecutivo comercial» del módulo de pedidos (16/10/2026), al lado del
 * de ciudad. Va en la URL (`?ejecutivo=<id>`) como el de canal: el kanban y la
 * lista reenvían la URL a su endpoint, y la elección sobrevive a recargar.
 *
 * Solo para quien ve el total (Administrador y Supervisores; spec
 * docs/specs/pedidos-filtro-ejecutivo.md, B1–B3): el ejecutivo comercial no
 * ve el selector y el parámetro se le ignora, así una URL compartida por un
 * supervisor le muestra igual sus pedidos. Además el permiso de vista
 * (bouncer) se aplica antes: nadie llega por acá a una cartera ajena.
 */
class FiltroEjecutivo
{
    public const PARAMETRO = 'ejecutivo';

    public static function disponible(): bool
    {
        return app(Alcance::class)->veTotal();
    }

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

        return is_numeric($valor) && self::disponible() ? (int) $valor : null;
    }

    /**
     * De quién son los datos del tablero: el ejecutivo ve los suyos (Alcance);
     * Administrador y Supervisores, los del ejecutivo elegido en el selector
     * del tablero, o todos (null). Una sola regla para todas las tarjetas y
     * el Excel (spec pedidos-filtro-ejecutivo, «Tablero»).
     */
    public static function usuarioDelTablero(): ?int
    {
        return app(Alcance::class)->userId() ?? self::elegido();
    }

    /**
     * Limita una consulta del tablero a usuarioDelTablero(), si lo hay.
     *
     * @template T
     *
     * @param  T  $query
     * @return T
     */
    public static function limitarTablero($query, string $columna = 'leads.user_id')
    {
        if (! is_null($userId = self::usuarioDelTablero())) {
            $query->where($columna, $userId);
        }

        return $query;
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
