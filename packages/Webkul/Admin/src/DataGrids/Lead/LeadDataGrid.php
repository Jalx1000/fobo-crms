<?php

namespace Webkul\Admin\DataGrids\Lead;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Concerns\NormalizesContactSearch;
use Webkul\Admin\Helpers\CustomAttributeValueResolver;
use Webkul\Admin\Support\VisibilidadLeads;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataGrid\DataGrid;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Lead\Repositories\SourceRepository;
use Webkul\Lead\Repositories\StageRepository;
use Webkul\Lead\Repositories\TypeRepository;
use Webkul\Tag\Repositories\TagRepository;
use Webkul\User\Repositories\UserRepository;

class LeadDataGrid extends DataGrid
{
    use NormalizesContactSearch;

    /**
     * Pipeline instance.
     *
     * @var \Webkul\Contract\Repositories\Pipeline
     */
    protected $pipeline;

    /**
     * Whether the grid is showing leads across all pipelines (cities).
     *
     * @var bool
     */
    protected $allPipelines = false;

    /**
     * Shared custom-attribute value resolver (kept as a single instance so its
     * lookup-label cache is reused across every exported row).
     */
    protected ?CustomAttributeValueResolver $attributeResolver = null;

    /**
     * Resolve the shared custom-attribute value resolver.
     */
    protected function attributeResolver(): CustomAttributeValueResolver
    {
        return $this->attributeResolver ??= app(CustomAttributeValueResolver::class);
    }

    /**
     * Create data grid instance.
     *
     * @return void
     */
    public function __construct(
        protected PipelineRepository $pipelineRepository,
        protected StageRepository $stageRepository,
        protected SourceRepository $sourceRepository,
        protected TypeRepository $typeRepository,
        protected UserRepository $userRepository,
        protected TagRepository $tagRepository,
        protected AttributeRepository $attributeRepository,
        protected LeadRepository $leadRepository,
    ) {
        $this->allPipelines = request('pipeline_id') === 'all';

        if (! $this->allPipelines && request('pipeline_id')) {
            $this->pipeline = $this->pipelineRepository->find(request('pipeline_id'));
        } else {
            $this->pipeline = $this->pipelineRepository->getDefaultPipeline();
        }
    }

    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $tablePrefix = DB::getTablePrefix();

        $queryBuilder = DB::table('leads')
            ->addSelect(
                'leads.id',
                'leads.title',
                'leads.description',
                'leads.status',
                'leads.lead_value',
                'leads.expected_close_date',
                'lead_sources.name as lead_source_name',
                'lead_types.name as lead_type_name',
                'leads.created_at',
                'lead_pipeline_stages.name as stage',
                'lead_tags.tag_id as tag_id',
                'users.id as user_id',
                'users.name as sales_person',
                'persons.id as person_id',
                'persons.name as person_name',
                'tags.name as tag_name',
                'lead_pipelines.rotten_days as pipeline_rotten_days',
                'lead_pipelines.name as pipeline_name',
                'lead_pipeline_stages.code as stage_code',
                DB::raw('CASE WHEN DATEDIFF(NOW(),'.$tablePrefix.'leads.created_at) >='.$tablePrefix.'lead_pipelines.rotten_days THEN 1 ELSE 0 END as rotten_lead'),
            )
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
            ->leftJoin('lead_types', 'leads.lead_type_id', '=', 'lead_types.id')
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->leftJoin('lead_sources', 'leads.lead_source_id', '=', 'lead_sources.id')
            ->leftJoin('lead_pipelines', 'leads.lead_pipeline_id', '=', 'lead_pipelines.id')
            ->leftJoin('lead_tags', 'leads.id', '=', 'lead_tags.lead_id')
            ->leftJoin('tags', 'tags.id', '=', 'lead_tags.tag_id')
            ->groupBy('leads.id');

        /**
         * In "all pipelines" mode we list leads from every city, so we skip the
         * pipeline scoping filter applied for a single pipeline view.
         */
        if (! $this->allPipelines) {
            $queryBuilder->where('leads.lead_pipeline_id', $this->pipeline->id);
        }

        // Por ciudad si su grupo designa una, por dueño si no.
        VisibilidadLeads::aplicar($queryBuilder);

        if ($dateFilter = request()->query('date_filter')) {
            if ($dateFilter === 'today') {
                $queryBuilder->whereBetween('leads.created_at', [now()->today()->startOfDay(), now()->today()->endOfDay()]);
            } elseif ($dateFilter === 'week') {
                $queryBuilder->whereBetween('leads.created_at', [now()->startOfWeek(), now()->endOfWeek()]);
            } elseif ($dateFilter === 'month') {
                $queryBuilder->whereBetween('leads.created_at', [now()->startOfMonth(), now()->endOfMonth()]);
            } elseif ($dateFilter === 'custom') {
                $from = request()->query('date_from');
                $to = request()->query('date_to');

                if ($from && $to) {
                    try {
                        $startDate = \Carbon\Carbon::parse($from)->startOfDay();
                        $endDate = \Carbon\Carbon::parse($to)->endOfDay();

                        if ($startDate->lte($endDate)) {
                            $queryBuilder->whereBetween('leads.created_at', [$startDate, $endDate]);
                        }
                    } catch (\Exception $e) {
                        // Skip filtering if date is invalid
                    }
                }
            }
        }

