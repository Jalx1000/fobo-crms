<?php

namespace Webkul\Admin\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cookie;

/**
 * Filtro de fecha global compartido por el Tablero, Pedidos (leads) y
 * Prospectos (persons).
 *
 * El rango vive en una sola cookie sin encriptar y sin httpOnly, para que el
 * servidor y el JS del tablero lean exactamente el mismo valor. Formato:
 *
 *     from|to|savedAt
 *
 * donde savedAt es el timestamp unix de la última vez que el usuario eligió el
 * rango. El "from" persiste un año, pero el "to" solo vale hasta la medianoche
 * del día en que se eligió: al día siguiente vuelve a la fecha actual, así un
 * rango guardado ayer o hace semanas nunca deja el tablero anclado en el pasado.
 *
 * El corte es por día calendario (no 24 h rodantes) para que el tablero siempre
 * arranque en "hoy" a primera hora de la mañana, sin importar a qué hora del día
 * anterior se dejó puesto el filtro.
 */
class GlobalDateFilter
{
    /**
     * Nombre de la cookie. Debe seguir listada en EncryptCookies::$except para
     * que el JS pueda leerla.
     */
    const COOKIE = 'global_date_range';

    /**
     * Vigencia de la cookie, en minutos (lo que espera Cookie::queue).
     */
    const COOKIE_TTL = 60 * 24 * 365;

    /**
     * Resuelve el valor crudo de la cookie a un rango ['from' => , 'to' => ]
     * en formato Y-m-d, moviendo el "to" a hoy cuando ya venció.
     *
     * Devuelve null si no hay un rango usable guardado.
     */
    public static function resolve(?string $raw): ?array
    {
        if (! $raw) {
            return null;
        }

        [$from, $to, $savedAt] = array_pad(explode('|', $raw), 3, null);

        if (! $from || ! $to) {
            return null;
        }

        $today = Carbon::today()->format('Y-m-d');

        /**
         * Sin timestamp (cookies escritas antes de este cambio) o elegido en un
         * día anterior: el "to" guardado ya no representa "hasta ahora", así que
         * lo traemos a hoy.
         */
        $savedOn = is_numeric($savedAt)
            ? Carbon::createFromTimestamp((int) $savedAt, config('app.timezone'))->format('Y-m-d')
            : null;

        if ($savedOn !== $today) {
            $to = $today;
        }

        // Un "from" posterior al "to" produciría un rango vacío.
        if ($from > $to) {
            $from = $to;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Persiste el rango.
     *
     * El savedAt solo se renueva cuando el rango cambia de verdad: si el
     * usuario simplemente vuelve a entrar y la app re-aplica el rango guardado,
     * conservamos el timestamp original para que el vencimiento del "to" cuente
     * desde el día de la elección real y no se posponga en cada visita.
     */
    public static function remember(string $from, string $to): void
    {
        $savedAt = time();

        [$currentFrom, $currentTo, $currentSavedAt] = array_pad(
            explode('|', (string) request()->cookie(self::COOKIE)), 3, null
        );

        if (
            $from === $currentFrom
            && $to === $currentTo
            && is_numeric($currentSavedAt)
        ) {
            $savedAt = (int) $currentSavedAt;
        }

        // httpOnly=false para que el JS del tablero lea la misma cookie.
        Cookie::queue(self::COOKIE, $from.'|'.$to.'|'.$savedAt, self::COOKIE_TTL, '/', null, false, false, false, 'Lax');
    }

    /**
     * Olvida el rango compartido.
     */
    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
