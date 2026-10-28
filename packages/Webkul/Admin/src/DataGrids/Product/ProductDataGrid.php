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
        // La especialidad (línea) es la columna real products.specialty_id. Antes
        // se resolvía contra attribute_values/attribute_options del atributo EAV
        // "type_service_2", que para un lookup guarda el id de la especialidad y
        // terminaba matcheando una opción ajena (p. ej. "Membresía Odontoking").
        $queryBuilder = DB::table('products')
            ->leftJoin('specialties', 'products.specialty_id', '=', 'specialties.id')
            ->select(
                'products.id',
                'products.sku',
                'products.name as name',
                'specialties.name as specialty'
            );

        $this->addFilter('sku', 'products.sku');
        $this->addFilter('name', 'products.name');
        $this->addFilter('specialty', 'specialties.name');

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
            'index'      => 'specialty',
            'label'      => 'Especialidad / Línea',
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
