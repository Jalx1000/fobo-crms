<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Services\DoctorAvailabilityService;

/**
 * Resuelve la disponibilidad REAL de un doctor combinando ShareMeData (fuente de
 * verdad de la agenda) con las citas locales del CRM:
 *
 *     disponibilidad = slots_libres_SMD  −  citas_locales_no_canceladas
 *
 * Si SMD no se puede consultar (caído, doctor sin vínculo, o flag desactivado)
 * degrada a la disponibilidad local (doctor_shifts − activities) e informa el
 * motivo en la respuesta (campos `source`, `degraded`, `reason`).
 */
class AvailabilityResolverService
{
    public function __construct(
        protected ShareMeDataService $smd,
        protected DoctorRepository $doctorRepository,
        protected DoctorAvailabilityService $localAvailability,
    ) {}

    /**
     * @param  array{specialty?:string, subsidiary?:string, duration_minutes?:int}  $opts
     * @return array<string, mixed>
     */
    public function resolve(int $doctorId, Carbon $startDate, int $days, array $opts = []): array
    {
        $doctor = $this->doctorRepository->with(['specialties', 'branch'])->find($doctorId);
        $duration = (int) ($opts['duration_minutes'] ?? 0);

        // Zona horaria de la sucursal del doctor (Asunción ≠ Santa Cruz). Fallback
        // al default de la app, con lo que para datos sin sucursal nada cambia.
        $tz = $this->resolveTimezone($doctor);

        // Caso 4: integración SMD desactivada globalmente.
        if (! config('smd.validate_availability', true)) {
            return $this->localResult($doctorId, $startDate, $days, $duration, 'smd_disabled', $tz);
        }

        // Caso 3: doctor sin vínculo con SMD → no se puede consultar el catálogo.
        if (! $doctor || empty($doctor->unique_id)) {
            return $this->localResult($doctorId, $startDate, $days, $duration, 'doctor_unlinked', $tz);
        }

        $from = $startDate->copy()->startOfDay();
        $to = $startDate->copy()->addDays($days - 1)->endOfDay();

        $specialties = ! empty($opts['specialty'])
            ? [$opts['specialty']]
            : ($doctor->specialties->pluck('name')->filter()->values()->toArray() ?: ['General']);

        $subsidiary = $opts['subsidiary'] ?? config('smd.default_subsidiary', 'Santa Cruz');

        // ── Recolectar intervalos libres de SMD (unión de especialidades) ──────
        $smdByDay = []; // 'Y-m-d' => [ [Carbon start, Carbon end], ... ]
        $networkFailure = false;

        foreach ($specialties as $spec) {
            $slots = $this->smd->checkAvailability(
                $doctor->unique_id, $spec, $subsidiary,
                $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')
            );

            $last = $this->smd->getLastResponse();

            // lastResponse === null ⇒ excepción de red dentro de checkAvailability.
            // status >= 500 ⇒ error de servidor. Ambos cuentan como fallo SMD.
            if ($last === null || (int) ($last['status'] ?? 500) >= 500) {
                $networkFailure = true;

                continue;
            }

            $smdByDay = array_merge_recursive($smdByDay, self::parseSmdSlots((array) $slots, $tz));
        }

        // Caso 2: SMD falló y no obtuvimos NADA → fallback local.
        if ($networkFailure && empty($smdByDay)) {
            return $this->localResult($doctorId, $startDate, $days, $duration, 'smd_unavailable', $tz);
        }

        return $this->scheduleFromSmd($doctorId, $startDate, $days, $smdByDay, $duration, $tz);
    }

    /**
     * Zona horaria efectiva para un doctor: la de su sucursal, o el default.
     */
    protected function resolveTimezone($doctor): string
    {
        return $doctor?->branch?->timezone ?: config('app.timezone', 'America/La_Paz');
    }

    /**
     * Intervalos libres de SMD de un doctor ('slots' de la respuesta de
     * /schedule/availability) agrupados por día local.
     *
     * @return array<string, array<int, array{0:Carbon,1:Carbon}>>
     */
    public static function parseSmdSlots(array $slots, string $tz): array
    {
        $byDay = [];

        foreach ($slots as $daySlots) {            // [ { "Y-m-d": [{start,end}, ...] } ]
            foreach ((array) $daySlots as $intervals) {
                foreach ((array) $intervals as $iv) {
                    if (empty($iv['start']) || empty($iv['end'])) {
                        continue;
                    }

                    $s = Carbon::parse($iv['start'])->setTimezone($tz);
                    $e = Carbon::parse($iv['end'])->setTimezone($tz);
                    $byDay[$s->toDateString()][] = [$s, $e];
                }
            }
        }

        return $byDay;
    }

