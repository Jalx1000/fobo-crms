<?php

namespace Webkul\Admin\DataGrids\Product;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class ProductDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $tablePrefix = DB::getTablePrefix();

        $queryBuilder = DB::table('products')
            ->leftJoin('attribute_values as type_service_2_av', function ($join) {
                $join->on('products.id', '=', 'type_service_2_av.entity_id')
                    ->where('type_service_2_av.entity_type', '=', DB::raw("'products'"))
                    ->where('type_service_2_av.attribute_id', '=', function ($query) {
                        $query->select('id')->from('attributes')->where('code', '=', DB::raw("'type_service_2'"))->limit(1);
                    });
            })
            ->leftJoin('attribute_options as type_service_2_options', 'type_service_2_av.integer_value', '=', 'type_service_2_options.id')->select(
                'products.id',
                'products.sku',
                'products.name as name',
                DB::raw('COALESCE('.$tablePrefix.'type_service_2_options.name, '.$tablePrefix.'type_service_2_av.text_value) as type_service_2')
            )
            ->groupBy('products.id');

        $this->addFilter('sku', 'products.sku');
        $this->addFilter('name', 'products.name');
        $this->addFilter('type_service_2', DB::raw('COALESCE('.$tablePrefix.'type_service_2_options.name, '.$tablePrefix.'type_service_2_av.text_value)'));

        return $queryBuilder;
    }

    /**
     * Add columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'sku',
            'label'      => trans('admin::app.products.index.datagrid.sku'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => false,
        ]);

        $this->addColumn([
            'index'      => 'name',
            'label'      => trans('admin::app.products.index.datagrid.name'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => false,
        ]);

        $this->addColumn([
            'index'      => 'type_service_2',
            'label'      => 'Tipo de servicio',
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => false,
        ]);
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('products.view')) {
            $this->addAction([
                'index'  => 'view',
                'icon'   => 'icon-eye',
                'title'  => trans('admin::app.products.index.datagrid.view'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.products.view', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('products.edit')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.products.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.products.edit', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('products.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.products.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.products.delete', $row->id),
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
            'title'  => trans('admin::app.products.index.datagrid.delete'),
            'method' => 'POST',
            'url'    => route('admin.products.mass_delete'),
        ]);
    }
}
