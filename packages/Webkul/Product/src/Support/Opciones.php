<?php

namespace Webkul\Product\Support;

use Webkul\Attribute\Models\AttributeOptionProxy;

/**
 * Traduce el valor de un atributo `select` al texto que ve la gente.
 *
 * Un select guarda el ID de la fila en `attribute_options`, no su nombre: el
 * PDF que imprime `$quote->lugar_entrega` sacaría «24» en vez de «A domicilio».
 * Es el mismo problema que la plantilla ya resolvía a mano para `marca`.
 *
 * Cachea por id dentro del request: un PDF con varios selects pediría la misma
 * fila varias veces.
 */
class Opciones
{
    /** @var array<int, ?string> */
    protected static array $cache = [];

    public static function nombre(mixed $valor, ?string $porDefecto = null): ?string
    {
        if ($valor === null || $valor === '') {
            return $porDefecto;
        }

        // Ya es texto (cotizaciones viejas, o un campo de texto libre).
        if (! is_numeric($valor)) {
            return (string) $valor;
        }

        $id = (int) $valor;

        return static::$cache[$id] ??= AttributeOptionProxy::modelClass()::find($id)?->name ?? $porDefecto;
    }
}
