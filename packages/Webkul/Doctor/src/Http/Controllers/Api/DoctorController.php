<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\ShiftRepository;

class DoctorController extends Controller
{
    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected ShiftRepository $shiftRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $page = (int) $request->query('page', 1);
            $limit = (int) $request->query('limit', 10);

            if ($limit > 100) {
                $limit = 100;
            }
            if ($limit < 1) {
                $limit = 10;
            }

            // Apply filters
            $query = $this->doctorRepository->getModel()->newQuery()->with('specialties');

            if ($request->has('specialty')) {
                $query->whereHas('specialties', function ($q) use ($request) {
                    $q->where('name', 'like', '%'.$request->query('specialty').'%');
                });
            }

            if ($request->has('name')) {
                $query->where('name', 'like', '%'.$request->query('name').'%');
            }

            // Pagination
            $doctors = $query->paginate($limit, ['*'], 'page', $page);

            // Get availability for the paginated doctors
            $doctorIds = $doctors->pluck('id')->toArray();
            $startDate = Carbon::now()->startOfDay();
            $endDate = Carbon::now()->addDays(6)->endOfDay();

            $shifts = DB::table('doctor_shifts')
                ->whereIn('doctor_id', $doctorIds)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->get()
                ->groupBy('doctor_id');

            // Attach availability to each doctor
            $doctors->getCollection()->transform(function ($doctor) use ($shifts) {
                $doctor->availability = $shifts->get($doctor->id, collect());

                return $doctor;
            });

            return response()->json([
                'data' => $doctors->items(),
                'meta' => [
                    'current_page' => $doctors->currentPage(),
                    'per_page'     => $doctors->perPage(),
                    'total'        => $doctors->total(),
                    'last_page'    => $doctors->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error processing request', 'error' => $e->getMessage()], 400);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            if (! is_numeric($id)) {
                return response()->json(['message' => 'Invalid doctor ID format'], 400);
            }

            $doctor = $this->doctorRepository->with(['specialties'])->find($id);

            if (! $doctor) {
                return response()->json(['message' => 'Doctor not found'], 404);
            }

            // --- Availability Calculation Logic ---
            $startDate = Carbon::now();
            $endDate = $startDate->copy()->addDays(6);

            // Fetch Shifts for the next 7 days
            $shifts = $this->shiftRepository->findWhere([
                ['doctor_id', '=', $id],
                ['date', '>=', $startDate->toDateString()],
                ['date', '<=', $endDate->toDateString()],
            ]);

            // Fetch Bookings/Activities
            $bookings = DB::table('activities')
                ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
                ->where('doctor_activities.doctor_id', $id)
                ->whereBetween('activities.schedule_from', [$startDate->format('Y-m-d H:i:s'), $endDate->endOfDay()->format('Y-m-d H:i:s')])
                ->select('activities.schedule_from', 'activities.schedule_to', 'activities.type')
                ->get();

            $schedule = [];
            $cursor = $startDate->copy();

            // Iterate through the next 7 days
            for ($i = 0; $i < 7; $i++) {
                $dateStr = $cursor->toDateString();

                // Filter shifts for the current day
                $dayShifts = $shifts->filter(function ($shift) use ($dateStr) {
                    return $shift->date instanceof Carbon
                        ? $shift->date->toDateString() === $dateStr
                        : $shift->date === $dateStr;
                });

                $daySlots = [];

                foreach ($dayShifts as $shift) {
                    $slotDuration = 30; // minutes

                    $shiftStart = Carbon::parse($dateStr.' '.$shift->start_time);
                    $shiftEnd = Carbon::parse($dateStr.' '.$shift->end_time);

                    $slotCursor = $shiftStart->copy();

                    while ($slotCursor->lt($shiftEnd)) {
                        $slotEnd = $slotCursor->copy()->addMinutes($slotDuration);
                        if ($slotEnd->gt($shiftEnd)) {
                            break;
                        }

                        $status = 'available';

                        // Check for conflicts with bookings
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

                        $slotCursor->addMinutes($slotDuration);
                    }
                }

                // Sort slots
                usort($daySlots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

                $schedule[] = [
                    'date'  => $dateStr,
                    'slots' => $daySlots,
                ];

                $cursor->addDay();
            }

            // Format response
            $response = $doctor->toArray();
            $response['schedule'] = $schedule;

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Internal Server Error', 'error' => $e->getMessage()], 500);
        }
    }
}
