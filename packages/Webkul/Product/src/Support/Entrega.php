<?php

namespace Webkul\Product\Support;

/**
 * Resuelve el tiempo y el lugar de entrega: de un ítem, y de una cotización
 * con varios.
 *
 * Las reglas son las de la reunión del 11/09 y viven como DATOS del producto
 * (`tiempo_entrega`, `lugar_entrega`, `entrega_domicilio_desde`) y de la
 * variante (`tiempo_entrega` como override). Acá solo se combinan.
 *
 * Lo comparten la pantalla de cotización, la API del agente y el PDF: si cada
 * uno lo calculara, tapas impresas tardarían 20 días en un lado e «inmediata»
 * en otro.
 */
class Entrega
{
    public const LUGAR_ALMACEN = 'Puesto almacén cliente';

    public const LUGAR_PLANTA = 'Recoge en planta';

    public const LUGAR_DOMICILIO = 'A domicilio';

    public const TIEMPO_INMEDIATA = 'Inmediata';

    /**
     * Tiempo y lugar para un ítem: el producto, cuántos lleva y, si la hay, la
     * variante.
     *
     *   stretch film · 60 rollos   → Inmediata · A domicilio       (≥ 50)
     *   stretch film · 20 rollos   → Inmediata · Puesto almacén cliente
     *   tapas con impresión · 30   → 20 días   · Recoge en planta  (override de la variante)
     *
     * «Recoge en planta» no se convierte en domicilio por cantidad: quien
     * recoge en planta, recoge en planta. El umbral solo mueve de almacén a
     * domicilio.
     *
     * @return array{tiempo: string, lugar: string}
     */
    public static function paraItem($product, int $cantidad, $variante = null): array
    {
        $tiempo = $variante?->tiempo_entrega ?: ($product->tiempo_entrega ?: self::TIEMPO_INMEDIATA);

        $lugar = $product->lugar_entrega ?: self::LUGAR_ALMACEN;

        $desde = $product->entrega_domicilio_desde;

        if ($lugar === self::LUGAR_ALMACEN && $desde !== null && $cantidad >= (int) $desde) {
            $lugar = self::LUGAR_DOMICILIO;
        }

        return ['tiempo' => $tiempo, 'lugar' => $lugar];
    }

    /**
     * Las condiciones de una cotización entera a partir de las de sus ítems.
     *
     * Un pedido tiene un solo bloque de condiciones, así que se combinan por lo
     * más restrictivo:
     *
     *   tiempo: el peor — tapas impresas arrastran a 20 días todo el pedido.
     *   lugar:  si algo se recoge en planta, todo se recoge en planta;
     *           si todo va a domicilio, va a domicilio;
     *           si no, puesto almacén cliente.
     *
     * @param  array<int, array{tiempo: string, lugar: string}>  $items
     * @return array{tiempo: string, lugar: string}
     */
    public static function paraCotizacion(array $items): array
    {
        if (! $items) {
            return ['tiempo' => self::TIEMPO_INMEDIATA, 'lugar' => self::LUGAR_ALMACEN];
        }

        $tiempos = array_column($items, 'tiempo');
        $lugares = array_column($items, 'lugar');

        return [
            'tiempo' => static::peorTiempo($tiempos),
            'lugar'  => in_array(self::LUGAR_PLANTA, $lugares, true)
                ? self::LUGAR_PLANTA
                : (count(array_unique($lugares)) === 1 && $lugares[0] === self::LUGAR_DOMICILIO
                    ? self::LUGAR_DOMICILIO
                    : self::LUGAR_ALMACEN),
        ];
    }

    /**
     * El tiempo más largo de una lista.
     *
     * Los tiempos son texto («Inmediata», «20 días», «3 semanas»): se ordena
     * por el número que traen, en días. «Inmediata» es 0. Un texto sin número
     * se trata como el más largo — si el asesor escribió «a confirmar», eso es
     * lo que tiene que salir, no «inmediata».
     */
    public static function peorTiempo(array $tiempos): string
    {
        $peor = self::TIEMPO_INMEDIATA;
        $peorDias = -1;

        foreach ($tiempos as $tiempo) {
            $dias = static::dias((string) $tiempo);

            if ($dias > $peorDias) {
                $peorDias = $dias;
                $peor = (string) $tiempo;
            }
        }

        return $peor;
    }

    protected static function dias(string $tiempo): int
    {
        $t = mb_strtolower(trim($tiempo));

        if ($t === '' || str_contains($t, 'inmediat')) {
            return 0;
        }

        if (! preg_match('/(\d+)/', $t, $m)) {
            return PHP_INT_MAX;
        }

        $n = (int) $m[1];

        return match (true) {
            str_contains($t, 'seman') => $n * 7,
            str_contains($t, 'mes')   => $n * 30,
            default                   => $n,
        };
    }
}
