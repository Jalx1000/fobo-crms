<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\ShiftRepository;

class AvailabilityController extends Controller
{
    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected ShiftRepository $shiftRepository
    ) {}

    public function getForMonth(Request $request, $doctorId, $year, $month): JsonResponse
    {
        // 1. Validation
        if (! is_numeric($year) || ! is_numeric($month) || $month < 1 || $month > 12) {
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

            $bookings = DB::table('activities')
                ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
                ->where('doctor_activities.doctor_id', $doctorId)
                ->whereBetween('activities.schedule_from', [$startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s')])
                ->select('activities.schedule_from', 'activities.schedule_to', 'activities.type')
                ->get();

            // 6. Process Availability
            $result = [];
            $cursor = $startDate->copy();

            while ($cursor->lte($endDate)) {
                $dateStr = $cursor->toDateString();
                $dayShifts = $shifts->where('date', $dateStr); // Collection check

                // If using repository findWhere returns Collection of models.
                // Shift model has 'date' (Carbon or string? likely cast to Date).
                // Let's assume date is Carbon object in model.

                $dayShifts = $shifts->filter(function ($shift) use ($dateStr) {
                    return $shift->date instanceof Carbon
                        ? $shift->date->toDateString() === $dateStr
                        : $shift->date === $dateStr;
                });

                $daySlots = [];

                foreach ($dayShifts as $shift) {
                    // Break shift into slots (e.g. 30 mins)
                    // Requirement doesn't specify slot duration, but example implies 30 mins.
                    // Or we can return the shift ranges minus bookings.
                    // The example: 09:00-09:30, 09:30-10:00.
                    // I'll assume 30 minutes for now.

                    $slotDuration = 30; // minutes

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
            return response()->json(['message' => 'Internal Server Error: '.$e->getMessage()], 500);
        }
    }
}
