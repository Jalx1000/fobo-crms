<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\ShiftRepository;
use Webkul\Doctor\Services\DoctorAvailabilityService;

class AvailabilityController extends Controller
{
    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected ShiftRepository $shiftRepository,
        protected DoctorAvailabilityService $availabilityService,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/doctors/{id}/slots",
     *     summary="Slots de disponibilidad de un doctor para un rango de días",
     *     description="Retorna los slots de todos los turnos de un doctor desde la fecha indicada hasta N días después, marcando cada uno como 'available' o 'booked'. Por defecto devuelve 7 días. Fuente de verdad: tabla doctor_shifts (turnos) y doctor_activities (citas). No requiere autenticación.",
     *     tags={"Doctors"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID del doctor",
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         required=true,
     *         description="Fecha de inicio del rango en formato YYYY-MM-DD",
     *         @OA\Schema(type="string", format="date", example="2026-05-26")
     *     ),
     *     @OA\Parameter(
     *         name="days",
     *         in="query",
     *         required=false,
     *         description="Cantidad de días a consultar desde la fecha indicada. Mínimo 1, máximo 30. Por defecto 7.",
     *         @OA\Schema(type="integer", minimum=1, maximum=30, default=7, example=14)
     *     ),
     *     @OA\Parameter(
     *         name="duration_minutes",
     *         in="query",
     *         required=false,
     *         description="Duración de cada slot en minutos. Mínimo 15, máximo 480. Por defecto 60.",
     *         @OA\Schema(type="integer", minimum=15, maximum=480, default=60, example=30)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Disponibilidad agrupada por día",
     *         @OA\JsonContent(
     *             @OA\Property(property="doctor_id", type="integer", example=3),
     *             @OA\Property(property="from", type="string", format="date", example="2026-05-26"),
     *             @OA\Property(property="days", type="integer", example=7),
     *             @OA\Property(property="duration_minutes", type="integer", example=60),
     *             @OA\Property(
     *                 property="schedule",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="date", type="string", format="date", example="2026-05-26"),
     *                     @OA\Property(
     *                         property="slots",
     *                         type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="start_time", type="string", example="09:00"),
     *                             @OA\Property(property="end_time", type="string", example="10:00"),
     *                             @OA\Property(property="status", type="string", enum={"available","booked"}, example="available")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Doctor no encontrado",
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Doctor not found."))
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Parámetros inválidos",
     *         @OA\JsonContent(@OA\Property(property="message", type="string"), @OA\Property(property="errors", type="object"))
     *     )
     * )
     */
    public function slots(Request $request, int $id): JsonResponse
    {
        if ($id <= 0) {
            return response()->json(['message' => 'Doctor not found.'], 404);
        }

        $data = $request->validate([
            'date'             => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today', 'before:' . now()->addMonths(6)->toDateString()],
            'days'             => ['sometimes', 'integer', 'min:1', 'max:30'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
        ]);

        $doctor = $this->doctorRepository->findWhere([
            ['id', '=', $id],
            ['is_active', '=', true],
        ])->first();

        if (! $doctor) {
            return response()->json(['message' => 'Doctor not found.'], 404);
        }

        $startDate = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();
        $days      = (int) ($data['days'] ?? 7);
        $duration  = (int) ($data['duration_minutes'] ?? DoctorAvailabilityService::SLOT_DURATION_MINUTES);

        $schedule = $this->availabilityService->slotsForRange($id, $startDate, $days, $duration);

        return response()->json([
            'doctor_id'        => $id,
            'from'             => $startDate->toDateString(),
            'days'             => $days,
            'duration_minutes' => $duration,
            'schedule'         => $schedule,
        ]);
    }

    public function getForMonth(Request $request, $doctorId, $year, $month): JsonResponse
    {
        // 1. Validation
        $year        = (int) $year;
        $month       = (int) $month;
        $currentYear = (int) now()->format('Y');

        if ($year < $currentYear - 1 || $year > $currentYear + 2 || $month < 1 || $month > 12) {
            return response()->json(['message' => 'Invalid year or month value'], 400);
        }

        // 2. Check Doctor
        $doctor = $this->doctorRepository->find($doctorId);
        if (! $doctor) {
            return response()->json(['message' => 'Doctor not found'], 404);
        }

        try {
            $includeBooked = filter_var($request->query('includeBooked', false), FILTER_VALIDATE_BOOLEAN);

            // 3. Date Range
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // 4. Fetch Shifts
            $shifts = $this->shiftRepository->findWhere([
                ['doctor_id', '=', $doctorId],
                ['date', '>=', $startDate->toDateString()],
                ['date', '<=', $endDate->toDateString()],
            ]);

            // 5. Fetch Appointments (Activities)
            // Assuming activities table stores appointments and 'doctor_activities' links them
            // or 'participants' logic. Based on ActivityController, it seems we check 'doctor_activities'.
            // And also 'doctor_shifts' are the availability.

            // We need to fetch booked slots.
            // Let's assume 'activities' with type 'meeting' or 'call' are bookings.
            // And 'time_off' also blocks availability.

            // Reusing logic from ActivityController::get logic effectively.

            $bookingsQuery = DB::table('activities')
                ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
                ->where('doctor_activities.doctor_id', $doctorId)
                ->whereBetween('activities.schedule_from', [$startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s')])
                ->select('activities.schedule_from', 'activities.schedule_to', 'activities.type');

            $bookings = DoctorAvailabilityService::excludeCancelledLeads($bookingsQuery)->get();

            // 6. Process Availability
            $result = [];
            $cursor = $startDate->copy();

            while ($cursor->lte($endDate)) {
                $dateStr = $cursor->toDateString();

                $dayShifts = $shifts->filter(function ($shift) use ($dateStr) {
                    return $shift->date instanceof Carbon
                        ? $shift->date->toDateString() === $dateStr
                        : $shift->date === $dateStr;
                });

                $daySlots = [];

                foreach ($dayShifts as $shift) {
                    $slotDuration = 60; // minutes

                    $shiftStart = Carbon::parse($dateStr.' '.$shift->start_time);
                    $shiftEnd = Carbon::parse($dateStr.' '.$shift->end_time);

                    $slotCursor = $shiftStart->copy();

                    while ($slotCursor->lt($shiftEnd)) {
                        $slotEnd = $slotCursor->copy()->addMinutes($slotDuration);
                        if ($slotEnd->gt($shiftEnd)) {
                            break;
                        }

                        $isBooked = false;

                        // Check against bookings
                        foreach ($bookings as $booking) {
                            $bStart = Carbon::parse($booking->schedule_from);
                            $bEnd = Carbon::parse($booking->schedule_to);

                            // Check overlap
                            // Slot overlaps booking if: SlotStart < BookingEnd AND SlotEnd > BookingStart
                            if ($slotCursor->lt($bEnd) && $slotEnd->gt($bStart)) {
                                $isBooked = true;
                                break;
                            }
                        }

                        if (! $isBooked || $includeBooked) {
                            $daySlots[] = [
                                'startTime' => $slotCursor->format('H:i'),
                                'endTime'   => $slotEnd->format('H:i'),
                                'isBooked'  => $isBooked,
                            ];
                        }

                        $slotCursor->addMinutes($slotDuration);
                    }
                }

                if (count($daySlots) > 0 || $includeBooked) {
                    // Sort slots by time
                    usort($daySlots, fn ($a, $b) => strcmp($a['startTime'], $b['startTime']));

                    $result[] = [
                        'date'  => $dateStr,
                        'slots' => $daySlots,
                    ];
                }

                $cursor->addDay();
            }

            return response()->json($result);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[AvailabilityController] getForMonth error', [
                'doctor_id' => $doctorId,
                'year'      => $year,
                'month'     => $month,
                'error'     => $e->getMessage(),
            ]);

            return response()->json(['message' => 'An error occurred. Please try again later.'], 500);
        }
    }
}
