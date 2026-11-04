<?php

namespace Webkul\Product\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Traduce entre el formulario y la tabla `product_prices`, y resuelve qué fila
 * corresponde a lo que el cliente pidió.
 *
 * Vive acá y no en el modelo porque la resuelve tanto el asesor (al ver la ficha
 * en el CRM) como el agente de IA (al contestar por WhatsApp), y las dos tienen
 * que dar el mismo resultado. Dos implementaciones se despegan a la primera
 * corrección que se hace en una sola.
 */
class ListaPrecios
{
    /**
     * Ejes del formulario a lista limpia de nombres.
     *
     * Se recortan los vacíos porque el formulario siempre muestra un eje de más
     * para poder agregar, igual que las características.
     */
    public static function normalizarEjes(mixed $entrada): ?array
    {
        $ejes = [];

        foreach ((array) $entrada as $eje) {
            $eje = trim((string) $eje);

            if ($eje !== '' && ! in_array($eje, $ejes, true)) {
                $ejes[] = $eje;
            }
        }

        return $ejes ?: null;
    }

    /**
     * Filas del formulario a filas listas para insertar.
     *
     * El formulario manda `precios[0][valores][Tamaño]`, `precios[0][precio]`…
     * Se descarta la fila que no tiene ningún valor de eje cargado: es la fila
     * en blanco del final, y guardarla dejaría un precio sin combinación que
     * después el agente no sabe a qué aplicar.
     *
     * Un precio vacío NO descarta la fila: una combinación sin precio es un caso
     * válido (se deriva) y es justamente lo que la `nota` explica.
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

            $precio = trim((string) ($fila['precio'] ?? ''));

            $filas[] = [
                'valores' => $valores,
                'precio'  => $precio === '' ? null : (float) str_replace(',', '.', $precio),
                'unidad'  => trim((string) ($fila['unidad'] ?? '')) ?: null,
                'minimo'  => trim((string) ($fila['minimo'] ?? '')) ?: null,
                'nota'    => trim((string) ($fila['nota'] ?? '')) ?: null,
                'orden'   => count($filas),
            ];
        }

        return $filas;
    }

    /**
     * Qué filas responden a lo que el cliente eligió.
     *
     * Se devuelve una colección y no una fila porque el agente casi nunca tiene
     * todos los ejes en el mismo turno: el cliente dice "de 50 litros" y todavía
     * no dijo si es para su tienda o para un hotel. Con criterios parciales
     * quedan varias candidatas, y ahí el agente tiene que repreguntar - devolver
     * la primera sería inventarle un canal al cliente.
     *
     * Los criterios que no son ejes del producto se ignoran en vez de vaciar el
     * resultado: el agente manda lo que entendió de la charla, y un dato de más
     * ("ciudad") no debe hacer desaparecer un precio que sí existe.
     */
    public static function filtrar(Collection $filas, array $criterios): Collection
    {
        foreach ($criterios as $eje => $buscado) {
            if ($buscado === null || $buscado === '') {
                continue;
            }

            $ejeReal = static::ejeEquivalente($filas, (string) $eje);

            if ($ejeReal === null) {
                continue;
            }

            $filas = $filas->filter(
                fn ($fila) => static::coincide((string) ($fila->valores[$ejeReal] ?? ''), (string) $buscado)
            );
        }

        return $filas->values();
    }

    /**
     * Suma la cantidad pedida a los criterios, para los productos con precio
     * por volumen (eje «Cantidad» con tramos «1-10», «11-30», «51+»).
     *
     * En los demás productos no cambia nada: `filtrar` ignora los criterios que
     * no son ejes del producto. Si el criterio ya vino, manda el que vino.
     */
    public static function conCantidad(array $criterios, mixed $cantidad): array
    {
        if (! is_numeric($cantidad)) {
            return $criterios;
        }

        foreach (array_keys($criterios) as $eje) {
            if (static::comparable((string) $eje) === 'cantidad') {
                return $criterios;
            }
        }

        return $criterios + ['Cantidad' => (string) (int) $cantidad];
    }

    /**
     * Si el valor de la fila es un tramo («1-10», «51+») y lo buscado es un
     * número, se compara por rango; si no, por escritura, como siempre.
     */
    protected static function coincide(string $valor, string $buscado): bool
    {
        if (is_numeric(trim($buscado))) {
            $n = (float) trim($buscado);

            if (preg_match('/^\s*(\d+)\s*[-–a]\s*(\d+)\s*$/u', $valor, $m)) {
                return $n >= (int) $m[1] && $n <= (int) $m[2];
            }

            if (preg_match('/^\s*(\d+)\s*(\+|o\s+m[aá]s)\s*$/iu', $valor, $m)) {
                return $n >= (int) $m[1];
            }
        }

        return static::comparable($valor) === static::comparable($buscado);
    }

    /**
     * El nombre de eje que usó el agente contra el que se cargó en el CRM.
     *
     * El agente manda "tamano" o "Tamaño" según cómo lo haya escrito el modelo;
     * el asesor cargó "Tamaño". Exigir la coincidencia exacta convertiría un
     * acento en un producto sin precio.
     */
    protected static function ejeEquivalente(Collection $filas, string $eje): ?string
    {
        $buscado = static::comparable($eje);

        foreach (array_keys((array) ($filas->first()?->valores ?? [])) as $real) {
            if (static::comparable((string) $real) === $buscado) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Forma canónica para comparar: sin acentos, sin mayúsculas y sin nada que
     * no sea letra o número.
     *
     * Así "50 L", "50l" y "50 litros"… no, "50 litros" no: eso sigue sin
     * coincidir, y está bien. Lo que se tolera es la escritura del mismo valor
     * (espacios, acentos, mayúsculas), no un sinónimo - un sinónimo aceptado de
     * más le haría cotizar al agente una combinación que el cliente no pidió.
     */
    public static function comparable(string $valor): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($valor))) ?? '';
    }
}
