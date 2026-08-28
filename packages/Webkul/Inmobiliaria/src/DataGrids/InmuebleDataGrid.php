<?php

namespace Webkul\Inmobiliaria\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class InmuebleDataGrid extends DataGrid
{
    protected $sortColumn = 'id';

    protected $sortOrder = 'desc';

    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('inmuebles')
            ->leftJoin('users', 'inmuebles.user_id', '=', 'users.id')
            ->whereNull('inmuebles.deleted_at')
            ->select(
                'inmuebles.id',
                'inmuebles.codigo',
                'inmuebles.titulo',
                'inmuebles.operacion',
                'inmuebles.tipo',
                'inmuebles.estado',
                'inmuebles.zona',
                'inmuebles.precio',
                'inmuebles.moneda',
                'users.name as asesor'
            );

        $this->addFilter('id', 'inmuebles.id');
        $this->addFilter('codigo', 'inmuebles.codigo');
        $this->addFilter('titulo', 'inmuebles.titulo');
        $this->addFilter('operacion', 'inmuebles.operacion');
        $this->addFilter('tipo', 'inmuebles.tipo');
        $this->addFilter('estado', 'inmuebles.estado');
        $this->addFilter('zona', 'inmuebles.zona');
        $this->addFilter('asesor', 'users.name');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'codigo',
            'label'      => 'Código',
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'      => 'titulo',
            'label'      => 'Título',
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'              => 'estado',
            'label'              => 'Estado',
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => $this->opciones(
                array_combine(
                    config('inmobiliaria.inmuebles.estados'),
                    array_map('ucfirst', config('inmobiliaria.inmuebles.estados'))
                )
            ),
            // El estado decide qué ve el agente de IA, así que se marca:
            // 'disponible' en verde es lo único que Sofía puede ofrecer.
            'closure' => fn ($row) => $row->estado === 'disponible'
                ? '<span class="label-active">Disponible</span>'
                : '<span class="label-pending">'.ucfirst($row->estado).'</span>',
        ]);

        $this->addColumn([
            'index'              => 'operacion',
            'label'              => 'Operación',
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => $this->opciones(
                array_combine(
                    config('inmobiliaria.inmuebles.operaciones'),
                    array_map('ucfirst', config('inmobiliaria.inmuebles.operaciones'))
                )
            ),
            'closure' => fn ($row) => ucfirst($row->operacion),
        ]);

        $this->addColumn([
            'index'              => 'tipo',
            'label'              => 'Tipo',
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => $this->opciones(config('inmobiliaria.inmuebles.tipos')),
            'closure'            => fn ($row) => config("inmobiliaria.inmuebles.tipos.{$row->tipo}", $row->tipo),
        ]);

        $this->addColumn([
            'index'      => 'zona',
            'label'      => 'Zona',
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'    => 'precio',
            'label'    => 'Precio',
            'type'     => 'string',
            'sortable' => true,
            // Se muestra en la moneda guardada, nunca convertido (D4). Por eso
            // no se usa el formateador de precio del core, que asume una sola
            // moneda de instancia.
            'closure'  => fn ($row) => $row->moneda.' '.number_format((float) $row->precio, 0, ',', '.'),
        ]);

        $this->addColumn([
            'index'      => 'asesor',
            'label'      => 'Asesor titular',
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);
    }

    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('inmuebles.edit')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => 'Editar',
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.inmuebles.edit', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('inmuebles.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => 'Eliminar',
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.inmuebles.destroy', $row->id),
            ]);
        }
    }

    /**
     * Formato que espera el filtro dropdown del DataGrid.
     *
     * @param  array<string, string>  $valores
     * @return array<int, array<string, string>>
     */
    protected function opciones(array $valores): array
    {
        return collect($valores)
            ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }
}
