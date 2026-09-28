<?php

namespace Webkul\Doctor\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class SpecialtyDataGrid extends DataGrid
{
    protected $sortColumn = 'id';

    protected $itemsPerPage = 20;

    public function prepareQueryBuilder(): Builder
    {
        $prefix = DB::connection()->getTablePrefix();

        $queryBuilder = DB::table('specialties')
            ->addSelect(
                'specialties.id',
                'specialties.name',
                'specialties.description',
                'specialties.created_at',
            );

        $this->addFilter('id', 'specialties.id');
        $this->addFilter('name', 'specialties.name');
        $this->addFilter('description', 'specialties.description');
        $this->addFilter('created_at', 'specialties.created_at');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => 'ID',
            'type'       => 'integer',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'name',
            'label'      => 'Nombre',
            'type'       => 'string',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'description',
            'label'      => 'Descripción',
            'type'       => 'string',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => false,
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => 'Creado',
            'type'       => 'datetime',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => false,
        ]);
    }

    public function prepareActions(): void
    {
        $this->addAction([
            'title'  => 'Editar',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.specialties.edit', $row->id),
            'icon'   => 'icon-edit',
        ]);

        $this->addAction([
            'title'  => 'Eliminar',
            'method' => 'DELETE',
            'url'    => fn ($row) => route('admin.specialties.delete', $row->id),
            'icon'   => 'icon-delete',
        ]);
    }
}
