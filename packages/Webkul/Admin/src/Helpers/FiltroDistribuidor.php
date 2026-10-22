<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Core\Services\Distribuidores;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Models\ProductProxy as LeadProductProxy;

/**
 * El filtro «Distribuidor» del tablero.
 *
 * Viaja como la ciudad: en la cookie `global_distribuidor_id` (o el parámetro
 * `distribuidor_id`), y lo resuelve DashboardController en cada consulta.
 *
 * Se aplica con scopes globales sobre pedidos, productos de pedidos y clientes,
 * activos SOLO durante la consulta del tablero (`activar`): así llega parejo a
 * todas las tarjetas sin tocar cada consulta, y no se filtra nada fuera del
 * tablero aunque la cookie siga puesta.
 *
 * Solo lo usan administradores y encargados: un distribuidor ya ve solo lo suyo.
 */
class FiltroDistribuidor
{
    public const COOKIE = 'global_distribuidor_id';

    protected static ?int $activo = null;

    /** Si el usuario en sesión puede filtrar por distribuidor. */
    public static function disponible(): bool
    {
        $usuario = auth()->guard('user')->user();

        return $usuario !== null && ! app(Distribuidores::class)->esDistribuidor((int) $usuario->id);
    }

    /**
     * Los distribuidores para el desplegable, con sus ciudades.
     *
     * @return array<int, array{id:int, name:string, ciudades:string}>
     */
    public static function opciones(): array
    {
        if (! self::disponible() || ! Schema::hasTable('distribuidor_zonas')) {
            return [];
        }

        return DB::table('distribuidor_zonas')
            ->join('users', 'users.id', '=', 'distribuidor_zonas.user_id')
            ->join('lead_pipelines', 'lead_pipelines.id', '=', 'distribuidor_zonas.lead_pipeline_id')
            ->where('users.status', 1)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'lead_pipelines.name as ciudad'])
            ->groupBy('id')
            ->map(fn ($filas) => [
                'id'       => (int) $filas->first()->id,
                'name'     => (string) $filas->first()->name,
                'ciudades' => $filas->pluck('ciudad')->unique()->implode(', '),
            ])
            ->values()
            ->all();
    }

    /** El distribuidor elegido en esta consulta, o null. */
    public static function elegido(Request $request): ?int
    {
        if (! self::disponible()) {
            return null;
        }

        $valor = $request->has('distribuidor_id')
            ? $request->input('distribuidor_id')
            : $request->cookie(self::COOKIE);

        if (! is_numeric($valor)) {
            return null;
        }

        return app(Distribuidores::class)->esDistribuidor((int) $valor) ? (int) $valor : null;
    }

    /**
     * Filtra lo que lee el tablero a ese distribuidor: sus pedidos, los productos
     * de sus pedidos y sus clientes, hasta `desactivar()`.
     *
     * Los scopes se registran una sola vez y leen `$activo` cada vez: un scope
     * global queda pegado a la clase mientras viva el proceso (un worker, los
     * tests), así que el que lo apaga es `desactivar()`, no el fin del pedido.
     */
    public static function activar(?int $userId): void
    {
        self::$activo = $userId ?: null;

        /*
         * Se pregunta a cada modelo y no a una bandera propia: Laravel vacía los
         * scopes globales al reiniciar los modelos (entre tests, por ejemplo), y
         * una bandera seguiría diciendo «ya está» con el scope borrado.
         */
        if (! self::$activo || LeadProxy::modelClass()::hasGlobalScope('tablero_distribuidor')) {
            return;
        }

        LeadProxy::modelClass()::addGlobalScope('tablero_distribuidor', function ($query) {
            if (self::$activo) {
                $query->where($query->getModel()->qualifyColumn('user_id'), self::$activo);
            }
        });

        LeadProductProxy::modelClass()::addGlobalScope('tablero_distribuidor', function ($query) {
            if (self::$activo) {
                $query->whereIn(
                    $query->getModel()->qualifyColumn('lead_id'),
                    DB::table('leads')->select('id')->where('user_id', self::$activo),
                );
            }
        });

        PersonProxy::modelClass()::addGlobalScope('tablero_distribuidor', function ($query) {
            if (self::$activo) {
                $query->where($query->getModel()->qualifyColumn('user_id'), self::$activo);
            }
        });
    }

    /** Apaga el filtro: lo que se consulte después ya no se filtra. */
    public static function desactivar(): void
    {
        self::$activo = null;
    }

    /** El distribuidor activo en esta consulta, o null. */
    public static function activo(): ?int
    {
        return self::$activo;
    }

    /**
     * Las tarjetas «por encargado» listan a todos los usuarios; con el filtro,
     * solo al distribuidor elegido (si no, son barras en cero).
     */
    public static function acotarUsuarios($query, string $columna = 'id')
    {
        return self::$activo ? $query->where($columna, self::$activo) : $query;
    }
}
