<?php

namespace Webkul\Admin\DataGrids\Settings;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class SalesGoalDataGrid extends DataGrid
{
    protected $sortColumn = 'periodo';

    protected $sortOrder = 'desc';

    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('sales_goals')
            ->leftJoin('users', 'sales_goals.user_id', '=', 'users.id')
            ->select(
                'sales_goals.id',
                'sales_goals.periodo',
                'sales_goals.metrica',
                'sales_goals.objetivo',
                'sales_goals.moneda',
                'sales_goals.user_id',
                'users.name as asesor'
            );

        $this->addFilter('periodo', 'sales_goals.periodo');
        $this->addFilter('metrica', 'sales_goals.metrica');
        $this->addFilter('asesor', 'users.name');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'periodo',
            'label'      => 'Período',
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'      => 'asesor',
            'label'      => 'Aplica a',
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
            // Sin asesor es la meta del equipo, y se marca para que no se lea
            // como un dato faltante.
            'closure'    => fn ($row) => $row->user_id
                ? e($row->asesor)
                : '<span class="label-info">Equipo</span>',
        ]);

        $this->addColumn([
            'index'              => 'metrica',
            'label'              => 'Métrica',
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => collect(config('reporting.goals.metricas', []))
                ->map(fn ($def, $key) => ['label' => $def['label'], 'value' => $key])
                ->values()
                ->all(),
            'closure' => fn ($row) => config("reporting.goals.metricas.{$row->metrica}.label", $row->metrica),
        ]);

        $this->addColumn([
            'index'    => 'objetivo',
            'label'    => 'Objetivo',
            'type'     => 'string',
            'sortable' => true,
            'closure'  => fn ($row) => trim(($row->moneda ?? '').' '.number_format((float) $row->objetivo, 0, ',', '.')),
        ]);
    }

    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('settings.lead.metas')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => 'Editar',
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.settings.metas.edit', $row->id),
            ]);

            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => 'Eliminar',
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.settings.metas.delete', $row->id),
            ]);
        }
    }
}