    /**
     * Disponibilidad real a partir de intervalos de SMD ya obtenidos:
     * SMD ∩ jornada local − citas locales. No consulta SMD (el formulario de
     * WhatsApp trae una sola respuesta por especialidad y la reparte acá
     * entre sus doctores).
     *
     * @param  array<string, array<int, array{0:Carbon,1:Carbon}>>  $smdByDay
     */
    public function scheduleFromSmd(int $doctorId, Carbon $startDate, int $days, array $smdByDay, int $duration = 0, ?string $tz = null): array
    {
        $tz ??= config('app.timezone', 'America/La_Paz');
        $from = $startDate->copy()->startOfDay();
        $to = $startDate->copy()->addDays($days - 1)->endOfDay();

        // ── Jornada local + citas locales ─────────────────────────────────────
        $shifts = $this->localShifts($doctorId, $from, $to, $tz);
        $bookings = $this->localBookings($doctorId, $from, $to, $tz);

        $schedule = [];
        $cursor = $startDate->copy();

        for ($i = 0; $i < $days; $i++) {
            $dateStr = $cursor->toDateString();
            $intervals = $smdByDay[$dateStr] ?? [];

            // Intersección con la jornada local (doctor_shifts). Sin turno local
            // ese día ⇒ la clínica no atiende ⇒ día cerrado (sin slots).
            $intervals = $this->intersectWindows($intervals, $shifts[$dateStr] ?? []);

            $free = $this->subtractBookings($intervals, $bookings[$dateStr] ?? []);
            $free = $this->mergeContiguous($free);

            if ($duration > 0) {
                $free = $this->resliceToDuration($free, $duration);
            }

            $schedule[] = [
                'date'  => $dateStr,
                'slots' => array_map(fn ($b) => [
                    'start_time' => $b[0]->format('H:i'),
                    'end_time'   => $b[1]->format('H:i'),
                    'status'     => 'available',
                ], $free),
            ];

            $cursor->addDay();
        }

        return [
            'doctor_id' => $doctorId,
            'from'      => $startDate->toDateString(),
            'days'      => $days,
            'source'    => 'smd',
            'degraded'  => false,
            'reason'    => null,
            'schedule'  => $schedule,
        ];
    }

    /**
     * Jornada laboral local del doctor (doctor_shifts) agrupada por fecha.
     *
     * @return array<string, array<int, array{0:Carbon,1:Carbon}>>
     */
    protected function localShifts(int $doctorId, Carbon $from, Carbon $to, string $tz): array
    {
        $rows = DB::table('doctor_shifts')
            ->where('doctor_id', $doctorId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['date', 'start_time', 'end_time']);

        $byDay = [];
        foreach ($rows as $r) {
            $date = $r->date instanceof Carbon ? $r->date->toDateString() : $r->date;
            $s = Carbon::parse($date.' '.$r->start_time, $tz);
            $e = Carbon::parse($date.' '.$r->end_time, $tz);

            if ($s->lt($e)) {
                $byDay[$date][] = [$s, $e];
            }
        }

        return $byDay;
    }

    /**
     * Recorta los intervalos a las ventanas dadas (intersección).
     * Sin ventanas ⇒ resultado vacío (día cerrado).
     *
     * @param  array<int, array{0:Carbon,1:Carbon}>  $intervals
     * @param  array<int, array{0:Carbon,1:Carbon}>  $windows
     * @return array<int, array{0:Carbon,1:Carbon}>
     */
    protected function intersectWindows(array $intervals, array $windows): array
    {
        if (empty($windows)) {
            return [];
        }

        $out = [];
        foreach ($intervals as $iv) {
            foreach ($windows as $w) {
                $start = $iv[0]->gt($w[0]) ? $iv[0] : $w[0];
                $end = $iv[1]->lt($w[1]) ? $iv[1] : $w[1];

                if ($start->lt($end)) {
                    $out[] = [$start->copy(), $end->copy()];
                }
            }
        }

        return $out;
    }

