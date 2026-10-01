<?php

namespace Webkul\Product\Support;

/**
 * Traduce la ficha técnica de una variante entre el textarea y el JSON.
 *
 * El asesor la pega tal como se la pasa el proveedor:
 *
 *     Dimensiones del rollo
 *     Color: Transparente
 *     Ancho: 500 mm ±5 mm
 *
 * Una línea con `clave: valor` es un dato; una línea sin dos puntos es un
 * encabezado. Se guarda como LISTA y no como mapa porque el orden y los
 * encabezados son parte de cómo se lee la ficha, y porque una misma clave
 * («Peso», «Diámetro») aparece dos veces: una para el rollo y otra para el cono.
 */
class FichaTecnica
{
    /**
     * Del textarea al JSON.
     *
     * @return array<int, array{clave: string, valor: ?string}>
     */
    public static function desdeTexto(?string $texto): array
    {
        $entradas = [];

        foreach (preg_split('/\R/', (string) $texto) as $linea) {
            $linea = trim($linea);

            if ($linea === '') {
                continue;
            }

            // Solo el PRIMER `:` separa: «Longitud: 510 mm ± 1 mm» no se parte
            // más, y una hora («Entrega: 8:00») tampoco.
            $partes = explode(':', $linea, 2);

            $clave = trim($partes[0]);
            $valor = isset($partes[1]) ? trim($partes[1]) : null;

            if ($clave === '') {
                continue;
            }

            $entradas[] = ['clave' => $clave, 'valor' => $valor === '' ? null : $valor];
        }

        return $entradas;
    }

    /**
     * Del JSON al textarea, para volver a editarla tal como se cargó.
     */
    public static function aTexto(mixed $ficha): string
    {
        $lineas = [];

        foreach ((array) $ficha as $entrada) {
            $clave = trim((string) ($entrada['clave'] ?? ''));

            if ($clave === '') {
                continue;
            }

            $valor = $entrada['valor'] ?? null;

            $lineas[] = $valor === null || $valor === '' ? $clave : $clave.': '.$valor;
        }

        return implode("\n", $lineas);
    }

    /**
     * La ficha en una línea, para el chat del agente y los listados.
     *
     * Los encabezados se omiten: sin los saltos de línea no separan nada y
     * quedarían como palabras sueltas en medio de los datos.
     */
    public static function resumen(mixed $ficha): string
    {
        $datos = [];

        foreach ((array) $ficha as $entrada) {
            if (! empty($entrada['valor'])) {
                $datos[] = $entrada['clave'].': '.$entrada['valor'];
            }
        }

        return implode(' · ', $datos);
    }
}
