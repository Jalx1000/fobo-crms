<?php

namespace Webkul\Product\Support;

use Illuminate\Support\Str;

/**
 * Traduce entre el formulario y la columna JSON `caracteristicas`.
 *
 * El formulario manda dos arrays paralelos (`clave[]` y `valor[]`) en vez de un
 * mapa, porque el asesor puede agregar filas con claves que no están en la
 * config. Un mapa obligaría a conocer todas las claves de antemano, que es
 * justo lo que no se puede.
 */
class Caracteristicas
{
    /**
     * Campos que ofrece el formulario para las categorías de un producto.
     *
     * `product_type` es un multiselect: un producto puede estar en más de una
     * categoría, y en ese caso se ofrece la unión de todas. Ofrecer solo la
     * primera dejaría campos inalcanzables sin que nadie entienda por qué.
     */
    public static function paraCategorias(array $categorias): array
    {
        $config = config('caracteristicas', []);

        $campos = $config['comunes'] ?? [];

        foreach ($categorias as $categoria) {
            $slug = Str::slug((string) $categoria);

            $campos += $config['por_categoria'][$slug] ?? [];
        }

        return $campos;
    }

    /**
     * Del formulario al JSON.
     *
     * Descarta las filas sin clave: el formulario siempre muestra una fila
     * vacía al final para poder agregar, y guardarla dejaría un {"": ""} que
     * después aparece como una característica sin nombre en la ficha.
     */
    public static function normalizar(?array $entrada): ?array
    {
        $claves = $entrada['clave'] ?? [];
        $valores = $entrada['valor'] ?? [];

        $salida = [];

        foreach ($claves as $i => $clave) {
            $clave = trim((string) $clave);
            $valor = trim((string) ($valores[$i] ?? ''));

            if ($clave === '' || $valor === '') {
                continue;
            }

            $salida[$clave] = $valor;
        }

        return $salida ?: null;
    }

    /**
     * Etiqueta legible de una clave, para mostrarla en la ficha.
     *
     * Una clave libre (que el asesor inventó) no está en la config: se muestra
     * como está, con guiones bajos convertidos a espacios.
     */
    public static function etiqueta(string $clave, array $campos): string
    {
        return $campos[$clave]['label'] ?? Str::title(str_replace('_', ' ', $clave));
    }
}
