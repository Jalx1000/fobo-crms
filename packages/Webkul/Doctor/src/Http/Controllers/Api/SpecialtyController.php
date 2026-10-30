<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Doctor\Repositories\SpecialtyRepository;
use Webkul\Doctor\Services\DoctorAvailabilityService;

class SpecialtyController extends Controller
{
    public function __construct(
        protected SpecialtyRepository $specialtyRepository,
        protected AttributeRepository $attributeRepository,
        protected DoctorAvailabilityService $availabilityService
    ) {}

    public function index(): JsonResponse
    {
        try {
            $specialties = $this->specialtyRepository->all();

            return response()->json([
                'data' => $specialties,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Internal Server Error',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/specialties",
     *     summary="Create a new specialty",
     *      tags={"Specialties"},
     *
     *     @OA\RequestBody(
     *
     *         @OA\MediaType(
     *             mediaType="application/json",
     *
     *             @OA\Schema(
     *
     *                 @OA\Property(
     *                     property="name",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string"
     *                 ),
     *                 example={"name": "Cardiología", "description": "Especialidad médica que se ocupa de las enfermedades del corazón y del aparato circulatorio."}
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Specialty created successfully"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'min:2', 'max:150', 'unique:specialties,name'],
            'description' => ['required', 'string'],
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $specialty = $this->specialtyRepository->create($validated);

        return response()->json($specialty, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/specialties/{identifier}/doctors",
     *     summary="Listar doctores activos por especialidad",
     *     description="Devuelve los doctores ACTIVOS (is_active=true) asociados a una especialidad, con campos resumidos y disponibilidad de cita a 7/14/30 días. El parámetro {identifier} acepta ID numérico, slug o nombre (coincidencia parcial). Endpoint público.",
     *     tags={"Specialties"},
     *
     *     @OA\Parameter(
     *         name="identifier",
     *         in="path",
     *         required=true,
     *         description="ID, slug o nombre de la especialidad",
     *
     *         @OA\Schema(type="string", example="ortodoncia")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Especialidad encontrada con sus doctores activos",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="specialty", type="object"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Dr. García"),
     *                 @OA\Property(property="unique_id", type="string", example="123|Dr. García"),
     *                 @OA\Property(property="age_range_min", type="string", example="0", nullable=true),
     *                 @OA\Property(property="age_range_max", type="string", example="99", nullable=true),
     *                 @OA\Property(property="type_service_doctor", type="array", nullable=true, @OA\Items(type="string"), example={"PROTESIS FIJA"}),
     *                 @OA\Property(property="attendsPatientType", type="string", nullable=true, example="Pacientes nuevos"),
     *                 @OA\Property(property="available_7d", type="boolean", example=true),
     *                 @OA\Property(property="available_14d", type="boolean", example=true),
     *                 @OA\Property(property="available_30d", type="boolean", example=true)
     *             )),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Especialidad no encontrada")
     * )
     */
    public function doctors(Request $request, string $identifier): JsonResponse
    {
        try {
            $model = $this->specialtyRepository->resolveByIdentifier($identifier);

            if (! $model) {
                return response()->json(['message' => 'Specialty not found'], 404);
            }

            $doctors = $model->doctors()
                ->where('doctors.is_active', true)
                ->with('attribute_values')
                ->get();

            $doctorIds = $doctors->pluck('id')->all();

            $startDate = Carbon::now()->startOfDay();
            $endDate = $startDate->copy()->addDays(29)->endOfDay();
            $availability = $doctorIds
                ? $this->availabilityService->forDoctors($doctorIds, $startDate, $endDate)
                : [];

            $serviceOptions = $this->attributeOptionMap('type_service_doctor');
            $patientTypeOptions = $this->attributeOptionMap('attendsPatientType');

            $data = $doctors->map(function ($doctor) use ($availability, $serviceOptions, $patientTypeOptions, $startDate) {
                $slots = $availability[$doctor->id] ?? collect();

                return [
                    'id'                  => $doctor->id,
                    'name'                => $doctor->name,
                    'unique_id'           => $doctor->unique_id,
                    'age_range_min'       => $this->nullifyEmpty($doctor->age_range_min),
                    'age_range_max'       => $this->nullifyEmpty($doctor->age_range_max),
                    'type_service_doctor' => $this->multiselectLabels($doctor->type_service_doctor, $serviceOptions),
                    'attendsPatientType'  => $this->selectLabel($doctor->attendsPatientType, $patientTypeOptions),
                    'available_7d'        => $this->hasSlotWithin($slots, $startDate, 7),
                    'available_14d'       => $this->hasSlotWithin($slots, $startDate, 14),
                    'available_30d'       => $this->hasSlotWithin($slots, $startDate, 30),
                ];
            })->values();

            return response()->json([
                'specialty' => [
                    'id'   => $model->id,
                    'name' => $model->name,
                    'slug' => $model->slug,
                ],
                'data' => $data,
                'meta' => ['total' => $data->count()],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Internal Server Error',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mapa [option_id => name] de las opciones de un atributo select/multiselect.
     */
    protected function attributeOptionMap(string $code): array
    {
        $attribute = $this->attributeRepository->getAttributeByCode($code);

        if (! $attribute) {
            return [];
        }

        return DB::table('attribute_options')
            ->where('attribute_id', $attribute->id)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Convierte el valor crudo de un multiselect ("132,137") en array de etiquetas,
     * o null si no tiene valor.
     */
    protected function multiselectLabels($raw, array $optionMap): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $ids = array_filter(
            array_map('trim', explode(',', (string) $raw)),
            fn ($id) => $id !== ''
        );

        $labels = [];
        foreach ($ids as $id) {
            if (isset($optionMap[(int) $id])) {
                $labels[] = $optionMap[(int) $id];
            }
        }

        return empty($labels) ? null : $labels;
    }

    /**
     * Convierte el valor crudo de un select (id de opción) en su etiqueta, o null.
     */
    protected function selectLabel($raw, array $optionMap): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return $optionMap[(int) $raw] ?? null;
    }

    /**
     * Normaliza un valor de texto a string o null cuando está vacío.
     */
    protected function nullifyEmpty($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * True si hay al menos un slot libre dentro de los próximos $days días
     * (hoy incluido) según la colección de disponibilidad del doctor.
     */
    protected function hasSlotWithin($slots, Carbon $startDate, int $days): bool
    {
        $limit = $startDate->copy()->addDays($days - 1)->toDateString();

        return collect($slots)->contains(fn ($slot) => $slot['date'] <= $limit);
    }
}
