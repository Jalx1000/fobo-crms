<?php

namespace Webkul\Distribuidor\Services;

use Webkul\Core\Services\AlcanceCiudades;

/**
 * Qué ciudades y sucursales puede ver el distribuidor que está adelante del Punto.
 *
 * La pregunta "qué ciudades ve este usuario" la responde AlcanceCiudades, en Core,
 * porque la bandeja de WhatsApp necesita exactamente la misma respuesta. Lo que
 * queda acá es lo propio del Punto: traducir esas ciudades a las sucursales desde
 * las que se puede vender.
 *
 * El alcance se aplica en el SERVIDOR, en los lugares que devuelven datos
 * (ciudades, sucursales, pedidos). Filtrar sólo en la UI dejaría los pedidos de
 * otra ciudad a un request de distancia.
 */
class Alcance
{
    public function __construct(
        protected Catalogo $catalogo,
        protected AlcanceCiudades $alcance,
    ) {}

    /**
     * Los ids de ciudad (pipeline) que el usuario puede ver, o null si ve todas.
     *
     * @return array<int, int>|null
     */
    public function ciudadesPermitidas(?int $userId = null): ?array
    {
        return $this->alcance->ciudadesDe($userId);
    }

    public function puedeVerCiudad(int $pipelineId): bool
    {
        return $this->alcance->puedeVer($pipelineId);
    }

    /**
     * Las ciudades visibles, ya con nombre, para los filtros del Punto.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function ciudades(): array
    {
        return $this->alcance->ciudades();
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
