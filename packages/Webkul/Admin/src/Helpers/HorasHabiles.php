<?php

namespace Webkul\Admin\Helpers;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Tiempo entre dos momentos contando solo horas laborales: los días de
 * `cotizacion.dias_laborales` (lunes a viernes) en la franja de
 * `cotizacion.horario_atencion` (08:00–19:00). Feriados: no se consideran.
 *
 * Spec docs/specs/tiempo-en-responder.md: un pedido que llega el viernes a
 * las 18:00 y se mide el lunes a las 09:00 lleva 2 h, no 63.
 */
class HorasHabiles
{
    public static function segundos(CarbonInterface $desde, CarbonInterface $hasta): int
    {
        if ($hasta->lte($desde)) {
            return 0;
        }

        [$abre, $cierra] = self::franja();
        $dias = self::dias();

        $total = 0;
        $dia = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta);

        // Día por día: el tramo laboral de cada día, recortado a [desde, hasta].
        while ($dia->lte($fin)) {
            if (in_array($dia->dayOfWeekIso, $dias, true)) {
                $inicio = $dia->copy()->setTimeFromTimeString($abre)->max($desde);
                $cierre = $dia->copy()->setTimeFromTimeString($cierra)->min($fin);

                if ($cierre->gt($inicio)) {
                    $total += $inicio->diffInSeconds($cierre);
                }
            }

            $dia->addDay();
        }

        return $total;
    }

    /**
     * Largo de un día hábil (08:00–19:00 = 11 h): la unidad de «días» en la
     * distribución del tiempo de respuesta.
     */
    public static function segundosPorDia(): int
    {
        [$abre, $cierra] = self::franja();

        return max(1, Carbon::parse($abre)->diffInSeconds(Carbon::parse($cierra)));
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected static function franja(): array
    {
        $horario = (array) config('cotizacion.horario_atencion', []);

        return [(string) ($horario['desde'] ?? '08:00'), (string) ($horario['hasta'] ?? '19:00')];
    }

    /**
     * Días laborales ISO (1 = lunes … 7 = domingo).
     *
     * @return array<int, int>
     */
    protected static function dias(): array
    {
        return array_map('intval', (array) config('cotizacion.dias_laborales', [1, 2, 3, 4, 5]));
    }
}
