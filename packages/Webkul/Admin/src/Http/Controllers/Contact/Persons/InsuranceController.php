<?php

namespace Webkul\Admin\Http\Controllers\Contact\Persons;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Services\InsuranceService;
use Webkul\Contact\Repositories\PersonRepository;

class InsuranceController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected InsuranceService $insuranceService,
        protected PersonRepository $personRepository
    ) {}

    /**
     * Verify insurance for a specific person.
     */
    public function verify(int $id): JsonResponse
    {
        $person = $this->personRepository->find($id);

        if (! $person) {
            return response()->json([
                'message' => 'No encontramos al paciente.',
            ], 404);
        }

        // Obtener atributos necesarios
        $ciAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)->findOneByField('code', 'ci_paciente');
        $seguroAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)->findOneByField('code', 'seguro_paciente');

        $ci = $person->getCustomAttributeValue($ciAttr);
        $seguro = $person->getCustomAttributeValue($seguroAttr);

        if (empty($ci) || empty($seguro)) {
            // Si faltan campos, devolvemos las opciones del seguro para el modal
            $options = [];
            if ($seguroAttr) {
                $options = DB::table('attribute_options')
                    ->where('attribute_id', $seguroAttr->id)
                    ->get(['id', 'name']);
            }

            return response()->json([
                'message' => 'Faltan datos importantes para la validación.',
                'status'  => 'CAMPOS_VACIOS',
                'options' => $options,
            ], 422);
        }

        $result = $this->insuranceService->verify($id);

        // Una nota por aseguradora con datos reales, no una sola por el agregado.
        foreach ($result['results'] ?? [$result] as $single) {
            if (in_array($single['status'], ['VIGENTE', 'EN_MORA'])) {
                $this->insuranceService->createNoteActivity($id, $single);
            }
        }

        return response()->json($result);
    }

    /**
     * Actualiza los atributos de seguro del paciente antes de verificar.
     */
    public function updateAndVerify(int $id): JsonResponse
    {
        /**
         * `seguro_paciente` es un atributo multiselect: acepta un id suelto (calendario,
         * checker SMD) o una lista (formulario de edición con chips).
         */
        request()->merge([
            'seguro_paciente' => array_values(array_filter(
                Arr::wrap(request('seguro_paciente')),
                fn ($value) => $value !== null && $value !== ''
            )),
        ]);

        $data = request()->validate([
            'ci_paciente'       => 'required|string',
            'seguro_paciente'   => 'required|array|min:1',
            'seguro_paciente.*' => 'exists:attribute_options,id',
        ]);

        $person = $this->personRepository->find($id);

        if (! $person) {
            return response()->json(['message' => 'Paciente no encontrado.'], 404);
        }

        // Actualizar los atributos usando el repositorio de personas para disparar eventos
        $this->personRepository->update([
            'entity_type'     => 'persons',
            'ci_paciente'     => $data['ci_paciente'],
            'seguro_paciente' => $data['seguro_paciente'],
        ], $id);

        // Disparar el evento manualmente por si el repositorio no lo hace (aunque debería)
        Event::dispatch('contacts.person.update.after', $person);

        // Limpiar cache previa si existe para forzar nueva verificación
        $this->clearCache($id);

        return $this->verify($id);
    }

    /**
     * Verifica seguro sin person_id (para usar en formulario de creación).
     * Recibe ci_paciente y seguro_option_id directamente en el body.
     */
    public function verifyQuick(Request $request): JsonResponse
    {
        /**
         * `seguro_paciente` es multiselect: llega un id o la lista de ids elegidos.
         * Con uno solo se responde como siempre; con varios, un resultado por
         * aseguradora (`results`) y el veredicto general, igual que en la ficha.
         */
        $data = $request->validate([
            'ci_paciente'       => 'required|string|min:3',
            'seguro_paciente'   => 'required',
            'seguro_paciente.*' => 'exists:attribute_options,id',
        ]);

        if (! is_array($data['seguro_paciente'])) {
            $request->validate(['seguro_paciente' => 'exists:attribute_options,id']);
        }

        $options = DB::table('attribute_options')
            ->whereIn('id', (array) $data['seguro_paciente'])
            ->get(['id', 'name']);

        $results = $options->map(fn ($option) => $this->insuranceService->verifyWithParams($data['ci_paciente'], $option->name ?? '')
            + ['seguro_id' => $option->id, 'seguro_name' => $option->name]
        )->values()->all();

        return response()->json(count($results) === 1
            ? $results[0]
            : $this->insuranceService->summarizeResults($results));
    }

    /**
     * Consulta la cédula contra TODAS las aseguradoras con integración, sin
     * escribir en ninguna ficha (crear y editar paciente): así recepción ve en
     * qué seguro figura el paciente aunque no lo haya elegido. Cada resultado
     * lleva `opciones`, las opciones equivalentes del multiselect, para saber
     * si ese seguro ya está cargado.
     */
    public function verifyCi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ci_paciente' => 'required|string|min:5|max:20',
        ]);

        $summary = $this->insuranceService->verifyCi(trim($data['ci_paciente']));

        $results = array_map(fn ($result) => [
            'seguro_id'   => $result['seguro_id'],
            'seguro_name' => $result['seguro_name'],
            'status'      => $result['status'],
            'badge'       => $result['badge'] ?? null,
            'message'     => $result['message'] ?? null,
            'opciones'    => $this->insuranceService->equivalentOptionIds($result['seguro_id']),
        ], $summary['results'] ?? []);

        return response()->json([
            'status'  => $summary['status'],
            'results' => $results,
        ]);
    }

    /**
     * Devuelve las opciones del atributo seguro_paciente.
     */
    public function insuranceOptions(): JsonResponse
    {
        $seguroAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
            ->findOneByField('code', 'seguro_paciente');

        if (! $seguroAttr) {
            return response()->json(['options' => []]);
        }

        $options = DB::table('attribute_options')
            ->where('attribute_id', $seguroAttr->id)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return response()->json(['options' => $options]);
    }

    /**
     * Devuelve el estado de cobertura persistido en BD para un paciente.
     */
    public function insuranceStatus(int $id): JsonResponse
    {
        $person = $this->personRepository->find($id);

        if (! $person) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $ciAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)->findOneByField('code', 'ci_paciente');
        $seguroAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)->findOneByField('code', 'seguro_paciente');

        $ci = $ciAttr ? $person->getCustomAttributeValue($ciAttr) : null;

        $insurances = $this->insuranceService->getPersonInsurances($person, $seguroAttr);

        // Estado persistido de cada aseguradora, indexado para pegarlo a su seguro.
        $statuses = $person->insuranceStatuses->keyBy('attribute_option_id');

        $seguros = array_map(function ($insurance) use ($statuses) {
            $persisted = $statuses->get($insurance['id']);

            return $insurance + [
                'status'     => $persisted?->status,
                'badge'      => $persisted?->badge,
                'message'    => $persisted?->message,
                'checked_at' => $persisted?->checked_at?->toISOString(),
            ];
        }, $insurances);

        return response()->json([
            'ci' => $ci,

            /**
             * `seguros` es la lista completa (multiselect), cada una con SU estado.
             * `seguro_id`/`seguro_label`/`status` se mantienen apuntando al primero
             * para no romper a los consumidores que aún leen un solo valor.
             */
            'seguros'      => $seguros,
            'seguro_id'    => $seguros[0]['id'] ?? null,
            'seguro_label' => $seguros[0]['name'] ?? null,
            'status'       => $person->insurance_status,
            'checked_at'   => $person->insurance_checked_at?->toISOString(),
        ]);
    }

    /**
     * Limpia la caché de verificación de un paciente.
     */
    public function clearCache(int $id): JsonResponse
    {
        $person = $this->personRepository->find($id);

        if (! $person) {
            return response()->json(['message' => 'Paciente no encontrado.'], 404);
        }

        $attributeRepository = app(\Webkul\Attribute\Repositories\AttributeRepository::class);

        $ci = $person->getCustomAttributeValue($attributeRepository->findOneByField('code', 'ci_paciente'));
        $seguroAttr = $attributeRepository->findOneByField('code', 'seguro_paciente');

        // Limpia la caché de todas las aseguradoras del paciente, no solo de una.
        $this->insuranceService->forgetCache($person, $seguroAttr, $ci);

        return response()->json(['message' => 'Caché de seguro limpiada correctamente.']);
    }
}
