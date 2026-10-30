<?php

namespace Webkul\Doctor\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Doctor\Repositories\ShiftRepository;

class DoctorAvailabilityService
{
    public const SLOT_DURATION_MINUTES = 60;

    public function __construct(
        protected ShiftRepository $shiftRepository
    ) {}

    /**
     * Excluye, de una query sobre `activities`, las citas cuyo(s) Lead(s) están
     * todos cerrados/cancelados - mismo criterio que el chequeo de conflicto local
     * de AppointmentService: cancelación se detecta por leads.status = 0 o por
     * lead_pipeline_stage_id en stage_map.cancelled/no_show (config/smd.php).
     * Una Activity sin Lead vinculado se sigue tratando como cita real (conservador).
     */
    public static function excludeCancelledLeads(\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
    {
        $cancelledStageIds = array_filter([
            (int) config('smd.stage_map.cancelled'),
            (int) config('smd.stage_map.no_show'),
        ]);

        return $query->where(function ($query) use ($cancelledStageIds) {
            $query->whereNotExists(function ($sub) {
                $sub->select('lead_activities.activity_id')
                    ->from('lead_activities')
                    ->whereColumn('lead_activities.activity_id', 'activities.id');
            })->orWhereExists(function ($sub) use ($cancelledStageIds) {
                $sub->select('lead_activities.activity_id')
                    ->from('lead_activities')
                    ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
                    ->whereColumn('lead_activities.activity_id', 'activities.id')
                    ->where(function ($q) {
                        $q->whereNull('leads.status')->orWhere('leads.status', '!=', 0);
                    })
                    ->where(function ($q) use ($cancelledStageIds) {
                        $q->whereNull('leads.lead_pipeline_stage_id')
                          ->orWhereNotIn('leads.lead_pipeline_stage_id', $cancelledStageIds);
                    });
            });
        });
    }

    /**
     * Retorna disponibilidad de los próximos 7 días para múltiples doctores de una sola pasada.
     * Usado por el índice paginado de la API.
     *
     * @return array<int, Collection> keyed by doctor_id
     */
    public function forDoctors(array $doctorIds, Carbon $startDate, Carbon $endDate): array
    {
        $shifts = DB::table('doctor_shifts')
            ->whereIn('doctor_id', $doctorIds)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->groupBy('doctor_id');

        $bookingsQuery = DB::table('activities')
            ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
            ->whereIn('doctor_activities.doctor_id', $doctorIds)
            ->whereBetween('activities.schedule_from', [
                $startDate->format('Y-m-d H:i:s'),
                $endDate->format('Y-m-d H:i:s'),
            ])
            ->select('doctor_activities.doctor_id', 'activities.schedule_from', 'activities.schedule_to');

        $bookings = self::excludeCancelledLeads($bookingsQuery)
            ->get()
            ->groupBy('doctor_id');

        $result = [];
        foreach ($doctorIds as $doctorId) {
            $result[$doctorId] = $this->buildSlots(
                $shifts->get($doctorId, collect()),
                $bookings->get($doctorId, collect())
            );
        }

        return $result;
    }

    /**
     * Retorna el schedule detallado día a día para un único doctor.
     * Usado por el endpoint show() de la API.
     *
     * @return array<int, array{date: string, slots: array}>
     */
    public function weekScheduleForDoctor(int $doctorId, Carbon $startDate, Carbon $endDate): array
    {
        $shifts = $this->shiftRepository->findWhere([
            ['doctor_id', '=', $doctorId],
            ['date', '>=', $startDate->toDateString()],
            ['date', '<=', $endDate->toDateString()],
        ]);

        $bookingsQuery = DB::table('activities')
            ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
            ->where('doctor_activities.doctor_id', $doctorId)
            ->whereBetween('activities.schedule_from', [
                $startDate->format('Y-m-d H:i:s'),
                $endDate->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->select('activities.schedule_from', 'activities.schedule_to');

        $bookings = self::excludeCancelledLeads($bookingsQuery)->get();

        $schedule = [];
        $cursor = $startDate->copy();

        for ($i = 0; $i < 7; $i++) {
            $dateStr = $cursor->toDateString();

            $dayShifts = $shifts->filter(function ($shift) use ($dateStr) {
                $shiftDate = $shift->date instanceof Carbon
                    ? $shift->date->toDateString()
                    : $shift->date;

                return $shiftDate === $dateStr;
            });

            $daySlots = [];

            foreach ($dayShifts as $shift) {
                $shiftStart = Carbon::parse($dateStr.' '.$shift->start_time);
                $shiftEnd = Carbon::parse($dateStr.' '.$shift->end_time);
                $slotCursor = $shiftStart->copy();

                while ($slotCursor->lt($shiftEnd)) {
                    $slotEnd = $slotCursor->copy()->addMinutes(self::SLOT_DURATION_MINUTES);

                    if ($slotEnd->gt($shiftEnd)) {
                        break;
                    }

                    $status = 'available';
                    foreach ($bookings as $booking) {
                        $bStart = Carbon::parse($booking->schedule_from);
                        $bEnd = Carbon::parse($booking->schedule_to);

                        if ($slotCursor->lt($bEnd) && $slotEnd->gt($bStart)) {
                            $status = 'booked';
                            break;
                        }
                    }

                    $daySlots[] = [
                        'start_time' => $slotCursor->format('H:i'),
                        'end_time'   => $slotEnd->format('H:i'),
                        'status'     => $status,
                    ];

                    $slotCursor->addMinutes(self::SLOT_DURATION_MINUTES);
                }
            }

            usort($daySlots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

            $schedule[] = [
                'date'  => $dateStr,
                'slots' => $daySlots,
            ];

            $cursor->addDay();
        }

        return $schedule;
    }

    /**
     * Retorna slots por día para un doctor en un rango de fechas.
     * Una sola pasada a la BD para shifts y bookings del rango completo.
     *
     * @return array<int, array{date: string, slots: array}>
     */
    public function slotsForRange(int $doctorId, Carbon $startDate, int $days, int $durationMinutes = self::SLOT_DURATION_MINUTES): array
    {
        $endDate = $startDate->copy()->addDays($days - 1);

        $shifts = DB::table('doctor_shifts')
            ->where('doctor_id', $doctorId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->groupBy('date');

        $tz = config('app.timezone', 'UTC');

        $bookingsQuery = DB::table('activities')
            ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
            ->where('doctor_activities.doctor_id', $doctorId)
            ->whereNotNull('activities.schedule_from')
            ->whereNotNull('activities.schedule_to')
            ->whereBetween('activities.schedule_from', [
                $startDate->format('Y-m-d 00:00:00'),
                $endDate->format('Y-m-d 23:59:59'),
            ])
            ->select('activities.schedule_from', 'activities.schedule_to');

        $bookings = self::excludeCancelledLeads($bookingsQuery)
            ->get()
            ->groupBy(fn ($b) => Carbon::parse($b->schedule_from)->setTimezone($tz)->toDateString());

        $result = [];
        $cursor = $startDate->copy();

        for ($i = 0; $i < $days; $i++) {
            $dateStr     = $cursor->toDateString();
            $dayShifts   = $shifts->get($dateStr, collect());
            $dayBookings = $bookings->get($dateStr, collect());

            $slots = $this->buildSlotsForDay($dateStr, $dayShifts, $dayBookings, $durationMinutes);

            $result[] = [
                'date'  => $dateStr,
                'slots' => $slots,
            ];

            $cursor->addDay();
        }

        return $result;
    }

    protected function buildSlotsForDay(string $dateStr, \Illuminate\Support\Collection $dayShifts, \Illuminate\Support\Collection $dayBookings, int $durationMinutes): array
    {
        $slots = [];

        foreach ($dayShifts as $shift) {
            $cursor   = Carbon::parse($dateStr . ' ' . $shift->start_time);
            $shiftEnd = Carbon::parse($dateStr . ' ' . $shift->end_time);

            // Turno corrupto o que cruza medianoche: ignorar con log
            if (! $cursor->lt($shiftEnd)) {
                \Illuminate\Support\Facades\Log::warning('[DoctorAvailability] turno inválido ignorado', [
                    'shift_id'   => $shift->id ?? null,
                    'date'       => $dateStr,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                ]);
                continue;
            }

            while (true) {
                $slotEnd = $cursor->copy()->addMinutes($durationMinutes);

                if ($slotEnd->gt($shiftEnd)) {
                    break;
                }

                $status = 'available';
                foreach ($dayBookings as $booking) {
                    // Cita con timestamps nulos: dato corrupto, ignorar
                    if (! $booking->schedule_from || ! $booking->schedule_to) {
                        continue;
                    }

                    $bStart = Carbon::parse($booking->schedule_from);
                    $bEnd   = Carbon::parse($booking->schedule_to);

                    if ($cursor->lt($bEnd) && $slotEnd->gt($bStart)) {
                        $status = 'booked';
                        break;
                    }
                }

                $slots[] = [
                    'start_time' => $cursor->format('H:i'),
                    'end_time'   => $slotEnd->format('H:i'),
                    'status'     => $status,
                ];

                $cursor->addMinutes($durationMinutes);
            }
        }

        usort($slots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

        // Deduplicar en caso de turnos solapados del mismo doctor
        $unique = [];
        foreach ($slots as $slot) {
            $key = $slot['start_time'] . '-' . $slot['end_time'];
            $unique[$key] = $slot;
        }

        return array_values($unique);
    }

    /**
     * Construye slots libres a partir de bloques de turno y citas existentes.
     */
    protected function buildSlots(Collection $shiftBlocks, Collection $doctorBookings): Collection
    {
        $slots = collect();

        foreach ($shiftBlocks as $block) {
            $current = Carbon::parse($block->date.' '.$block->start_time);
            $end = Carbon::parse($block->date.' '.$block->end_time);

            while ($current->copy()->addMinutes(self::SLOT_DURATION_MINUTES)->lte($end)) {
                $slotEnd = $current->copy()->addMinutes(self::SLOT_DURATION_MINUTES);

                $isBooked = $doctorBookings->contains(function ($booking) use ($current, $slotEnd) {
                    $bStart = Carbon::parse($booking->schedule_from);
                    $bEnd = Carbon::parse($booking->schedule_to);

                    return $current->lt($bEnd) && $slotEnd->gt($bStart);
                });

                if (! $isBooked) {
                    $slots->push([
                        'id'         => $block->id,
                        'doctor_id'  => $block->doctor_id,
                        'date'       => $block->date,
                        'start_time' => $current->format('H:i:s'),
                        'end_time'   => $slotEnd->format('H:i:s'),
                    ]);
                }

                $current->addMinutes(self::SLOT_DURATION_MINUTES);
            }
        }

        return $slots;
    }
}
