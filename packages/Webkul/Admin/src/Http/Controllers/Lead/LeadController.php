<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Admin\DataGrids\Lead\LeadDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\LeadForm;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\LeadResource;
use Webkul\Admin\Http\Resources\StageResource;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Lead\Helpers\MagicAI;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Lead\Repositories\ProductRepository;
use Webkul\Lead\Repositories\SourceRepository;
use Webkul\Lead\Repositories\StageRepository;
use Webkul\Lead\Repositories\TypeRepository;
use Webkul\Lead\Services\MagicAIService;
use Webkul\Tag\Repositories\TagRepository;
use Webkul\User\Repositories\UserRepository;

class LeadController extends Controller
{
    /**
     * Const variable for supported types.
     */
    const SUPPORTED_TYPES = 'pdf,bmp,jpeg,jpg,png,webp';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected UserRepository $userRepository,
        protected AttributeRepository $attributeRepository,
        protected SourceRepository $sourceRepository,
        protected TypeRepository $typeRepository,
        protected PipelineRepository $pipelineRepository,
        protected StageRepository $stageRepository,
        protected LeadRepository $leadRepository,
        protected ProductRepository $productRepository,
        protected PersonRepository $personRepository,
        protected ActivityRepository $activityRepository,
        protected DoctorRepository $doctorRepository,
        protected ShareMeDataService $shareMeDataService
    ) {
        request()->request->add(['entity_type' => 'leads']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(LeadDataGrid::class)->process();
        }

        if (request('pipeline_id')) {
            $pipeline = $this->pipelineRepository->find(request('pipeline_id'));
        } else {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        return view('admin::leads.index', [
            'pipeline' => $pipeline,
            'columns'  => $this->getKanbanColumns(),
        ]);
    }

    /**
     * Returns a listing of the resource.
     */
    public function get(): JsonResponse
    {
        if (request()->query('pipeline_id')) {
            $pipeline = $this->pipelineRepository->find(request()->query('pipeline_id'));
        } else {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        if ($stageId = request()->query('pipeline_stage_id')) {
            $stages = $pipeline->stages->where('id', request()->query('pipeline_stage_id'));
        } else {
            $stages = $pipeline->stages;
        }

        foreach ($stages as $stage) {
            /**
             * We have to create a new instance of the lead repository every time, which is
             * why we're not using the injected one.
             */
            $query = app(LeadRepository::class)
                ->pushCriteria(app(RequestCriteria::class))
                ->where([
                    'lead_pipeline_id'       => $pipeline->id,
                    'lead_pipeline_stage_id' => $stage->id,
                ]);

            if ($userIds = bouncer()->getAuthorizedUserIds()) {
                $query->whereIn('leads.user_id', $userIds);
            }

            // Buscador del kanban: paciente por nombre, teléfono o CI, o el título.
            if ($term = trim((string) request()->query('q', ''))) {
                app(\Webkul\Admin\Services\PatientSearch::class)->apply($query, $term, 'leads.person_id', ['leads.title']);
            }

            /**
             * Filtro por fecha de creación. Un rango personalizado (start_date/end_date)
             * tiene precedencia sobre el quick-filter (7 / 30 / 90 días / mes actual).
             * Aplicado antes del sum() clone y el paginador para que tanto los totales
             * de columna como las tarjetas lo respeten.
             */
            $startDate = request()->query('start_date');
            $endDate = request()->query('end_date');

            if ($startDate || $endDate) {
                if ($startDate) {
                    $query->whereDate('leads.created_at', '>=', $startDate);
                }

                if ($endDate) {
                    $query->whereDate('leads.created_at', '<=', $endDate);
                }
            } elseif ($from = $this->resolveDateRangeFrom(request()->query('date_range'))) {
                $query->where('leads.created_at', '>=', $from);
            }

            $stage->lead_value = (clone $query)->sum('lead_value');

            $data[$stage->sort_order] = (new StageResource($stage))->jsonSerialize();

            $data[$stage->sort_order]['leads'] = [
                'data' => LeadResource::collection($paginator = $query->with([
                    'tags',
                    'type',
                    'source',
                    'user',
                    'doctor',
                    'person',
                    'person.organization',
                    'pipeline',
                    'pipeline.stages',
                    'stage',
                    'attribute_values',
                ])->paginate($this->resolveKanbanPerPage())),

                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'from'         => $paginator->firstItem(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'to'           => $paginator->lastItem(),
                    'total'        => $paginator->total(),
                ],
            ];
        }

        return response()->json($data);
    }

    /**
     * Resolves the starting datetime for the kanban date-range quick filter.
     *
     * Accepted values: "7", "30", "90" (last N days) and "month" (current month).
     * Any other/empty value disables the filter (returns null). The boundary is
     * computed server-side so it is consistent regardless of the client timezone.
     */
    protected function resolveDateRangeFrom(?string $range): ?Carbon
    {
        return match ($range) {
            '7'     => now()->subDays(7),
            '30'    => now()->subDays(30),
            '90'    => now()->subDays(90),
            'month' => now()->startOfMonth(),
            default => null,
        };
    }

    /**
     * Resolves how many leads each kanban column page returns.
     *
     * Honours the request "limit" (sent by the infinite-scroll frontend) so the
     * backend and frontend share a single source of truth, clamped to a sane
     * range to avoid abusive page sizes.
     */
    protected function resolveKanbanPerPage(): int
    {
        $limit = (int) request()->query('limit', 10);

        return max(1, min($limit, 100));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $attributes = $this->attributeRepository->findWhere([
            'entity_type' => 'leads',
            'quick_add'   => 1,
        ]);

        $doctors = $this->doctorRepository->findWhere(['is_active' => 1]);

        return view('admin::leads.create', compact('attributes', 'doctors'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(LeadForm $request): RedirectResponse|JsonResponse
    {
        Event::dispatch('lead.create.before');

        $data = request()->all();
        $hasAppointment = ! empty($data['appointment_start']) && ! empty($data['appointment_end']);

        if ($hasAppointment) {
            $doctorId = $data['doctor_id'] ?? null;
            if (! $doctorId) {
                $msg = 'Debe seleccionar un doctor para programar una cita.';
                if (request()->ajax()) {
                    return response()->json(['message' => $msg], 422);
                }
                session()->flash('error', $msg);

                return redirect()->back()->withInput();
            }

            $scheduleFrom = Carbon::parse($data['appointment_start']);
            $scheduleTo = Carbon::parse($data['appointment_end']);

            // 1. Validar Conflictos Locales
            $localConflict = DB::table('activities')
                ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
                ->where('doctor_activities.doctor_id', $doctorId)
                ->where(function ($query) use ($scheduleFrom, $scheduleTo) {
                    $query->where(function ($q) use ($scheduleFrom, $scheduleTo) {
                        $q->where('schedule_from', '>=', $scheduleFrom)
                            ->where('schedule_from', '<', $scheduleTo);
                    })->orWhere(function ($q) use ($scheduleFrom, $scheduleTo) {
                        $q->where('schedule_to', '>', $scheduleFrom)
                            ->where('schedule_to', '<=', $scheduleTo);
                    })->orWhere(function ($q) use ($scheduleFrom, $scheduleTo) {
                        $q->where('schedule_from', '<=', $scheduleFrom)
                            ->where('schedule_to', '>=', $scheduleTo);
                    });
                })
                ->exists();

            if ($localConflict) {
                $msg = 'El doctor ya tiene una cita programada en este horario en el sistema local.';
                if (request()->ajax()) {
                    return response()->json(['message' => $msg], 422);
                }
                session()->flash('error', $msg);

                return redirect()->back()->withInput();
            }

            // 2. Validar Jornada Laboral (Shifts)
            $hasValidShift = DB::table('doctor_shifts')
                ->where('doctor_id', $doctorId)
                ->where('date', $scheduleFrom->toDateString())
                ->where('start_time', '<=', $scheduleFrom->format('H:i'))
                ->where('end_time', '>=', $scheduleTo->format('H:i'))
                ->exists();

            if (! $hasValidShift) {
                $msg = 'El horario seleccionado está fuera de la jornada laboral del doctor para este día.';
                if (request()->ajax()) {
                    return response()->json(['message' => $msg], 422);
                }
                session()->flash('error', $msg);

                return redirect()->back()->withInput();
            }

            // 3. Validar Disponibilidad en SMD
            $doctor = $this->doctorRepository->find($doctorId);
            $doctorExternalId = $doctor?->unique_id;

            if (empty($doctorExternalId)) {
                // Descubrimiento automático (Lógica reducida para LeadController)
                $discoverySpecialties = $doctor->specialties->pluck('name')->toArray() ?: ['General'];
                foreach ($discoverySpecialties as $spec) {
                    $this->shareMeDataService->checkAvailability(null, $spec, 'Santa Cruz', $scheduleFrom->format('Y-m-d H:i:s'), $scheduleTo->format('Y-m-d H:i:s'));
                    $raw = $this->shareMeDataService->getLastResponse();
                    if ($raw && isset($raw['body']) && is_array($raw['body'])) {
                        foreach ($raw['body'] as $item) {
                            $smdName = trim(strtolower(($item['physician']['name'] ?? '').' '.($item['physician']['lastName'] ?? '')));
                            if ($smdName === trim(strtolower($doctor->name))) {
                                $doctorExternalId = $item['physician']['_id'] ?? null;
                                if ($doctorExternalId) {
                                    $this->doctorRepository->update(['unique_id' => $doctorExternalId], $doctor->id);
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

            $doctorSpecialties = $doctor->specialties->pluck('name')->toArray() ?: ['General'];
            $isAvailableExternally = false;
            foreach ($doctorSpecialties as $specialty) {
                $slots = $this->shareMeDataService->checkAvailability($doctorExternalId, $specialty, 'Santa Cruz', $scheduleFrom->format('Y-m-d H:i:s'), $scheduleTo->format('Y-m-d H:i:s'));
                if (! empty($slots)) {
                    $isAvailableExternally = true;
                    break;
                }
            }

            if (! $isAvailableExternally) {
                $msg = 'El doctor no tiene disponibilidad en SHAREMEDATA para el horario solicitado.';
                if (request()->ajax()) {
                    return response()->json(['message' => $msg], 422);
                }
                session()->flash('error', $msg);

                return redirect()->back()->withInput();
            }
        }

        return DB::transaction(function () use ($data, $hasAppointment) {
            $data['status'] = 1;

            $targetStageId = $hasAppointment ? 2 : 1;
            $stage = $this->stageRepository->findOrFail($targetStageId);

            // Buscar lead existente del paciente para reutilizarlo
            $personId = ! empty($data['person']['id']) ? (int) $data['person']['id'] : null;
            $existingLead = null;

            if ($personId) {
                $existingLead = $this->leadRepository
                    ->findWhere(['person_id' => $personId])
                    ->sortByDesc('created_at')
                    ->first();
            }

            if ($existingLead) {
                // Reabrir el lead si está cerrado
                if (! is_null($existingLead->closed_at) || $existingLead->status != 1) {
                    $existingLead->update([
                        'status'                 => 1,
                        'closed_at'              => null,
                        'lead_pipeline_stage_id' => $stage->id,
                        'lead_pipeline_id'       => $stage->lead_pipeline_id,
                    ]);
                }

                $lead = $existingLead->fresh();

                if ($hasAppointment) {
                    $this->createAutomaticAppointment($lead, $data);

                    $this->syncAppointmentToSmd($lead, $data);
                }

                if (request()->ajax()) {
                    return response()->json([
                        'message' => trans('admin::app.leads.create-success'),
                        'data'    => new LeadResource($lead),
                    ]);
                }

                session()->flash('success', trans('admin::app.leads.create-success'));

                return redirect()->route('admin.leads.index', ['pipeline_id' => $lead->lead_pipeline_id]);
            }

            // Paciente nuevo: crear lead completo
            $data['lead_pipeline_stage_id'] = $stage->id;
            $data['lead_pipeline_id'] = $stage->lead_pipeline_id;

            if (in_array($stage->code, ['won', 'lost'])) {
                $data['closed_at'] = Carbon::now();
            }

            $lead = $this->leadRepository->create(Arr::except($data, ['doctor_id']));

            if ($hasAppointment) {
                $this->createAutomaticAppointment($lead, $data);

                $this->syncAppointmentToSmd($lead, $data);
            }

            Event::dispatch('lead.create.after', $lead);

            if (request()->ajax()) {
                return response()->json([
                    'message' => trans('admin::app.leads.create-success'),
                    'data'    => new LeadResource($lead),
                ]);
            }

            session()->flash('success', trans('admin::app.leads.create-success'));

            return redirect()->route('admin.leads.index', ['pipeline_id' => $lead->lead_pipeline_id]);
        });
    }

    /**
     * Envía la cita al sistema externo ShareMeData.
     */
    private function syncAppointmentToSmd($lead, $data): void
    {
        $person = $this->personRepository->find($lead->person_id);
        $nameParts = explode(' ', trim($person->name ?? 'Paciente'));
        $firstName = $nameParts[0] ?: 'Paciente';
        $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Externo';
        $phone = '77788990';

        if ($person && ! empty($person->contact_numbers)) {
            $numbers = is_array($person->contact_numbers)
                ? $person->contact_numbers
                : json_decode($person->contact_numbers, true);

            if (! empty($numbers[0]['value'])) {
                $phone = (string) $numbers[0]['value'];
            }
        }

        $doctor = $this->doctorRepository->find($data['doctor_id']);

        $this->shareMeDataService->createEvent([
            'summary'   => 'CONSULTA: '.$lead->title,
            'physician' => ['_id' => $doctor->unique_id, 'email' => $doctor->email ?: ''],
            'patient'   => [
                'name'      => (string) $firstName,
                'lastName'  => (string) $lastName,
                'phone'     => (string) $phone,
                'personID'  => '',
                'birthday'  => '',
            ],
            'slot' => [
                'start' => Carbon::parse($data['appointment_start'])->format('Y-m-d\TH:i:s-04:00'),
                'end'   => Carbon::parse($data['appointment_end'])->format('Y-m-d\TH:i:s-04:00'),
            ],
        ]);
    }

    /**
     * Crea una cita automática asociada al lead.
     */
    protected function createAutomaticAppointment($lead, $data)
    {
        try {
            $activityData = [
                'type'          => 'meeting',
                'title'         => 'Cita Confirmada: '.$lead->title,
                'comment'       => $lead->description ?: 'Cita creada automáticamente desde la creación de Lead.',
                'schedule_from' => Carbon::parse($data['appointment_start'])->format('Y-m-d H:i:s'),
                'schedule_to'   => Carbon::parse($data['appointment_end'])->format('Y-m-d H:i:s'),
                'is_done'       => 0,
                'user_id'       => auth()->guard('user')->id() ?? $lead->user_id,
                'participants'  => [
                    'persons' => [$lead->person_id],
                    'doctors' => isset($data['doctor_id']) ? [$data['doctor_id']] : [],
                ],
            ];

            $activity = $this->activityRepository->create($activityData);

            // Asociar actividad con el lead
            $activity->leads()->attach($lead->id);

            // Asociar productos/servicios del lead a la actividad
            if (isset($data['products'])) {
                $productIds = [];
                foreach ($data['products'] as $productData) {
                    if (isset($productData['product_id'])) {
                        $productIds[] = $productData['product_id'];
                    }
                }

                if (! empty($productIds)) {
                    $activity->products()->attach($productIds);
                }
            }

            Log::info('Cita automática creada con éxito', [
                'lead_id'     => $lead->id,
                'activity_id' => $activity->id,
                'start'       => $data['appointment_start'],
                'end'         => $data['appointment_end'],
            ]);

            return $activity;
        } catch (\Exception $e) {
            Log::error('Error al crear la cita automática', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);

            throw $e; // Provoca el rollback de la transacción
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $lead = $this->leadRepository->findOrFail($id);

        return view('admin::leads.edit', compact('lead'));
    }

    /**
     * Display a resource.
     */
    public function view(int $id)
    {
        try {
            $lead = $this->leadRepository->findOrFail($id);
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', trans('admin::app.leads.view.error-loading'));

            return redirect()->route('admin.leads.index');
        }

        $userIds = bouncer()->getAuthorizedUserIds();

        if (
            $userIds
            && ! in_array($lead->user_id, $userIds)
        ) {
            return redirect()->route('admin.leads.index');
        }

        return view('admin::leads.view', compact('lead'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(LeadForm $request, int $id): RedirectResponse|JsonResponse
    {
        Event::dispatch('lead.update.before', $id);

        $data = $request->all();

        if (isset($data['lead_pipeline_stage_id'])) {
            $stage = $this->stageRepository->findOrFail($data['lead_pipeline_stage_id']);

            $data['lead_pipeline_id'] = $stage->lead_pipeline_id;
        } else {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();

            $stage = $pipeline->stages()->first();

            $data['lead_pipeline_id'] = $pipeline->id;

            $data['lead_pipeline_stage_id'] = $stage->id;
        }

        $lead = $this->leadRepository->update($data, $id);

        Event::dispatch('lead.update.after', $lead);

        if (request()->ajax()) {
            return response()->json([
                'message' => trans('admin::app.leads.update-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.leads.update-success'));

        if (request()->has('closed_at')) {
            return redirect()->back();
        } else {
            return redirect()->route('admin.leads.index', $data['lead_pipeline_id']);
        }
    }

    /**
     * Update the lead attributes.
     */
    public function updateAttributes(int $id)
    {
        $data = request()->all();

        $attributes = $this->attributeRepository->findWhere([
            'entity_type' => 'leads',
            ['code', 'NOTIN', ['title', 'description']],
        ]);

        Event::dispatch('lead.update.before', $id);

        $lead = $this->leadRepository->update($data, $id, $attributes);

        Event::dispatch('lead.update.after', $lead);

        return response()->json([
            'message' => trans('admin::app.leads.update-success'),
        ]);
    }

    /**
     * Update the lead stage.
     */
    public function updateStage(int $id)
    {
        $this->validate(request(), [
            'lead_pipeline_stage_id' => 'required',
        ]);

        $lead = $this->leadRepository->findOrFail($id);

        $stage = $lead->pipeline->stages()
            ->where('id', request()->input('lead_pipeline_stage_id'))
            ->firstOrFail();

        Event::dispatch('lead.update.before', $id);

        $payload = request()->merge([
            'entity_type'            => 'leads',
            'lead_pipeline_stage_id' => $stage->id,
        ])->only([
            'closed_at',
            'lost_reason',
            'lead_pipeline_stage_id',
            'entity_type',
        ]);

        $lead = $this->leadRepository->update($payload, $id, ['lead_pipeline_stage_id']);

        Event::dispatch('lead.update.after', $lead);

        return response()->json([
            'message' => trans('admin::app.leads.update-success'),
        ]);
    }

    /**
     * Search person results.
     */
    public function search(): AnonymousResourceCollection
    {
        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $results = $this->leadRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->findWhereIn('user_id', $userIds);
        } else {
            $results = $this->leadRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->all();
        }

        return LeadResource::collection($results);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->leadRepository->findOrFail($id);

        try {
            Event::dispatch('lead.delete.before', $id);

            $this->leadRepository->delete($id);

            Event::dispatch('lead.delete.after', $id);

            return response()->json([
                'message' => trans('admin::app.leads.destroy-success'),
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.leads.destroy-failed'),
            ], 400);
        }
    }

    /**
     * Mass update the specified resources.
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResponse
    {
        $leads = $this->leadRepository->findWhereIn('id', $massUpdateRequest->input('indices'));

        try {
            foreach ($leads as $lead) {
                Event::dispatch('lead.update.before', $lead->id);

                $lead = $this->leadRepository->find($lead->id);

                $lead?->update(['lead_pipeline_stage_id' => $massUpdateRequest->input('value')]);

                Event::dispatch('lead.update.before', $lead->id);
            }

            return response()->json([
                'message' => trans('admin::app.leads.update-success'),
            ]);
        } catch (\Exception $th) {
            return response()->json([
                'message' => trans('admin::app.leads.update-failed'),
            ], 400);
        }
    }

    /**
     * Mass delete the specified resources.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $leads = $this->leadRepository->findWhereIn('id', $massDestroyRequest->input('indices'));

        try {
            foreach ($leads as $lead) {
                Event::dispatch('lead.delete.before', $lead->id);

                $this->leadRepository->delete($lead->id);

                Event::dispatch('lead.delete.after', $lead->id);
            }

            return response()->json([
                'message' => trans('admin::app.leads.destroy-success'),
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.leads.destroy-failed'),
            ]);
        }
    }

    /**
     * Attach product to lead.
     */
    public function addProduct(int $leadId): JsonResponse
    {
        $product = $this->productRepository->updateOrCreate(
            [
                'lead_id'    => $leadId,
                'product_id' => request()->input('product_id'),
            ],
            array_merge(
                request()->all(),
                [
                    'lead_id' => $leadId,
                    'amount'  => request()->input('price') * request()->input('quantity'),
                ],
            )
        );

        return response()->json([
            'data'    => $product,
            'message' => trans('admin::app.leads.update-success'),
        ]);
    }

    /**
     * Remove product attached to lead.
     */
    public function removeProduct(int $id): JsonResponse
    {
        try {
            Event::dispatch('lead.product.delete.before', $id);

            $this->productRepository->deleteWhere([
                'lead_id'    => $id,
                'product_id' => request()->input('product_id'),
            ]);

            Event::dispatch('lead.product.delete.after', $id);

            return response()->json([
                'message' => trans('admin::app.leads.destroy-success'),
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.leads.destroy-failed'),
            ]);
        }
    }

    /**
     * Kanban lookup.
     */
    public function kanbanLookup()
    {
        $params = $this->validate(request(), [
            'column'      => ['required'],
            'search'      => ['required', 'min:2'],
        ]);

        /**
         * Finding the first column from the collection.
         */
        $column = collect($this->getKanbanColumns())->where('index', $params['column'])->firstOrFail();

        /**
         * Fetching on the basis of column options.
         */
        return app($column['filterable_options']['repository'])
            ->select([$column['filterable_options']['column']['label'].' as label', $column['filterable_options']['column']['value'].' as value'])
            ->where($column['filterable_options']['column']['label'], 'LIKE', '%'.$params['search'].'%')
            ->get()
            ->map
            ->only('label', 'value');
    }

    /**
     * Get columns for the kanban view.
     */
    private function getKanbanColumns(): array
    {
        return [
            [
                'index'                 => 'id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.id'),
                'type'                  => 'integer',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => null,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'lead_value',
                'label'                 => trans('admin::app.leads.index.kanban.columns.lead-value'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => null,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'user_id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.sales-person'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => 'searchable_dropdown',
                'filterable_options'    => [
                    'repository' => UserRepository::class,
                    'column'     => [
                        'label' => 'name',
                        'value' => 'id',
                    ],
                ],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'person.id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.contact-person'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
                'filterable_type'       => 'searchable_dropdown',
                'filterable_options'    => [
                    'repository' => PersonRepository::class,
                    'column'     => [
                        'label' => 'name',
                        'value' => 'id',
                    ],
                ],
            ],
            [
                'index'                 => 'lead_type_id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.lead-type'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => 'dropdown',
                'filterable_options'    => $this->typeRepository->all(['name as label', 'id as value'])->toArray(),
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'lead_source_id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.source'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => 'dropdown',
                'filterable_options'    => $this->sourceRepository->all(['name as label', 'id as value'])->toArray(),
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'tags.name',
                'label'                 => trans('admin::app.leads.index.kanban.columns.tags'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
                'filterable_type'       => 'searchable_dropdown',
                'filterable_options'    => [
                    'repository' => TagRepository::class,
                    'column'     => [
                        'label' => 'name',
                        'value' => 'name',
                    ],
                ],
            ],
        ];
    }

    /**
     * Create lead with specified AI.
     */
    public function createByAI()
    {
        $leadData = [];

        $errorMessages = [];

        foreach (request()->file('files') as $file) {
            $lead = $this->processFile($file);

            if (
                isset($lead['status'])
                && $lead['status'] === 'error'
            ) {
                $errorMessages[] = $lead['message'];
            } else {
                $leadData[] = $lead;
            }
        }

        if (isset($errorMessages[0]['code'])) {
            return response()->json(MagicAI::errorHandler($errorMessages[0]['message']));
        }

        if (
            empty($leadData)
            && ! empty($errorMessages)
        ) {
            return response()->json(MagicAI::errorHandler(implode(', ', $errorMessages)), 400);
        }

        if (empty($leadData)) {
            return response()->json(MagicAI::errorHandler(trans('admin::app.leads.no-valid-files')), 400);
        }

        return response()->json([
            'message' => trans('admin::app.leads.create-success'),
            'leads'   => $this->createLeads($leadData),
        ]);
    }

    /**
     * Process file.
     *
     * @param  mixed  $file
     */
    private function processFile($file)
    {
        $validator = Validator::make(
            ['file' => $file],
            ['file' => 'required|extensions:'.str_replace(' ', '', self::SUPPORTED_TYPES)]
        );

        if ($validator->fails()) {
            return MagicAI::errorHandler($validator->errors()->first());
        }

        $base64Pdf = base64_encode(file_get_contents($file->getRealPath()));

        $extractedData = MagicAIService::extractDataFromFile($base64Pdf);

        $lead = MagicAI::mapAIDataToLead($extractedData);

        return $lead;
    }

    /**
     * Create multiple leads.
     */
    private function createLeads($rawLeads): array
    {
        $leads = [];

        foreach ($rawLeads as $rawLead) {
            Event::dispatch('lead.create.before');

            foreach ($rawLead['person']['emails'] as $email) {
                $person = $this->personRepository
                    ->whereJsonContains('emails', [['value' => $email['value']]])
                    ->first();

                if ($person) {
                    $rawLead['person']['id'] = $person->id;

                    break;
                }
            }

            $pipeline = $this->pipelineRepository->getDefaultPipeline();

            $stage = $pipeline->stages()->first();

            $lead = $this->leadRepository->create(array_merge($rawLead, [
                'lead_pipeline_id'       => $pipeline->id,
                'lead_pipeline_stage_id' => $stage->id,
            ]));

            Event::dispatch('lead.create.after', $lead);

            $leads[] = $lead;
        }

        return $leads;
    }
}
