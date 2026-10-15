<?php

namespace Webkul\Admin\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Conversión de las fechas que manda SMD a la hora local de la clínica.
 *
 * SMD publica los eventos en UTC (`2026-07-28T15:30:00.000Z`) y el CRM guarda
 * `schedule_from` / `schedule_to` en hora boliviana, que es la que se muestra en
 * la agenda. La conversión estaba repetida en cuatro lugares como
 * `Carbon::parse($x)->setTimezone(config('app.timezone'))`, sin decir en ningún
 * lado que el origen es UTC.
 *
 * El detalle que importa: ese `parse()` es correcto SOLO mientras el string
 * traiga el offset explícito. Si SMD alguna vez manda `2026-07-28T15:30:00` a
 * secas, Carbon lo interpreta en la zona por defecto de la app -que ya es La
 * Paz- y el `setTimezone()` no hace nada: la cita queda agendada 4 horas tarde,
 * sin error y sin log. Acá eso se vuelve explícito: sin offset se asume UTC,
 * que es el contrato de SMD, y se deja constancia en el log.
 */
class SmdTime
{
    /**
     * Fecha de SMD (UTC) → Carbon en la zona de la clínica.
     */
    public static function toLocal(string $isoDate): Carbon
    {
        $tz = config('app.timezone');

        if (self::traeOffset($isoDate)) {
            return Carbon::parse($isoDate)->setTimezone($tz);
        }

        Log::warning('[SmdTime] Fecha de SMD sin offset, se asume UTC', [
            'fecha' => $isoDate,
        ]);

        return Carbon::parse($isoDate, 'UTC')->setTimezone($tz);
    }

    /**
     * Lo mismo, ya formateado para guardar en `activities`.
     */
    public static function toLocalString(string $isoDate): string
    {
        return self::toLocal($isoDate)->format('Y-m-d H:i:s');
    }

    /**
     * ¿El string dice en qué zona está? `Z` o `±HH:MM` al final.
     */
    private static function traeOffset(string $isoDate): bool
    {
        return (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($isoDate));
    }
}
