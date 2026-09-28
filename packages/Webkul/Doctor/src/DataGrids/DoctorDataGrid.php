<?php

namespace Webkul\Doctor\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\DataGrid\DataGrid;

class DoctorDataGrid extends DataGrid
{
    protected $sortColumn = 'id';

    protected $itemsPerPage = 20;

    public function prepareQueryBuilder(): Builder
    {
        $hasSpecialtyTables = Schema::hasTable('doctor_specialty') && Schema::hasTable('specialties');
        $hasIsActiveColumn = Schema::hasColumn('doctors', 'is_active');
        $hasEmailColumn = Schema::hasColumn('doctors', 'email');
        $hasUniqueIdColumn = Schema::hasColumn('doctors', 'unique_id');
        $prefix = DB::connection()->getTablePrefix();

        $queryBuilder = DB::table('doctors')
            ->addSelect(
                'doctors.id',
                'doctors.name',
                'doctors.created_at',
                $hasEmailColumn ? 'doctors.email' : DB::raw('NULL as email'),
                $hasUniqueIdColumn ? 'doctors.unique_id' : DB::raw('NULL as unique_id'),
                $hasIsActiveColumn ? 'doctors.is_active' : DB::raw('NULL as is_active'),
                $hasSpecialtyTables
                    ? DB::raw('GROUP_CONCAT(DISTINCT '.$prefix.'specialties.name SEPARATOR ", ") as specialties')
                    : DB::raw('"" as specialties')
            );

        if ($hasSpecialtyTables) {
            $queryBuilder
                ->leftJoin('doctor_specialty', 'doctor_specialty.doctor_id', '=', 'doctors.id')
                ->leftJoin('specialties', 'specialties.id', '=', 'doctor_specialty.specialty_id');
        }

        $groupColumns = ['doctors.id', 'doctors.name', 'doctors.created_at'];
        if ($hasIsActiveColumn) {
            $groupColumns[] = 'doctors.is_active';
        }
        if ($hasEmailColumn) {
            $groupColumns[] = 'doctors.email';
        }
        if ($hasUniqueIdColumn) {
            $groupColumns[] = 'doctors.unique_id';
        }
        $queryBuilder->groupBy($groupColumns);

        $this->addFilter('id', 'doctors.id');
        $this->addFilter('name', 'doctors.name');
        $this->addFilter('email', 'doctors.email');
        $this->addFilter('unique_id', 'doctors.unique_id');
        $this->addFilter('created_at', 'doctors.created_at');
        if ($hasSpecialtyTables) {
            $this->addFilter('specialties', 'specialties.name');
        }
        if ($hasIsActiveColumn) {
            $this->addFilter('is_active', 'doctors.is_active');
        }

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        $hasSpecialtyTables = Schema::hasTable('doctor_specialty') && Schema::hasTable('specialties');
        $hasIsActiveColumn = Schema::hasColumn('doctors', 'is_active');

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
            'index'      => 'email',
            'label'      => 'Correo',
            'type'       => 'string',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'unique_id',
            'label'      => 'ID ShareMeData',
            'type'       => 'string',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => true,
        ]);

        $this->addColumn([
            'index'      => 'specialties',
            'label'      => 'Especialidad',
            'type'       => 'string',
            'filterable' => $hasSpecialtyTables,
            'sortable'   => $hasSpecialtyTables,
            'searchable' => $hasSpecialtyTables,
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => 'Creado',
            'type'       => 'date',
            'filterable' => true,
            'sortable'   => true,
            'searchable' => false,
        ]);

        $this->addColumn([
            'index'      => 'is_active',
            'label'      => 'Estado',
            'type'       => 'boolean',
            'filterable' => $hasIsActiveColumn,
            'sortable'   => $hasIsActiveColumn,
            'searchable' => false,
            'closure'    => function ($row) {
                if ($row->is_active === null) {
                    return '<span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-300">Sin dato</span>';
                }

                $isActive = (bool) $row->is_active;

                $class = $isActive
                    ? 'inline-flex items-center rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900 dark:text-green-300'
                    : 'inline-flex items-center rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900 dark:text-red-300';

                $label = $isActive ? 'Habilitado' : 'No Habilitado';

                return "<span class=\"{$class}\">{$label}</span>";
            },
        ]);
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        $this->addAction([
            'index'  => 'edit',
            'icon'   => 'icon-edit',
            'title'  => 'Editar',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.doctor.edit', $row->id),
        ]);

        $this->addAction([
            'index'  => 'delete',
            'icon'   => 'icon-delete',
            'title'  => 'Eliminar',
            'method' => 'DELETE',
            'url'    => fn ($row) => route('admin.doctor.delete', $row->id),
        ]);
    }
}
