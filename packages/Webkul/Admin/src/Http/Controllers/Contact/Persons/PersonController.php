<?php

namespace Webkul\Admin\Http\Controllers\Contact\Persons;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\DataGrids\Contact\PersonDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\AttributeForm;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Resources\PersonResource;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Contact\Repositories\PersonRepository;

class PersonController extends Controller
{
    /**
     * Create a new class instance.
     *
     * @return void
     */
    public function __construct(
        protected PersonRepository $personRepository,
        protected ShareMeDataService $shareMeDataService,
    ) {
        request()->request->add(['entity_type' => 'persons']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(PersonDataGrid::class)->process();
        }

        return view('admin::contacts.persons.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin::contacts.persons.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AttributeForm $request): RedirectResponse|JsonResponse
    {
        Event::dispatch('contacts.person.create.before');

        $person = $this->personRepository->create($request->all());

        Event::dispatch('contacts.person.create.after', $person);

        if (request()->ajax()) {
            return response()->json([
                'data'    => new PersonResource($person),
                'message' => trans('admin::app.contacts.persons.index.create-success'),
            ]);
        }

        // Reload para obtener smd_patient_id que pudo haber sido asignado por el listener
        $person->refresh();

        session()->flash('success', trans('admin::app.contacts.persons.index.create-success'));

        if ($person->smd_patient_id) {
            session()->flash('info', 'Paciente vinculado a ShareMeData ✓ (ID: '.$person->smd_patient_id.')');
        } else {
            session()->flash('warning', 'Paciente creado, pero no se encontró en ShareMeData. Verifícalo manualmente desde su perfil.');
        }

        return redirect()->route('admin.contacts.persons.view', $person->id);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): View
    {
        $person = $this->personRepository->findOrFail($id);

        return view('admin::contacts.persons.view', compact('person'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $person = $this->personRepository->findOrFail($id);

        return view('admin::contacts.persons.edit', compact('person'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AttributeForm $request, int $id): RedirectResponse|JsonResponse
    {
        Event::dispatch('contacts.person.update.before', $id);

        $person = $this->personRepository->update($request->all(), $id);

        Event::dispatch('contacts.person.update.after', $person);

        if (request()->ajax()) {
            return response()->json([
                'data'    => $person,
                'message' => trans('admin::app.contacts.persons.index.update-success'),
            ], 200);
        }

        session()->flash('success', trans('admin::app.contacts.persons.index.update-success'));

        return redirect()->route('admin.contacts.persons.index');
    }

    /**
     * Search person results.
     */
    public function search(): JsonResource
    {
        // Selector de paciente (ej. "Añadir cita" del calendario): por nombre,
        // teléfono o CI, igual que los buscadores de los listados.
        if ($query = trim((string) request()->get('query'))) {
            $persons = $this->personRepository->getModel()->newQuery()
                ->whereIn('id', app(\Webkul\Admin\Services\PatientSearch::class)->matchingPersons($query))
                ->when(bouncer()->getAuthorizedUserIds(), fn ($q, $userIds) => $q->whereIn('user_id', $userIds))
                ->orderBy('name')
                ->get();

            return PersonResource::collection($persons);
        }

        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $persons = $this->personRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->findWhereIn('user_id', $userIds);
        } else {
            $persons = $this->personRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->all();
        }

        return PersonResource::collection($persons);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $person = $this->personRepository->findOrFail($id);

        if (
            $person->leads
            && $person->leads->count() > 0
        ) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }

        try {
            Event::dispatch('contacts.person.delete.before', $person);

            $person->delete();

            Event::dispatch('contacts.person.delete.after', $person);

            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-success'),
            ], 200);

        } catch (Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Busca un paciente en SMD por teléfono, CI o email.
     * Usado por el verificador del formulario de creación.
     */
    public function searchSmd(Request $request): JsonResponse
    {
        if (! bouncer()->hasPermission('contacts.persons.edit')) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $term = trim($request->get('q', ''));

        if (strlen($term) < 3) {
            return response()->json(['found' => false, 'message' => 'Ingresa al menos 3 caracteres.']);
        }

        // 1. Por teléfono
        $results = $this->shareMeDataService->searchPatient($term);

        // 2. Por CI
        if (empty($results)) {
            $results = $this->shareMeDataService->searchPatientByCi($term);
        }

        // 3. Por email
        if (empty($results)) {
            $results = $this->shareMeDataService->searchPatientByEmail($term);
        }

        if (empty($results)) {
            return response()->json(['found' => false]);
        }

        $p = $results[0];

        return response()->json([
            'found'     => true,
            'smd_id'    => $p['_id'] ?? null,
            'name'      => trim(($p['name'] ?? '').' '.($p['lastName'] ?? '')),
            'phone'     => $p['phone'] ?? null,
            'ci'        => $p['personID'] ?? null,
            'email'     => $p['secondEmail'] ?? null,
            'birthday'  => isset($p['birthday'])
                ? \Carbon\Carbon::parse($p['birthday'])->format('Y-m-d')
                : null,
            'insurance' => $p['extra']['insurance'][0] ?? null,
            // Todas: un paciente puede tener varias y el campo Seguro es multiselect.
            'insurances' => array_values(array_filter(
                (array) ($p['extra']['insurance'] ?? []),
                fn ($nombre) => is_string($nombre) && trim($nombre) !== ''
            )),
        ]);
    }

    /**
     * Sincroniza manualmente el paciente con ShareMeData.
     */
    public function syncSmd(int $id): JsonResponse
    {
        if (! bouncer()->hasPermission('contacts.persons.edit')) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $person = $this->personRepository->find($id);

        if (! $person) {
            return response()->json(['message' => 'Paciente no encontrado'], 404);
        }

        $contactNumbers = is_array($person->contact_numbers)
            ? $person->contact_numbers
            : json_decode($person->contact_numbers ?? '[]', true);

        $phone = $contactNumbers[0]['value'] ?? null;

        if (! $phone || $phone === '0') {
            return response()->json([
                'message' => 'El paciente no tiene número de teléfono registrado.',
            ], 422);
        }

        $smdPatients = $this->shareMeDataService->searchPatient($phone);

        if (! empty($smdPatients)) {
            $smdPatientId = $smdPatients[0]['_id'] ?? null;
            $action = 'encontrado';
        } else {
            $nameParts = explode(' ', trim($person->name ?? ''));
            $ciAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
                ->findOneByField('code', 'ci_paciente');
            $ci = $ciAttr ? $person->getCustomAttributeValue($ciAttr) : null;

            $result = $this->shareMeDataService->createPatient([
                'first_name' => $nameParts[0] ?? 'Paciente',
                'last_name'  => implode(' ', array_slice($nameParts, 1)) ?: 'Externo',
                'phone'      => $phone,
                'ci'         => $ci ?: null,
            ]);

            if (! $result['success'] && ($result['duplicate'] ?? false)) {
                $retry = $this->shareMeDataService->searchPatient($phone);
                $smdPatientId = $retry[0]['_id'] ?? null;
                $action = 'recuperado_duplicado';
            } elseif ($result['success']) {
                $smdPatientId = $result['data']['_id'] ?? null;
                $action = 'creado';
            } else {
                return response()->json([
                    'message' => 'No se pudo sincronizar con ShareMeData: '.($result['message'] ?? 'Error desconocido'),
                ], 422);
            }
        }

        if (! $smdPatientId) {
            return response()->json(['message' => 'No se obtuvo ID de SMD. Intenta de nuevo.'], 422);
        }

        // DB directo para evitar que PersonRepository::sanitize() borre user_id
        \Illuminate\Support\Facades\DB::table('persons')->where('id', $id)->update(['smd_patient_id' => $smdPatientId]);

        return response()->json([
            'success'        => true,
            'smd_patient_id' => $smdPatientId,
            'action'         => $action,
            'message'        => match ($action) {
                'encontrado'           => 'Paciente vinculado con ShareMeData correctamente.',
                'creado'               => 'Paciente creado en ShareMeData y vinculado.',
                'recuperado_duplicado' => 'Paciente ya existía en ShareMeData. Vinculado correctamente.',
                default                => 'Sincronizado.',
            },
        ]);
    }

    /**
     * Retorna los datos del paciente vinculado en SMD.
     */
    public function smdData(int $id): JsonResponse
    {
        // Devuelve la ficha clínica de SMD (CI, teléfono, seguro): mismo nivel de
        // acceso que ver el contacto. Era el único endpoint SMD sin permiso (AUD-10).
        if (! bouncer()->hasPermission('contacts.persons.view')) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $person = $this->personRepository->find($id);

        if (! $person) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $person->smd_patient_id) {
            return response()->json(['linked' => false]);
        }

        $data = $this->shareMeDataService->getPatient($person->smd_patient_id);

        if (! $data) {
            return response()->json([
                'linked' => true,
                'smd_id' => $person->smd_patient_id,
                'stale'  => true,
            ]);
        }

        return response()->json([
            'linked'    => true,
            'smd_id'    => $person->smd_patient_id,
            'stale'     => false,
            'name'      => trim(($data['name'] ?? '').' '.($data['lastName'] ?? '')),
            'phone'     => $data['phone'] ?? null,
            'ci'        => $data['personID'] ?? null,
            'insurance' => $data['extra']['insurance'][0] ?? null,
        ]);
    }

    /**
     * Vincula manualmente un paciente CRM con un ID de SMD.
     */
    public function linkSmd(Request $request, int $id): JsonResponse
    {
        if (! bouncer()->hasPermission('contacts.persons.edit')) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Un id de SMD es un ObjectId de Mongo (24 hex). Validarlo evita que
        // "../calendar/specialties" viaje en la URL: SMD respondía otra cosa y el
        // paciente quedaba vinculado a un id basura (AUD-10).
        $request->validate(['smd_patient_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i']]);

        $person = $this->personRepository->find($id);

        if (! $person) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $smdData = $this->shareMeDataService->getPatient($request->smd_patient_id);

        if (! $smdData) {
            return response()->json(['message' => 'El ID proporcionado no existe en SMD'], 422);
        }

        \Illuminate\Support\Facades\DB::table('persons')
            ->where('id', $id)
            ->update(['smd_patient_id' => $request->smd_patient_id]);

        return response()->json([
            'message' => 'Paciente vinculado correctamente',
            'smd_id'  => $request->smd_patient_id,
        ]);
    }

    /**
     * Mass destroy the specified resources from storage.
     */
    public function massDestroy(MassDestroyRequest $request): JsonResponse
    {
        try {
            $persons = $this->personRepository->findWhereIn('id', $request->input('indices', []));

            $deletedCount = 0;

            $blockedCount = 0;

            foreach ($persons as $person) {
                if (
                    $person->leads
                    && $person->leads->count() > 0
                ) {
                    $blockedCount++;

                    continue;
                }

                Event::dispatch('contact.person.delete.before', $person);

                $this->personRepository->delete($person->id);

                Event::dispatch('contact.person.delete.after', $person);

                $deletedCount++;
            }

            $statusCode = 200;

            switch (true) {
                case $deletedCount > 0 && $blockedCount === 0:
                    $message = trans('admin::app.contacts.persons.index.all-delete-success');

                    break;

                case $deletedCount > 0 && $blockedCount > 0:
                    $message = trans('admin::app.contacts.persons.index.partial-delete-warning');

                    break;

                case $deletedCount === 0 && $blockedCount > 0:
                    $message = trans('admin::app.contacts.persons.index.none-delete-warning');

                    $statusCode = 400;

                    break;

                default:
                    $message = trans('admin::app.contacts.persons.index.no-selection');

                    $statusCode = 400;

                    break;
            }

            return response()->json(['message' => $message], $statusCode);
        } catch (Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }
    }
}
