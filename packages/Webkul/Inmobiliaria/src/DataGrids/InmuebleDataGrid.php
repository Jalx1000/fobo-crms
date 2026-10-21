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
                'inmuebles.dormitorios',
                'inmuebles.banos',
                'inmuebles.parqueos',
                'users.name as asesor'
            )
            /*
            | La portada, en una subconsulta correlacionada y no con un join.
            |
            | Un join contra inmueble_medias multiplicaría la fila por cada foto
            | y habría que agrupar todo el resto. Replica el orden de
            | Inmueble::portada(): la marcada como portada primero, si no la
            | primera foto por orden.
            */
            ->selectSub(function ($query) {
                $query->from('inmueble_medias')
                    ->select('id')
                    ->whereColumn('inmueble_medias.inmueble_id', 'inmuebles.id')
                    ->where('tipo', 'foto')
                    ->orderByDesc('es_portada')
                    ->orderBy('orden')
                    ->orderBy('id')
                    ->limit(1);
            }, 'portada_id');

        $this->addFilter('id', 'inmuebles.id');
        $this->addFilter('codigo', 'inmuebles.codigo');
        $this->addFilter('titulo', 'inmuebles.titulo');
        $this->addFilter('operacion', 'inmuebles.operacion');
        $this->addFilter('tipo', 'inmuebles.tipo');
        $this->addFilter('estado', 'inmuebles.estado');
        $this->addFilter('zona', 'inmuebles.zona');
        $this->addFilter('asesor', 'users.name');
        $this->addFilter('dormitorios', 'inmuebles.dormitorios');
        $this->addFilter('banos', 'inmuebles.banos');
        $this->addFilter('parqueos', 'inmuebles.parqueos');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        /*
        | La portada va primera porque es por donde se escanea la cartera: el
        | asesor reconoce «el dúplex de la esquina», no «C21-0043».
        |
        | El hueco punteado cuando no hay foto no es decorativo: un inmueble sin
        | fotos es trabajo pendiente que hoy nadie detecta hasta que un cliente
        | las pide.
        */
        $this->addColumn([
            'index'      => 'portada_id',
            'label'      => 'Foto',
            'type'       => 'string',
            'sortable'   => false,
            'searchable' => false,
            'filterable' => false,
            'closure'    => function ($row) {
                if (! $row->portada_id) {
                    return '<div class="flex h-12 w-16 items-center justify-center rounded border border-dashed '
                        .'border-gray-300 text-[10px] text-gray-400 dark:border-gray-700" '
                        .'title="Sin fotos cargadas" aria-label="Sin fotos cargadas">Sin foto</div>';
                }

                return '<img src="'.route('admin.inmuebles.medias.archivo', $row->portada_id).'" '
                    .'alt="Portada de '.e($row->codigo).'" loading="lazy" decoding="async" '
                    .'class="h-12 w-16 rounded object-cover" />';
            },
        ]);

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

        /*
        | Dormitorios, baños y parqueos.
        |
        | Los campos existían en la tabla desde el principio pero no se veían ni
        | se podían filtrar, así que la pregunta más común del cliente —«¿tenés
        | algo de 2 o 3 dormitorios en Equipetrol?»— se contestaba abriendo
        | inmuebles de a uno con el cliente esperando en el chat.
        |
        | El filtro `integer` admite varios valores con OR, que es exactamente
        | «2 o 3». El dropdown llega hasta 5 porque arriba de eso la cartera es
        | marginal y se busca por texto.
        */
        foreach ([
            'dormitorios' => 'Dorm.',
            'banos'       => 'Baños',
            'parqueos'    => 'Parq.',
        ] as $campo => $etiqueta) {
            $this->addColumn([
                'index'              => $campo,
                'label'              => $etiqueta,
                'type'               => 'integer',
                'sortable'           => true,
                'filterable'         => true,
                'filterable_type'    => 'dropdown',
                'filterable_options' => $this->opciones([
                    '0' => 'Sin dato',
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                    '5' => '5 o más',
                ]),
                // Un guion y no un 0: «0 baños» es un dato cargado mal, «—» es
                // un dato que falta. Para el asesor no son lo mismo.
                'closure' => fn ($row) => ($row->{$campo} ?? null) ? (string) $row->{$campo} : '—',
            ]);
        }

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
