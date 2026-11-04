<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
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
 *
 * La elección se recuerda en la cookie `global_ejecutivo` (20/10/2026), como
 * la ciudad (`global_pipeline_id`) y las fechas: sobrevive a recargar y se
 * comparte entre Tablero, Pedidos, Cotizaciones y Contactos. Si la URL trae
 * el parámetro, manda la URL (vacío = «Todos los ejecutivos»).
 */
class FiltroEjecutivo
{
    public const PARAMETRO = 'ejecutivo';

    public const COOKIE = 'global_ejecutivo';

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
        $valor = request()->query->has(self::PARAMETRO)
            ? request()->query(self::PARAMETRO)
            : request()->cookie(self::COOKIE);

        return is_numeric($valor) && self::disponible() ? (int) $valor : null;
    }

    /**
     * Guarda en la cookie la elección que vino en la URL (las pantallas que
     * navegan: Pedidos, Cotizaciones, Contactos). El tablero la guarda desde JS.
     * httpOnly=false para que el JS del tablero lea la misma cookie.
     */
    public static function recordar(): void
    {
        if (! request()->query->has(self::PARAMETRO) || ! self::disponible()) {
            return;
        }

        $valor = request()->query(self::PARAMETRO);

        Cookie::queue(is_numeric($valor)
            ? Cookie::make(self::COOKIE, (string) (int) $valor, 60 * 24 * 365, '/', null, false, false, false, 'Lax')
            : Cookie::forget(self::COOKIE));
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
