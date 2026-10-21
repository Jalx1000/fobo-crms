<?php

namespace Webkul\Admin\DataGrids\Lead;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\CustomAttributeValueResolver;
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
                'leads.lead_value_moneda',
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
                'persons.contact_numbers as person_phone',
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

        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $queryBuilder->whereIn('leads.user_id', $userIds);
        }

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
        // Sin esto la búsqueda haría `WHERE person_phone LIKE …` sobre un alias,
        // que MySQL no acepta en WHERE.
        $this->addFilter('person_phone', 'persons.contact_numbers');
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
            'searchable'         => true,
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
            /*
             * Con la moneda del lead, no la de la instalación.
             *
             * `formatBasePrice()` asume una sola moneda, y acá se opera en
             * dólares y en bolivianos sin conversión: etiquetar un importe con
             * la moneda equivocada no es un detalle de formato, es un dato
             * falso. Los leads anteriores al campo caen en la moneda por
             * defecto, que es la que el sistema asume al crearlos.
             */
            'closure'    => fn ($row) => ($row->lead_value_moneda
                ?: config('inmobiliaria.inmuebles.moneda_por_defecto', 'USD'))
                .' '.number_format((float) $row->lead_value, 0, ',', '.'),
        ]);

        $this->addColumn([
            'index'              => 'tag_name',
            'label'              => trans('admin::app.leads.index.datagrid.tag-name'),
            'type'               => 'string',
            'searchable'         => true,
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

        /*
        | La descripción es donde el asesor escribe lo que el cliente pidió
        | («3 dormitorios, zona norte, hasta 120 mil»), así que es el campo más
        | valioso de la búsqueda global y estaba excluido.
        |
        | Sigue oculta como columna: en una tabla ocuparía todo el ancho. Oculto
        | y buscable son cosas distintas — `visibility` controla si se muestra,
        | `searchable` si entra en la búsqueda.
        */
        $this->addColumn([
            'index'      => 'description',
            'label'      => trans('admin::app.leads.index.datagrid.description'),
            'type'       => 'string',
            'searchable' => true,
            'visibility' => false,
        ]);

        /*
        | Teléfono del contacto, buscable y visible.
        |
        | `contact_numbers` es JSON —[{"label":"work","value":"59176616013"}]—
        | así que el LIKE corre contra el texto del JSON y encuentra el número.
        | Es tosco pero funciona, y evita un join extra a una tabla que no
        | existe: los teléfonos no están normalizados en filas.
        |
        | OJO: busca por dígitos tal como están guardados. Tecleando «76616013»
        | lo encuentra; tecleando «+591 766 16013» con espacios, no.
        */
        $this->addColumn([
            'index'      => 'person_phone',
            'label'      => 'Teléfono',
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => false,
            'closure'    => function ($row) {
                $numeros = json_decode($row->person_phone ?? '[]', true);

                if (! is_array($numeros) || ! $numeros) {
                    return '—';
                }

                return e(collect($numeros)->pluck('value')->filter()->implode(' · '));
            },
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
            /*
             * `lead_value` se excluye como el resto: ya tiene su propia columna
             * más arriba, con la moneda del lead.
             *
             * Este bucle agrega una columna por atributo personalizado y se
             * ejecuta DESPUÉS, así que sin esta línea pisaba la definición
             * anterior y el importe volvía a mostrarse con la moneda de la
             * instalación. La columna existía, su closure estaba bien, y el
             * grid usaba el otro.
             */
            if (in_array($attribute->code, ['title', 'description', 'lead_type_id', 'lead_type', 'lead_value'])) {
                continue;
            }

            /*
             * Filtro por atributo personalizado.
             *
             * Estas columnas no existen en `leads`: su valor vive en
             * `attribute_values`, en la columna que corresponde al tipo del
             * atributo. Sin esto, el campo aparecía en el grid y no se podía
             * filtrar por él.
             *
             * El filtro apunta a una subconsulta sobre `attribute_values`, en la
             * columna que corresponde al tipo del atributo. El DataGrid la trata
             * como cualquier columna y le aplica su LIKE, así que escribir una
             * parte del valor alcanza.
             *
             * En un `select` el valor guardado es el id de la opción, así que la
             * subconsulta devuelve el NOMBRE de la opción: quien filtra escribe
             * "Contado", no el número.
             */
            $this->addColumn([
                'index'      => $attribute->code,
                'label'      => $attribute->name,
                'type'       => 'string',
                'visibility' => false,
                'searchable' => false,
                'filterable' => true,
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

            // Va después de addColumn: addFilter recorre las columnas ya
            // registradas para reapuntarle el nombre a la subconsulta.
            $this->addFilter($attribute->code, $this->subconsultaAtributo($attribute));
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
     * Expresión que devuelve el valor de un atributo personalizado para cada fila.
     *
     * Se usa como "nombre de columna" del filtro: el DataGrid le aplica su LIKE
     * sin saber que detrás hay una subconsulta, y así un campo que vive en
     * `attribute_values` se filtra igual que uno de la tabla.
     */
    protected function subconsultaAtributo($attribute): \Illuminate\Database\Query\Expression
    {
        $prefijo = DB::getTablePrefix();

        $columna = match ($attribute->type) {
            'boolean'                 => 'boolean_value',
            'select', 'lookup'        => 'integer_value',
            'price'                   => 'float_value',
            'date'                    => 'date_value',
            'datetime'                => 'datetime_value',
            'checkbox', 'multiselect' => 'json_value',
            default                   => 'text_value',
        };

        // Un select guarda el id de la opción; se devuelve su nombre para que el
        // filtro compare contra lo que la persona ve en pantalla.
        if (in_array($attribute->type, ['select', 'lookup'], true)) {
            // `ao` es un alias, no una tabla: no lleva prefijo.
            return DB::raw("(
                SELECT ao.name
                FROM {$prefijo}attribute_values av
                JOIN {$prefijo}attribute_options ao ON ao.id = av.integer_value
                WHERE av.entity_id = {$prefijo}leads.id
                  AND av.entity_type = 'leads'
                  AND av.attribute_id = {$attribute->id}
                LIMIT 1
            )");
        }

        return DB::raw("(
            SELECT av.{$columna}
            FROM {$prefijo}attribute_values av
            WHERE av.entity_id = {$prefijo}leads.id
              AND av.entity_type = 'leads'
              AND av.attribute_id = {$attribute->id}
            LIMIT 1
        )");
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
