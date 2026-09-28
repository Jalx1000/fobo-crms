<?php

namespace Webkul\Product\Support;

/**
 * Resuelve qué variante de un producto corresponde a lo que el cliente eligió,
 * y traduce el formulario de variantes a filas.
 *
 * Vive junto a ListaPrecios y usa su misma comparación tolerante: el asesor
 * carga "50 L" y el agente manda "50l". Que el precio y el código TR4 se
 * resuelvan con reglas distintas haría que una misma elección tenga precio y no
 * tenga código, o al revés.
 */
class Variantes
{
    /**
     * La variante para unos criterios, mirando SOLO los ejes de identidad.
     *
     * Criterios `{Tamaño: 50 L, Canal: HORECA, Zona: Santa Cruz}` sobre un
     * producto con `ejes_identidad = [Tamaño]` buscan la variante `{Tamaño: 50 L}`.
     * Filtrar por todos los criterios dejaría sin variante a una combinación
     * que sí la tiene, porque las variantes no saben de canales ni zonas.
     *
     * Null cuando no hay: el ítem se cotiza igual con los códigos vacíos.
     * Bloquear dejaría sin cotizar a todo producto al que todavía no le
     * cargaron los TR4.
     */
    public static function resolver($product, array $criterios)
    {
        $ejes = array_values((array) ($product->ejes_identidad ?? []));

        if (! $ejes || $product->variantes->isEmpty()) {
            return null;
        }

        // Solo los criterios que son ejes de identidad, con el nombre real del eje.
        $identidad = [];

        foreach ($criterios as $eje => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }

            foreach ($ejes as $real) {
                if (ListaPrecios::comparable((string) $real) === ListaPrecios::comparable((string) $eje)) {
                    $identidad[$real] = (string) $valor;
                }
            }
        }

        // Faltan ejes de identidad: no se adivina. "50 L" sin color no
        // identifica un hule.
        if (count($identidad) < count($ejes)) {
            return null;
        }

        return $product->variantes->first(function ($variante) use ($identidad) {
            foreach ($identidad as $eje => $valor) {
                $suyo = (string) (((array) $variante->valores)[$eje] ?? '');

                if (ListaPrecios::comparable($suyo) !== ListaPrecios::comparable($valor)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Ejes de identidad del formulario: subconjunto de los ejes de precio.
     *
     * Un eje de identidad que no sea eje de precio no tendría de dónde tomar
     * su valor al cotizar; se descarta en silencio en vez de guardar un eje
     * que nunca resuelve nada.
     */
    public static function normalizarEjes(mixed $entrada, array $ejesPrecio): ?array
    {
        $ejes = [];

        foreach ((array) $entrada as $eje) {
            $eje = trim((string) $eje);

            if ($eje !== '' && in_array($eje, $ejesPrecio, true) && ! in_array($eje, $ejes, true)) {
                $ejes[] = $eje;
            }
        }

        return $ejes ?: null;
    }

    /**
     * Filas del formulario a filas para insertar.
     *
     * Igual que las de precio: la fila sin ningún valor de eje es la vacía del
     * final y se descarta. Una fila con valores pero sin TR4 se conserva: es
     * una variante a la que todavía no le cargaron el código.
     */
    public static function normalizarFilas(mixed $entrada, array $ejes): array
    {
        $filas = [];

        foreach ((array) $entrada as $fila) {
            $valores = [];

            foreach ($ejes as $eje) {
                $valor = trim((string) ($fila['valores'][$eje] ?? ''));

                if ($valor !== '') {
                    $valores[$eje] = $valor;
                }
            }

            if (! $valores) {
                continue;
            }

            $filas[] = [
                'valores'        => $valores,
                'codigo_tr4'     => trim((string) ($fila['codigo_tr4'] ?? '')) ?: null,
                'codigo_barra'   => trim((string) ($fila['codigo_barra'] ?? '')) ?: null,
                'medida'         => trim((string) ($fila['medida'] ?? '')) ?: null,
                'unidad'         => trim((string) ($fila['unidad'] ?? '')) ?: 'PZA',
                'nombre_erp'     => trim((string) ($fila['nombre_erp'] ?? '')) ?: null,
                // La ficha llega como texto («Clave: valor» por línea) y se
                // guarda estructurada; ver FichaTecnica.
                'caracteristicas' => FichaTecnica::desdeTexto($fila['caracteristicas'] ?? null) ?: null,
                'tiempo_entrega' => trim((string) ($fila['tiempo_entrega'] ?? '')) ?: null,
                'orden'          => count($filas),
            ];
        }

        return $filas;
    }

    /**
     * Los valores de una variante en el orden de los ejes de identidad, para
     * mostrarla: MySQL normaliza el orden de las claves del JSON.
     */
    public static function etiqueta($product, $variante): string
    {
        $valores = (array) $variante->valores;
        $ordenados = [];

        foreach ((array) ($product->ejes_identidad ?? []) as $eje) {
            if (isset($valores[$eje])) {
                $ordenados[] = $valores[$eje];
            }
        }

        return implode(' · ', $ordenados ?: array_values($valores));
    }
}
