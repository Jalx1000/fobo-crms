<?php

namespace Webkul\Admin\DataGrids\Activity;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Traits\ProvideDropdownOptions;
use Webkul\DataGrid\DataGrid;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Repositories\UserRepository;

class ActivityDataGrid extends DataGrid
{
    use ProvideDropdownOptions;

    /**
     * Page sizes offered in the list view.
     *
     * Adds the large sizes on top of the framework defaults: the clinic reviews
     * a whole day or week of appointments at once, and a 50-row ceiling forced
     * constant paging. They are opt-in — nothing loads 1000 rows unless the
     * size is picked from the dropdown.
     */
    protected $perPageOptions = [10, 20, 30, 40, 50, 100, 500, 1000];

    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('activities')
            ->select(
                'activities.id',
                'activities.title',
                'activities.type',
                'activities.comment',
                'activities.is_done',
                'activities.schedule_from',
                'activities.schedule_to',
                'activities.user_id',
                'activities.created_at',
                'activities.updated_at',
                'leads.id as lead_id',
                'leads.title as lead_title',
                'leads.lead_pipeline_id',
                'users.id as created_by_id',
                'users.name as created_by',
            )
            ->leftJoin('activity_participants', 'activities.id', '=', 'activity_participants.activity_id')
            ->leftJoin('lead_activities', 'activities.id', '=', 'lead_activities.activity_id')
            ->leftJoin('leads', 'lead_activities.lead_id', '=', 'leads.id')
            ->leftJoin('users', 'activities.user_id', '=', 'users.id')
            ->whereIn('activities.type', ['call', 'meeting', 'lunch'])
            ->where(function ($query) {
                if ($userIds = bouncer()->getAuthorizedUserIds()) {
                    $query->whereIn('activities.user_id', $userIds)
                        ->orWhereIn('activity_participants.user_id', $userIds);
                }
            })
            ->groupBy(
                'activities.id', 'activities.title', 'activities.type', 'activities.comment',
                'activities.is_done', 'activities.schedule_from', 'activities.schedule_to',
                'activities.user_id', 'activities.created_at', 'activities.updated_at',
                'leads.id', 'leads.title', 'leads.lead_pipeline_id',
                'users.id', 'users.name'
            );

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => trans('admin::app.activities.index.datagrid.id'),
            'type'       => 'integer',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'            => 'is_done',
            'label'            => trans('admin::app.activities.index.datagrid.is_done'),
            'type'             => 'string',
            'dropdown_options' => $this->getBooleanDropdownOptions('yes_no'),
            'searchable'       => false,
            'closure'          => fn ($row) => view('admin::activities.datagrid.is-done', compact('row'))->render(),
        ]);

        $this->addColumn([
            'index'      => 'title',
            'label'      => trans('admin::app.activities.index.datagrid.title'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'              => 'created_by_id',
            'label'              => trans('admin::app.activities.index.datagrid.created_by'),
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
            'closure'    => function ($row) {
                $route = urldecode(route('admin.settings.users.index', ['id[eq]' => $row->created_by_id]));

                return "<a class='text-brandColor hover:underline' href='".$route."'>".$row->created_by.'</a>';
            },
        ]);

        $this->addColumn([
            'index'   => 'comment',
            'label'   => trans('admin::app.activities.index.datagrid.comment'),
            'type'    => 'string',
        ]);

        $this->addColumn([
            'index'              => 'lead_title',
            'label'              => trans('admin::app.activities.index.datagrid.lead'),
            'type'               => 'string',
            'searchable'         => true,
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'searchable_dropdown',
            'filterable_options' => [
                'repository' => LeadRepository::class,
                'column'     => [
                    'label' => 'title',
                    'value' => 'title',
                ],
            ],
            'closure'    => function ($row) {
                if ($row->lead_title == null) {
                    return "<span class='text-gray-800 dark:text-gray-300'>N/A</span>";
                }

                $route = urldecode(route('admin.leads.view', $row->lead_id));

                return "<a class='text-brandColor hover:underline' target='_blank' href='".$route."'>".$row->lead_title.'</a>';
            },
        ]);

        $this->addColumn([
            'index'      => 'type',
            'label'      => trans('admin::app.activities.index.datagrid.type'),
            'type'       => 'string',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => true,
            'closure'    => fn ($row) => trans('admin::app.activities.index.datagrid.'.$row->type),
        ]);

        $this->addColumn([
            'index'      => 'schedule_from',
            'label'      => trans('admin::app.activities.index.datagrid.schedule_from'),
            'type'       => 'date',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatDate($row->schedule_from),
        ]);

        $this->addColumn([
            'index'      => 'schedule_to',
            'label'      => trans('admin::app.activities.index.datagrid.schedule_to'),
            'type'       => 'date',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatDate($row->schedule_to),
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => trans('admin::app.activities.index.datagrid.created_at'),
            'type'       => 'date',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatDate($row->created_at),
        ]);

        $this->addFilter('id', 'activities.id');
        $this->addFilter('title', 'activities.title');
        $this->addFilter('schedule_from', 'activities.schedule_from');
        $this->addFilter('schedule_to', 'activities.schedule_to');
        $this->addFilter('created_by', 'users.name');
        $this->addFilter('created_by_id', 'users.name');
        $this->addFilter('created_at', 'activities.created_at');
        $this->addFilter('lead_title', 'leads.title');
    }

    /**
     * Override de sorting para usar el nombre calificado (activities.id, etc.)
     * y evitar "Column 'id' is ambiguous" con los JOINs activos.
     */
    protected function processRequestedSorting($requestedSort)
    {
        $requestedColumn = $requestedSort['column'] ?? $this->sortColumn ?? 'activities.id';

        $qualifiedColumn = collect($this->columns)
            ->first(fn ($col) => $col->getIndex() === $requestedColumn)
            ?->getColumnName() ?? $requestedColumn;

        return $this->queryBuilder->orderBy(
            $qualifiedColumn,
            $requestedSort['order'] ?? $this->sortOrder
        );
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('activities.edit')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.activities.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.activities.edit', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('activities.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.activities.index.datagrid.update'),
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.activities.delete', $row->id),
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
            'title'  => trans('admin::app.activities.index.datagrid.mass-delete'),
            'method' => 'POST',
            'url'    => route('admin.activities.mass_delete'),
        ]);

        $this->addMassAction([
            'title'   => trans('admin::app.activities.index.datagrid.mass-update'),
            'url'     => route('admin.activities.mass_update'),
            'method'  => 'POST',
            'options' => [
                [
                    'label' => trans('admin::app.activities.index.datagrid.done'),
                    'value' => 1,
                ], [
                    'label' => trans('admin::app.activities.index.datagrid.not-done'),
                    'value' => 0,
                ],
            ],
        ]);
    }
}
