<?php

namespace Webkul\Whatsapp\Services;

use Carbon\CarbonImmutable;

/**
 * Horario de atención de la clínica, franjas del día y feriados, con el reloj
 * del CRM (hora de Bolivia). Lo usan el formulario de agendar (franjas, hora y
 * fecha válidas) y el contexto del agente (`fuera_de_horario`, `ahora`): el
 * modelo se equivoca con la hora, el CRM no.
 *
 * Todo sale de `whatsapp.clinic`: días 1 (lunes) a 7 (domingo) con [desde, hasta]
 * en HH:MM; un día sin entrada está cerrado.
 */
class ClinicHours
{
    protected const DAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
    }

    /** [desde, hasta] del día, o null si ese día no se atiende (domingo, feriado). */
    public static function hoursOn(string $date): ?array
    {
        if (in_array($date, self::holidays(), true)) {
            return null;
        }

        $day = CarbonImmutable::parse($date, config('app.timezone'))->isoWeekday();
        $hours = config("whatsapp.clinic.hours.{$day}");

        return is_array($hours) && count($hours) === 2 ? array_values($hours) : null;
    }

    public static function isOpenDay(string $date): bool
    {
        return self::hoursOn($date) !== null;
    }

    /**
     * ¿Se atiende a esa hora? Con fecha, según el horario de ESE día (15:00 un
     * sábado no); sin fecha, si entra en el horario de algún día de la semana.
     */
    public static function timeAllowed(string $time, ?string $date = null): bool
    {
        $ranges = $date !== null
            ? array_filter([self::hoursOn($date)])
            : array_filter((array) config('whatsapp.clinic.hours', []), fn ($h) => is_array($h) && count($h) === 2);

        foreach ($ranges as [$from, $until]) {
            if ($time >= $from && $time <= $until) {
                return true;
            }
        }

        return false;
    }

    /** ¿La clínica está cerrada en este momento (fuera de horario, domingo o feriado)? */
    public static function isClosed(?CarbonImmutable $at = null): bool
    {
        $at ??= self::now();

        return ! self::timeAllowed($at->format('H:i'), $at->toDateString());
    }

    /**
     * Franjas del día en orden: id => [desde, hasta, nombre].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function timeSlots(): array
    {
        return (array) config('whatsapp.clinic.time_slots', []);
    }

    /** La franja (manana, mediodia, tarde) de una hora HH:MM, o null. */
    public static function slotOf(string $time): ?string
    {
        foreach (self::timeSlots() as $id => [$from, $until]) {
            if ($time >= $from && $time <= $until) {
                return $id;
            }
        }

        return null;
    }

    /** "jueves 29/10/2026 10:53": lo que el agente necesita para hablar de "hoy" y "mañana". */
    public static function label(?CarbonImmutable $at = null): string
    {
        $at ??= self::now();

        return self::DAYS[$at->isoWeekday()].' '.$at->format('d/m/Y H:i');
    }

    /** Bloque `clinica` del contexto del agente. */
    public static function snapshot(): array
    {
        $now = self::now();
        $hours = self::hoursOn($now->toDateString());

        return [
            'ahora'            => self::label($now),
            'ahora_iso'        => $now->toIso8601String(),
            'zona_horaria'     => $now->getTimezone()->getName(),
            'fuera_de_horario' => self::isClosed($now),
            'horario_hoy'      => $hours ? $hours[0].' a '.$hours[1] : null,
        ];
    }

    /** @return array<int, string> fechas YYYY-MM-DD */
    protected static function holidays(): array
    {
        $raw = config('whatsapp.clinic.holidays', []);

        return array_values(array_filter(array_map('trim', is_array($raw) ? $raw : explode(',', (string) $raw))));
    }
}
