<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;

/**
 * Qué ciudades puede ver el distribuidor que está adelante del Punto.
 *
 * Sin filas asignadas = todas. Es el default a propósito: un distribuidor recién
 * creado tiene que poder trabajar antes de que alguien se acuerde de asignarle
 * ciudades, y el caso de una sola ciudad no necesita configuración.
 *
 * El alcance se aplica en el SERVIDOR, en los tres lugares que devuelven datos
 * (ciudades, sucursales, pedidos). Filtrar sólo en la UI dejaría los pendientes
 * de otra ciudad a un request de distancia.
 */
class Alcance
{
    public function __construct(protected Catalogo $catalogo) {}

    /**
     * Los ids de ciudad (pipeline) que el usuario puede ver, o null si ve todas.
     *
     * Null y no "todas las que existen": así quien consulta puede distinguir "sin
     * restricción" de "restringido a esta lista", y una ciudad nueva aparece sola
     * para los no restringidos.
     *
     * @return array<int, int>|null
     */
    public function ciudadesPermitidas(?int $userId = null): ?array
    {
        $userId ??= auth()->guard('user')->id();

        if (! $userId) {
            return [];
        }

        $ids = DB::table('distribuidor_ciudades')
            ->where('user_id', $userId)
            ->pluck('lead_pipeline_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids ?: null;
    }

    public function puedeVerCiudad(int $pipelineId): bool
    {
        $permitidas = $this->ciudadesPermitidas();

        return $permitidas === null || in_array($pipelineId, $permitidas, true);
    }

    /**
     * Las ciudades visibles, ya con nombre, para los filtros del Punto.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function ciudades(): array
    {
        $excluidas = (array) config('whatsapp.handoff.exclude_cities', []);
        $permitidas = $this->ciudadesPermitidas();

        return DB::table('lead_pipelines')
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->reject(fn ($p) => in_array($p->name, $excluidas, true))
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name])
            ->values()
            ->all();
    }

    /**
     * Las sucursales desde las que puede vender: sólo las de sus ciudades.
     *
     * Una sucursal cuya ciudad no resuelve queda afuera. Vender desde ahí abriría
     * el pedido sin ciudad, y en este CRM la ciudad es el pipeline: el pedido no
     * aparecería en ningún tablero.
     *
     * @return array<int, array{id:int, name:string, ciudad_id:?int}>
     */
    public function sucursales(): array
    {
        $visibles = array_column($this->ciudades(), 'id');

        return array_values(array_filter(
            $this->catalogo->sucursales(),
            fn ($s) => $s['ciudad_id'] !== null && in_array($s['ciudad_id'], $visibles, true),
        ));
    }
}