    /**
     * Citas locales del doctor agrupadas por fecha (excluye canceladas).
     *
     * @return array<string, array<int, array{0:Carbon,1:Carbon}>>
     */
    protected function localBookings(int $doctorId, Carbon $from, Carbon $to, string $tz): array
    {
        $query = DB::table('activities')
            ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
            ->where('doctor_activities.doctor_id', $doctorId)
            ->whereNotNull('activities.schedule_from')
            ->whereNotNull('activities.schedule_to')
            ->whereBetween('activities.schedule_from', [
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ])
            ->select('activities.schedule_from', 'activities.schedule_to');

        $rows = DoctorAvailabilityService::excludeCancelledLeads($query)->get();

        $byDay = [];
        foreach ($rows as $r) {
            $s = Carbon::parse($r->schedule_from)->setTimezone($tz);
            $e = Carbon::parse($r->schedule_to)->setTimezone($tz);
            $byDay[$s->toDateString()][] = [$s, $e];
        }

        return $byDay;
    }

    /**
     * Elimina los intervalos libres que se solapen con alguna cita local.
     *
     * @param  array<int, array{0:Carbon,1:Carbon}>  $free
     * @param  array<int, array{0:Carbon,1:Carbon}>  $bookings
     * @return array<int, array{0:Carbon,1:Carbon}>
     */
    protected function subtractBookings(array $free, array $bookings): array
    {
        if (empty($bookings)) {
            return $free;
        }

        return array_values(array_filter($free, function ($slot) use ($bookings) {
            foreach ($bookings as $b) {
                // Solape: slotStart < bookingEnd && slotEnd > bookingStart
                if ($slot[0]->lt($b[1]) && $slot[1]->gt($b[0])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Une intervalos contiguos/solapados y deduplica.
     *
     * @param  array<int, array{0:Carbon,1:Carbon}>  $intervals
     * @return array<int, array{0:Carbon,1:Carbon}>
     */
    protected function mergeContiguous(array $intervals): array
    {
        if (empty($intervals)) {
            return [];
        }

        usort($intervals, fn ($a, $b) => $a[0]->timestamp <=> $b[0]->timestamp);

        $merged = [array_shift($intervals)];

        foreach ($intervals as $iv) {
            $lastIdx = count($merged) - 1;

            // Contiguo o solapado con el último bloque acumulado.
            if ($iv[0]->lte($merged[$lastIdx][1])) {
                if ($iv[1]->gt($merged[$lastIdx][1])) {
                    $merged[$lastIdx][1] = $iv[1];
                }
            } else {
                $merged[] = $iv;
            }
        }

        return $merged;
    }

    /**
     * Corta bloques libres en slots deslizantes de `$duration` minutos.
     *
     * @param  array<int, array{0:Carbon,1:Carbon}>  $blocks
     * @return array<int, array{0:Carbon,1:Carbon}>
     */
    protected function resliceToDuration(array $blocks, int $duration): array
    {
        $slots = [];

        foreach ($blocks as $block) {
            $cursor = $block[0]->copy();

            while ($cursor->copy()->addMinutes($duration)->lte($block[1])) {
                $end = $cursor->copy()->addMinutes($duration);
                $slots[] = [$cursor->copy(), $end];
                $cursor = $end;
            }
        }

        return $slots;
    }

    /**
     * Resultado degradado a partir de la disponibilidad local.
     */
    protected function localResult(int $doctorId, Carbon $startDate, int $days, int $duration, string $reason, ?string $tz = null): array
    {
        $localDuration = $duration > 0 ? $duration : DoctorAvailabilityService::SLOT_DURATION_MINUTES;

        $local = $this->localAvailability->slotsForRange($doctorId, $startDate->copy(), $days, $localDuration, null, $tz);

        $schedule = array_map(function ($day) {
            return [
                'date'  => $day['date'],
                'slots' => array_values(array_map(
                    fn ($s) => [
                        'start_time' => $s['start_time'],
                        'end_time'   => $s['end_time'],
                        'status'     => 'available',
                    ],
                    array_filter($day['slots'], fn ($s) => ($s['status'] ?? null) === 'available')
                )),
            ];
        }, $local);

        return [
            'doctor_id' => $doctorId,
            'from'      => $startDate->toDateString(),
            'days'      => $days,
            'source'    => 'local',
            'degraded'  => true,
            'reason'    => $reason,
            'schedule'  => $schedule,
        ];
    }
}
