<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Services\DoctorAvailabilityService;

/**
 * @OA\Tag(
 *     name="Doctors",
 *     description="Endpoints para consultar doctores y su disponibilidad"
 * )
 */
class DoctorController extends Controller
{
    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected DoctorAvailabilityService $availabilityService
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

            $query = $this->doctorRepository->getModel()->newQuery()->with(['specialties']);

            if ($request->has('specialty')) {
                $query->whereHas('specialties', function ($q) use ($request) {
                    $q->where('name', 'like', '%'.$request->query('specialty').'%');
                });
            }

            if ($request->has('name')) {
                $query->where('name', 'like', '%'.$request->query('name').'%');
            }

            $doctors = $query->paginate($limit, ['*'], 'page', $page);
            $doctorIds = $doctors->pluck('id')->toArray();
            $startDate = Carbon::now()->startOfDay();
            $endDate = Carbon::now()->addDays(6)->endOfDay();

            $availability = $this->availabilityService->forDoctors($doctorIds, $startDate, $endDate);

            $doctors->getCollection()->transform(function ($doctor) use ($availability) {
                $doctorAvailability = $availability[$doctor->id] ?? collect();
                $doctor->availability = $doctorAvailability;
                $doctor->has_availability = $doctorAvailability->isNotEmpty();

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

    /**
     * @OA\Get(
     *     path="/api/doctors/{id}",
     *     summary="Obtener doctor por ID con schedule semanal",
     *     description="Devuelve los datos de un doctor específico junto con su disponibilidad (slots libres) para los próximos 7 días. Endpoint público - no requiere token. Alternativa eficiente a cargar todos los doctores y filtrar en el cliente.",
     *     tags={"Doctors"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID numérico del doctor",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Doctor encontrado con su schedule de 7 días",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="Dr. García"),
     *             @OA\Property(property="specialties", type="array", @OA\Items(type="object")),
     *             @OA\Property(
     *                 property="schedule",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="date", type="string", format="date", example="2026-05-24"),
     *                     @OA\Property(
     *                         property="slots",
     *                         type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="start_time", type="string", example="09:00"),
     *                             @OA\Property(property="end_time", type="string", example="10:00"),
     *                             @OA\Property(property="status", type="string", example="available")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="ID no numérico",
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Invalid doctor ID format"))
     *     ),
     *     @OA\Response(response=404, description="Doctor no encontrado",
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Doctor not found"))
     *     )
     * )
     */
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

            $startDate = Carbon::now();
            $endDate = $startDate->copy()->addDays(6);

            $response = $doctor->toArray();
            $response['schedule'] = $this->availabilityService->weekScheduleForDoctor((int) $id, $startDate, $endDate);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Internal Server Error', 'error' => $e->getMessage()], 500);
        }
    }
}
