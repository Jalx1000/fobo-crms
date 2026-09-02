<?php

namespace Webkul\Admin\Http\Controllers\Lead;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\DataGrids\Lead\LeadDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\LeadForm;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\LeadResource;
use Webkul\Admin\Http\Resources\StageResource;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Contact\Repositories\PersonRepository;
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
        protected PersonRepository $personRepository
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

        /**
         * Global city filter: shared (via the "global_pipeline_id" cookie) with the
         * dashboard so selecting a city persists across the whole app.
         *
         * - If the URL has no explicit pipeline_id but a global filter is saved,
         *   redirect to the same URL with that pipeline_id so every existing
         *   request('pipeline_id')-based view (kanban, table, datagrid, switcher)
         *   keeps working unchanged.
         * - If the URL has an explicit pipeline_id, persist it as the global filter.
         */
        if (request()->query('pipeline_id') === null) {
            $savedPipelineId = request()->cookie('global_pipeline_id');

            if ($savedPipelineId) {
                return redirect()->route('admin.leads.index', array_merge(
                    request()->except('pipeline_id'),
                    ['pipeline_id' => $savedPipelineId]
                ));
            }
        } elseif (request()->query('pipeline_id') !== '') {
            // httpOnly=false so the dashboard's JS can read the same cookie.
            Cookie::queue('global_pipeline_id', request()->query('pipeline_id'), 60 * 24 * 365, '/', null, false, false, false, 'Lax');
        }

        /**
         * Global date filter: shared (via the "global_date_range" cookie) with the
         * dashboard, normalized to an explicit start/end range (date_from/date_to).
         *
         * - "date_filter=none" is an explicit clear: forget the cookie and strip
         *   the date params from the URL.
         * - No date params + saved range => redirect applying it as a custom range.
         * - Any date params => resolve to an explicit range and persist it.
         */
        if (request()->query('date_filter') === 'none') {
            Cookie::queue(Cookie::forget('global_date_range'));

            return redirect()->route('admin.leads.index', request()->except(['date_filter', 'date_from', 'date_to']));
        }

        $hasDateParam = request()->query('date_filter') !== null
            || request()->query('date_from') !== null
            || request()->query('date_to') !== null;

        if (! $hasDateParam) {
            if ($savedRange = request()->cookie('global_date_range')) {
                [$from, $to] = array_pad(explode('|', $savedRange), 2, null);

                if ($from && $to) {
                    return redirect()->route('admin.leads.index', array_merge(
                        request()->all(),
                        ['date_filter' => 'custom', 'date_from' => $from, 'date_to' => $to]
                    ));
                }
            }
        } elseif ($range = $this->resolveGlobalDateRange()) {
            Cookie::queue('global_date_range', $range['from'].'|'.$range['to'], 60 * 24 * 365, '/', null, false, false, false, 'Lax');
        }

        $allPipelines = request('pipeline_id') === 'all';

        if (! $allPipelines && request('pipeline_id')) {
            $pipeline = $this->pipelineRepository->find(request('pipeline_id'));
        } else {
            // In "all pipelines" mode we use the default pipeline as the canonical
            // stage template (every pipeline/city shares the same stage codes).
            $pipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        // Guard against a stale/invalid saved city (e.g. a deleted pipeline).
        if (! $pipeline) {
            Cookie::queue(Cookie::forget('global_pipeline_id'));

            $pipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        return view('admin::leads.index', [
            'pipeline'     => $pipeline,
            'columns'      => $this->getKanbanColumns(),
            'allPipelines' => $allPipelines,
        ]);
    }

    /**
     * Resolve the current request's date filter into an explicit
     * [from, to] range (Y-m-d) for the shared global_date_range cookie.
     */
    private function resolveGlobalDateRange(): ?array
    {
        switch (request()->query('date_filter')) {
            case 'today':
                $from = Carbon::today();
                $to = Carbon::today();
                break;

            case 'week':
                $from = Carbon::now()->startOfWeek();
                $to = Carbon::now()->endOfWeek();
                break;

            case 'month':
                $from = Carbon::now()->startOfMonth();
                $to = Carbon::now()->endOfMonth();
                break;

            default:
                $from = request()->query('date_from');
                $to = request()->query('date_to');

                if (! $from || ! $to) {
                    return null;
                }

                try {
                    $from = Carbon::parse($from);
                    $to = Carbon::parse($to);
                } catch (\Exception $e) {
                    return null;
                }
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to'   => $to->format('Y-m-d'),
        ];
    }

    /**
     * Returns a listing of the resource.
     */
    public function get(): JsonResponse
    {
        $allPipelines = request()->query('pipeline_id') === 'all';

        if (! $allPipelines && request()->query('pipeline_id')) {
            $pipeline = $this->pipelineRepository->find(request()->query('pipeline_id'));
        } else {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        /**
         * Guard against an unknown pipeline_id (find() returns null): fall back to
         * the default pipeline so we never dereference a null $pipeline below.
         */
        if (! $pipeline) {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        if ($stageId = request()->query('pipeline_stage_id')) {
            $stages = $pipeline->stages->where('id', request()->query('pipeline_stage_id'));
        } else {
            $stages = $pipeline->stages;
        }

        /**
         * Initialize the payload up-front. When a pipeline_stage_id that doesn't
         * belong to $pipeline is requested (e.g. stale/cross-pipeline params forwarded
         * from the URL), $stages is an empty collection and the loop below never runs.
         * Without this, $data would be undefined and the endpoint would 500
         * ("Undefined variable $data"). An empty payload yields an empty board instead.
         */
        $data = [];

        $dateRange = null;

        /**
         * Here, we're checking if the date filter is present in the search string.
         * If it is, then we'll extract the date filter and create a date range.
         */
        if ($search = request()->query('search')) {
            $searchParts = explode(';', $search);

            foreach ($searchParts as $part) {
                if (str_contains($part, 'created_at:')) {
                    $value = str_replace('created_at:', '', $part);

                    if ($value === 'today') {
                        $dateRange = [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
                    } elseif ($value === 'week') {
                        $dateRange = [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()];
                    } elseif ($value === 'month') {
                        $dateRange = [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
                    }

                    // Remove the created_at from search so RequestCriteria doesn't try to filter it as a literal string
                    if ($dateRange) {
                        $search = str_replace($part.';', '', $search);
                        $search = str_replace($part, '', $search);
                        request()->merge(['search' => $search]);
                    }
                }
            }
        }

        // Keep existing logic for backward compatibility or direct date_filter usage
        if (! $dateRange) {
            $dateFilter = request()->query('date_filter');

            if ($dateFilter === 'today') {
                $dateRange = [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
            } elseif ($dateFilter === 'week') {
                $dateRange = [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()];
            } elseif ($dateFilter === 'month') {
                $dateRange = [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
            } elseif ($dateFilter === 'custom') {
                $from = request()->query('date_from');
                $to = request()->query('date_to');

                if ($from && $to) {
                    try {
                        $dateRange = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];

                        if ($dateRange[0]->gt($dateRange[1])) {
                            $dateRange = null;
                        }
                    } catch (\Exception $e) {
                        $dateRange = null;
                    }
                }
            }
        }

        foreach ($stages as $stage) {
            /**
             * We have to create a new instance of the lead repository every time, which is
             * why we're not using the injected one.
             */
            $query = app(LeadRepository::class)
                ->pushCriteria(app(RequestCriteria::class));

            if ($allPipelines) {
                /**
                 * In "all pipelines" mode we merge each city's stage that sits in the
                 * same position (sort_order) into a single column. We intentionally
                 * match by sort_order rather than by `code`: every city shares the
                 * same stage structure by position, but stored codes can be dirty
                 * (e.g. a stray leading dash "-no-atendido" produced by the pipeline
                 * form's slugify), which would silently drop that city's leads from
                 * the combined column.
                 */
                $stageIds = $this->stageRepository->findWhere(['sort_order' => $stage->sort_order])->pluck('id')->toArray();

                $query = $query->whereIn('leads.lead_pipeline_stage_id', $stageIds);
            } else {
                $query = $query->where([
                    'lead_pipeline_id'       => $pipeline->id,
                    'lead_pipeline_stage_id' => $stage->id,
                ]);
            }

            if ($userIds = bouncer()->getAuthorizedUserIds()) {
                $query->whereIn('leads.user_id', $userIds);
            }

            if ($dateRange) {
                /**
                 * Se filtra SIEMPRE por created_at (fecha de creación del lead),
                 * igual que el dashboard (stageEventDateExpr) y el módulo de
                 * Contactos (persons.created_at). Así, en una ventana de N días, el
                 * tablero y los contactos cuentan el mismo conjunto: un cliente
                 * viejo que solo avanzó de etapa dentro de la ventana ya no aparece
                 * en el tablero como si fuera nuevo.
                 */
                $query->whereBetween('leads.created_at', $dateRange);
            }

            /**
             * Deterministic ordering so infinite-scroll pagination never duplicates
             * or drops leads across pages (especially in "all pipelines" mode where
             * the column aggregates leads from every city).
             */
            $query->orderBy('leads.id', 'desc');

            $stage->lead_value = (clone $query)->sum('lead_value');

            /*
             * Totales por moneda, sin sumarlas entre sí.
             *
             * Esta plaza cierra tanto en dólares como en bolivianos y el sistema
             * no convierte en ningún punto, así que un único total mezcla dos
             * unidades distintas: 247.000 USD + 1.700.000 BOB daría 1.947.000 de
             * nada. Se devuelve el desglose y la columna muestra una línea por
             * moneda.
             *
             * Los leads sin moneda —cerrados antes de que el campo existiera—
             * cuentan en la moneda por defecto, que es la que el sistema asume
             * al crearlos.
             */
            $porDefecto = config('inmobiliaria.inmuebles.moneda_por_defecto', 'USD');

            // El prefijo va explícito: la tabla real es `kl_leads`, y un
            // `leads.columna` en SQL crudo no lo resuelve solo.
            $tabla = DB::getTablePrefix().'leads';

            $stage->lead_values_por_moneda = (clone $query)
                ->selectRaw("COALESCE({$tabla}.lead_value_moneda, ?) as moneda, SUM({$tabla}.lead_value) as total", [$porDefecto])
                ->reorder()
                ->groupBy('moneda')
                ->pluck('total', 'moneda')
                ->map(fn ($total) => (float) $total)
                ->filter(fn ($total) => $total > 0)
                ->all();

            $data[$stage->sort_order] = (new StageResource($stage))->jsonSerialize();

            /**
             * Keep the "all" context so kanban pagination (append) keeps requesting
             * across all pipelines instead of the canonical pipeline only.
             */
            if ($allPipelines) {
                $data[$stage->sort_order]['lead_pipeline_id'] = 'all';
            }

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
                ])->paginate(100)),

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
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $attributes = $this->attributeRepository->findWhere([
            'entity_type' => 'leads',
            'quick_add'   => 1,
        ]);

        return view('admin::leads.create', compact('attributes'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(LeadForm $request): RedirectResponse|JsonResponse
    {
        Event::dispatch('lead.create.before');

        $data = request()->all();

        $data['status'] = 1;

        if (! empty($data['lead_pipeline_stage_id'])) {
            $stage = $this->stageRepository->findOrFail($data['lead_pipeline_stage_id']);

            $data['lead_pipeline_id'] = $stage->lead_pipeline_id;
        } else {
            if (empty($data['lead_pipeline_id'])) {
                $pipeline = $this->pipelineRepository->getDefaultPipeline();

                $data['lead_pipeline_id'] = $pipeline->id;
            } else {
                $pipeline = $this->pipelineRepository->findOrFail($data['lead_pipeline_id']);
            }

            $stage = $pipeline->stages()->first();

            $data['lead_pipeline_stage_id'] = $stage->id;
        }

        if (in_array($stage->code, ['won', 'lost'])) {
            $data['closed_at'] = Carbon::now();
        }

        $lead = $this->leadRepository->create($data);

        if (request()->ajax()) {
            return response()->json([
                'message' => trans('admin::app.leads.create-success'),
                'data'    => new LeadResource($lead),
            ]);
        }

        Event::dispatch('lead.create.after', $lead);

        session()->flash('success', trans('admin::app.leads.create-success'));

        if (! empty($data['lead_pipeline_id'])) {
            $params['pipeline_id'] = $data['lead_pipeline_id'];
        }

        return redirect()->route('admin.leads.index', $params ?? []);
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

        /**
         * Resolve the target stage within the lead's OWN pipeline. When dragging in
         * "all pipelines" mode the incoming stage id belongs to the canonical pipeline,
         * so we match by code to keep the lead inside its own city; otherwise the stage
         * already belongs to the lead's pipeline and resolves to itself.
         */
        $requestedStage = $this->stageRepository->find(request()->input('lead_pipeline_stage_id'));

        $stage = ($requestedStage
            ? $lead->pipeline->stages()->where('code', $requestedStage->code)->first()
            : null)
            ?? $lead->pipeline->stages()
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
                'filterable'            => false,
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
            [
                'index'                 => 'created_at',
                'label'                 => trans('admin::app.leads.index.kanban.columns.created-at'),
                'type'                  => 'date',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => 'date_range',
                'filterable_options'    => [
                    [
                        'name'  => 'today',
                        'label' => trans('admin::app.components.datagrid.filters.date-options.today'),
                    ],
                    [
                        'name'  => 'week',
                        'label' => trans('admin::app.components.datagrid.filters.date-options.this-week'),
                    ],
                    [
                        'name'  => 'month',
                        'label' => trans('admin::app.components.datagrid.filters.date-options.this-month'),
                    ],
                ],
                'allow_multiple_values' => false,
                'sortable'              => true,
                'visibility'            => true,
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
