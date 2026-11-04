<?php

namespace Webkul\Warehouse\Services;

use Webkul\Warehouse\Models\WarehouseProxy;

/**
 * Dónde quedan las plantas, como lo necesita el agente.
 *
 * Un almacén por ciudad (hoy solo Santa Cruz) y una ubicación por planta, con
 * su enlace de Maps y los productos que salen de ahí. Va en cada mensaje al
 * agente: «¿dónde quedan?» llega muchas veces antes de que el cliente diga qué
 * producto quiere, y sin esto el agente no tenía de dónde sacar la dirección.
 */
class Ubicaciones
{
    /**
     * @return array{plantas: array<int, array<string, mixed>>, fuera_de_ciudad: string}
     */
    public function paraAgente(): array
    {
        $plantas = [];

        foreach (WarehouseProxy::modelClass()::with(['locations' => fn ($q) => $q->orderBy('id'), 'locations.productos:id,name,warehouse_location_id'])->orderBy('id')->get() as $almacen) {
            foreach ($almacen->locations as $ubicacion) {
                $plantas[] = array_filter([
                    'ciudad'    => $almacen->name,
                    'nombre'    => $ubicacion->name,
                    'maps'      => $ubicacion->maps_url,
                    'productos' => $ubicacion->productos->pluck('name')->values()->all(),
                ], fn ($v) => $v !== null && $v !== '' && $v !== []);
            }
        }

        return [
            'plantas'         => $plantas,
            'fuera_de_ciudad' => (string) config('cotizacion.envios_fuera_de_ciudad'),
        ];
    }
}