        if (! is_null(request()->input('rotten_lead.in'))) {
            $queryBuilder->havingRaw($tablePrefix.'rotten_lead = '.request()->input('rotten_lead.in'));
        }

        $this->addFilter('id', 'leads.id');
        $this->addFilter('user', 'leads.user_id');
        $this->addFilter('sales_person', 'users.name');
        $this->addFilter('lead_source_name', 'lead_sources.id');
        // $this->addFilter('lead_type_name', 'lead_types.id');
        $this->addFilter('person_name', 'persons.name');
        $this->addFilter('person_contact', DB::raw("CONCAT_WS(' ', {$tablePrefix}persons.unique_id, {$tablePrefix}persons.emails, {$tablePrefix}persons.contact_numbers)"));
        // $this->addFilter('type', 'lead_pipeline_stages.code');
        $this->addFilter('stage', 'lead_pipeline_stages.id');
        $this->addFilter('tag_name', 'tags.name');
        $this->addFilter('expected_close_date', 'leads.expected_close_date');
        $this->addFilter('created_at', 'leads.created_at');
        $this->addFilter('rotten_lead', DB::raw('DATEDIFF(NOW(), '.$tablePrefix.'leads.created_at) >= '.$tablePrefix.'lead_pipelines.rotten_days'));

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => trans('admin::app.leads.index.datagrid.id'),
            'type'       => 'integer',
            'sortable'   => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'              => 'sales_person',
            'label'              => trans('admin::app.leads.index.datagrid.sales-person'),
            'type'               => 'string',
            'searchable'         => false,
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'searchable_dropdown',
            'filterable_options' => [
                'repository' => UserRepository::class,
                'column'     => [
                    'label' => 'name',
                    'value' => 'name',
                ],
            ],
        ]);

        $this->addColumn([
            'index'      => 'title',
            'label'      => trans('admin::app.leads.index.datagrid.subject'),
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'              => 'lead_source_name',
            'label'              => trans('admin::app.leads.index.datagrid.source'),
            'type'               => 'string',
            'searchable'         => false,
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => $this->sourceRepository->all(['name as label', 'id as value'])->toArray(),
        ]);

        $this->addColumn([
            'index'      => 'lead_value',
            'label'      => trans('admin::app.leads.index.datagrid.lead-value'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => false,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatBasePrice($row->lead_value, 2),
        ]);

        $this->addColumn([
            'index'              => 'tag_name',
            'label'              => trans('admin::app.leads.index.datagrid.tag-name'),
            'type'               => 'string',
            'searchable'         => false,
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'searchable_dropdown',
            'closure'            => fn ($row) => $row->tag_name ?? '--',
            'filterable_options' => [
                'repository' => TagRepository::class,
                'column'     => [
                    'label' => 'name',
                    'value' => 'name',
                ],
            ],
        ]);

        $this->addColumn([
            'index'              => 'person_name',
            'label'              => trans('admin::app.leads.index.datagrid.contact-person'),
            'type'               => 'string',
            'searchable'         => true,
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'searchable_dropdown',
            'filterable_options' => [
                'repository' => \Webkul\Contact\Repositories\PersonRepository::class,
                'column'     => [
                    'label' => 'name',
                    'value' => 'name',
                ],
            ],
            'closure'    => function ($row) {
                $route = route('admin.contacts.persons.view', $row->person_id);

                return "<a class=\"text-brandColor transition-all hover:underline\" href='".$route."'>".$row->person_name.'</a>';
            },
        ]);

        /**
         * Hidden helper column: it exists only so the grid's free-text search also
         * matches the contact's email, phone and messenger id. Those live as JSON
         * arrays in `persons` (plus `unique_id`, which holds "<email>|<phone>"), so
         * there is nothing meaningful to render — hence not visible, not exportable
         * and not filterable.
         */
        $this->addColumn([
            'index'      => 'person_contact',
            'label'      => trans('admin::app.leads.index.datagrid.contact-person'),
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => false,
            'filterable' => false,
            'visibility' => false,
            'exportable' => false,
        ]);

        $this->addColumn([
            'index'              => 'stage',
            'label'              => trans('admin::app.leads.index.datagrid.stage'),
            'type'               => 'string',
            'searchable'         => false,
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => $this->pipeline->stages->pluck('name', 'id')
                ->map(function ($name, $id) {
                    return ['value' => $id, 'label' => $name];
                })
                ->values()
                ->all(),
        ]);

        /**
         * City (pipeline) column, only shown when listing across all pipelines so
         * each lead can be told apart by city.
         */
        if ($this->allPipelines) {
            $this->addColumn([
                'index'      => 'pipeline_name',
                'label'      => trans('admin::app.leads.index.datagrid.city'),
                'type'       => 'string',
                'searchable' => false,
                'sortable'   => true,
                'closure'    => function ($row) {
                    return '<span class="label-active">'.$row->pipeline_name.'</span>';
                },
            ]);
        }

        $this->addColumn([
            'index'      => 'rotten_lead',
            'label'      => trans('admin::app.leads.index.datagrid.rotten-lead'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => false,
            'closure'    => function ($row) {
                if (! $row->rotten_lead) {
                    return trans('admin::app.leads.index.datagrid.no');
                }

                if (in_array($row->stage_code, ['won', 'lost'])) {
                    return trans('admin::app.leads.index.datagrid.no');
                }

                return trans('admin::app.leads.index.datagrid.yes');
            },
        ]);

        $this->addColumn([
            'index'      => 'description',
            'label'      => trans('admin::app.leads.index.datagrid.description'),
            'type'       => 'string',
            'visibility' => false,
        ]);

        $this->addColumn([
            'index'      => 'activities',
            'label'      => 'Actividades',
            'type'       => 'string',
            'visibility' => false,
            'closure'    => function ($row) {
                static $lead;

                if (! $lead || $lead->id != $row->id) {
                    $lead = $this->leadRepository->find($row->id);
                }

                if (! $lead) {
                    return '';
                }

                return $lead->activities->whereNotIn('type', ['note', 'system'])->map(fn ($a) => "[{$a->type}] {$a->title}: {$a->comment}")->implode(' | ');
            },
        ]);

        $this->addColumn([
            'index'      => 'notes',
            'label'      => 'Notas',
            'type'       => 'string',
            'visibility' => false,
            'closure'    => function ($row) {
                static $lead;

                if (! $lead || $lead->id != $row->id) {
                    $lead = $this->leadRepository->find($row->id);
                }

                if (! $lead) {
                    return '';
                }

                return $lead->activities->where('type', 'note')->map(fn ($a) => "{$a->comment}")->implode(' | ');
            },
        ]);

        $this->addColumn([
            'index'      => 'products',
            'label'      => 'Productos',
            'type'       => 'string',
            'visibility' => false,
            'closure'    => function ($row) {
                static $lead;

                if (! $lead || $lead->id != $row->id) {
                    $lead = $this->leadRepository->find($row->id);
                }

                if (! $lead) {
                    return '';
                }

                return $lead->products->map(fn ($p) => "{$p->name} (Cant: {$p->quantity})")->implode(' | ');
            },
        ]);

        $attributes = $this->attributeRepository->findWhere(['entity_type' => 'leads']);

        foreach ($attributes as $attribute) {
            if (in_array($attribute->code, ['title', 'description', 'lead_type_id', 'lead_type'])) {
                continue;
            }

            $this->addColumn([
                'index'      => $attribute->code,
                'label'      => $attribute->name,
                'type'       => 'string',
                'visibility' => false,
                'closure'    => function ($row) use ($attribute) {
                    static $lead;

                    if (! $lead || $lead->id != $row->id) {
                        $lead = $this->leadRepository->find($row->id);
                    }

                    if (! $lead) {
                        return '';
                    }

                    $value = $lead->getCustomAttributeValue($attribute);

                    return $this->attributeResolver()->resolve($attribute, $value);
                },
            ]);
        }

        $this->addColumn([
            'index'           => 'expected_close_date',
            'label'           => trans('admin::app.leads.index.datagrid.date-to'),
            'type'            => 'date',
            'searchable'      => false,
            'sortable'        => true,
            'filterable'      => true,
            'filterable_type' => 'date_range',
            'closure'         => function ($row) {
                if (! $row->expected_close_date) {
                    return '--';
                }

                return $row->expected_close_date;
            },
        ]);

        $this->addColumn([
            'index'           => 'created_at',
            'label'           => trans('admin::app.leads.index.datagrid.created-at'),
            'type'            => 'date',
            'searchable'      => false,
            'sortable'        => true,
            'filterable'      => true,
            'filterable_type' => 'date_range',
        ]);
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('leads.view')) {
            $this->addAction([
                'icon'   => 'icon-eye',
                'title'  => trans('admin::app.leads.index.datagrid.view'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.leads.view', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('leads.delete')) {
            $this->addAction([
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.leads.index.datagrid.delete'),
                'method' => 'delete',
                'url'    => fn ($row) => route('admin.leads.delete', $row->id),
            ]);
        }
    }

    /**
     * Prepare mass actions.
     */
    public function prepareMassActions(): void
    {
        $this->addMassAction([
            'icon'   => 'icon-delete',
            'title'  => trans('admin::app.leads.index.datagrid.mass-delete'),
            'method' => 'POST',
            'url'    => route('admin.leads.mass_delete'),
        ]);

        $this->addMassAction([
            'title'   => trans('admin::app.leads.index.datagrid.mass-update'),
            'url'     => route('admin.leads.mass_update'),
            'method'  => 'POST',
            'options' => $this->pipeline->stages->map(fn ($stage) => [
                'label' => $stage->name,
                'value' => $stage->id,
            ])->toArray(),
        ]);
    }
}
