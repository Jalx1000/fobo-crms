<?php

namespace Webkul\Product\Support;

use Illuminate\Support\Facades\DB;

/**
 * Qué productos están activos (atributo «Estado», código `products`).
 *
 * Un producto inactivo solo se ve en el módulo de Productos: ni el Punto, ni el
 * buscador de productos de pedidos y cotizaciones, ni la API, ni el catálogo que
 * recibe el agente. Una sola regla, en un solo lugar, para que ninguna pantalla
 * ofrezca algo que el negocio dio de baja (pasó con la bomba: inactiva y
 * ofrecida igual).
 *
 * Inactivo es «Estado = No». Un producto sin el campo cargado cuenta como
 * activo: los productos viejos no tenían el atributo y esconderlos rompería
 * pedidos en curso.
 */
class ProductosActivos
{
    public const ATRIBUTO = 'products';

    /**
     * Los ids de los productos inactivos.
     *
     * @return array<int, int>
     */
    public static function inactivos(): array
    {
        return self::consultaInactivos()->pluck('entity_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function esActivo(int $productId): bool
    {
        return ! in_array($productId, self::inactivos(), true);
    }

    /**
     * Saca los inactivos de una consulta de productos.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function aplicar($query, string $columna = 'products.id')
    {
        return $query->whereNotIn($columna, self::consultaInactivos());
    }

    protected static function consultaInactivos()
    {
        return DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->where('attributes.code', self::ATRIBUTO)
            ->where('attributes.entity_type', 'products')
            ->where('attribute_values.entity_type', 'products')
            ->where('attribute_values.boolean_value', 0)
            ->select('attribute_values.entity_id');
    }
}
