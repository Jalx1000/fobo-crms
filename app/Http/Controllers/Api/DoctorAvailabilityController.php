<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Admin\Services\AvailabilityResolverService;
use Webkul\Doctor\Repositories\DoctorRepository;

class DoctorAvailabilityController extends Controller
{
    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected AvailabilityResolverService $resolver,
    ) {}

    /**
     * Disponibilidad REAL de un doctor: slots libres de ShareMeData menos las
     * citas locales del CRM. Si SMD no se puede consultar, degrada a la
     * disponibilidad local e informa el motivo (`source`/`degraded`/`reason`).
     *
     * GET /api/doctors/{id}/available-slots?date=YYYY-MM-DD&days=7&duration_minutes=30&specialty=&subsidiary=
     *
     * @OA\Get(
     *     path="/api/doctors/{id}/available-slots",
     *     summary="Disponibilidad real del doctor (ShareMeData ∩ jornada local − citas)",
     *     description="Devuelve los slots realmente libres de un doctor: cruza la disponibilidad de ShareMeData (SMD) con la jornada local del CRM (doctor_shifts) y le resta las citas ya agendadas. Es el endpoint recomendado para clientes externos (agente IA), en lugar de `GET /api/doctors/{id}` (que devuelve solo la agenda local, sin SMD). Si SMD no se puede consultar, degrada a la disponibilidad local e informa el motivo en `source`/`degraded`/`reason`.",
     *     tags={"Doctors"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id", in="path", required=true,
     *         description="ID numérico del doctor en el CRM",
     *         @OA\Schema(type="integer", minimum=1, example=5)
     *     ),
     *     @OA\Parameter(
     *         name="date", in="query", required=true,
     *         description="Fecha inicial (hoy o futura, hasta 6 meses). Formato Y-m-d.",
     *         @OA\Schema(type="string", format="date", example="2026-07-23")
     *     ),
     *     @OA\Parameter(
     *         name="days", in="query", required=false,
     *         description="Cantidad de días a devolver desde `date` (1–30). Default 7.",
     *         @OA\Schema(type="integer", minimum=1, maximum=30, default=7, example=1)
     *     ),
     *     @OA\Parameter(
     *         name="duration_minutes", in="query", required=false,
     *         description="Si se envía, recorta cada ventana libre en slots de esta duración (15–480). Si se omite, devuelve las ventanas libres completas.",
     *         @OA\Schema(type="integer", minimum=15, maximum=480, example=30)
     *     ),
     *     @OA\Parameter(
     *         name="specialty", in="query", required=false,
     *         description="Especialidad a consultar en SMD. Si se omite, usa las especialidades del doctor en el CRM.",
     *         @OA\Schema(type="string", maxLength=120, example="Endodoncia")
     *     ),
     *     @OA\Parameter(
     *         name="subsidiary", in="query", required=false,
     *         description="Sucursal a consultar en SMD. Default: `Santa Cruz`.",
     *         @OA\Schema(type="string", maxLength=120, example="Santa Cruz")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Disponibilidad resuelta. `source=smd` cuando se cruzó con ShareMeData; `source=local` (con `degraded=true` y `reason`) cuando se degradó a la agenda local.",
     *         @OA\JsonContent(
     *             @OA\Property(property="doctor_id", type="integer", example=5),
     *             @OA\Property(property="from", type="string", format="date", example="2026-07-23"),
     *             @OA\Property(property="days", type="integer", example=1),
     *             @OA\Property(property="source", type="string", enum={"smd", "local"}, example="smd"),
     *             @OA\Property(property="degraded", type="boolean", example=false),
     *             @OA\Property(
     *                 property="reason", type="string", nullable=true,
     *                 enum={"doctor_unlinked", "smd_unavailable", "smd_disabled"},
     *                 description="Motivo de la degradación a local (null cuando `source=smd`).",
     *                 example=null
     *             ),
     *             @OA\Property(
     *                 property="schedule", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="date", type="string", format="date", example="2026-07-23"),
     *                     @OA\Property(
     *                         property="slots", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="start_time", type="string", example="09:00"),
     *                             @OA\Property(property="end_time", type="string", example="09:30"),
     *                             @OA\Property(property="status", type="string", example="available")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="No autenticado (falta el Bearer token de Sanctum)",
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Unauthenticated."))
     *     ),
     *     @OA\Response(response=404, description="Doctor no encontrado o inactivo",
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Doctor not found."))
     *     ),
     *     @OA\Response(response=422, description="Parámetros inválidos (p. ej. `date` faltante o fuera de rango)",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The date field is required."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function availableSlots(Request $request, int $id): JsonResponse
    {
        if ($id <= 0) {
            return response()->json(['message' => 'Doctor not found.'], 404);
        }

        $data = $request->validate([
            'date'             => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today', 'before:' . now()->addMonths(6)->toDateString()],
            'days'             => ['sometimes', 'integer', 'min:1', 'max:30'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
            'specialty'        => ['sometimes', 'string', 'max:120'],
            'subsidiary'       => ['sometimes', 'string', 'max:120'],
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

        $result = $this->resolver->resolve($id, $startDate, $days, [
            'duration_minutes' => $data['duration_minutes'] ?? 0,
            'specialty'        => $data['specialty']        ?? null,
            'subsidiary'       => $data['subsidiary']       ?? null,
        ]);

        return response()->json($result);
    }
}
