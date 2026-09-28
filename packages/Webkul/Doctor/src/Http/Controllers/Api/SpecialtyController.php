<?php

namespace Webkul\Doctor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Doctor\Repositories\SpecialtyRepository;

class SpecialtyController extends Controller
{
    public function __construct(
        protected SpecialtyRepository $specialtyRepository
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
}
