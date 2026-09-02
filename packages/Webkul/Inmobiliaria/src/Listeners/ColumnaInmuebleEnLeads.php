<?php

namespace Webkul\Inmobiliaria\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * Agrega la columna "Inmueble" al listado de leads.
 *
 * Se engancha a los eventos que el DataGrid del core ya dispara, para responder
 * de un vistazo "¿qué leads hay por el galpón de Av. Banzer?" sin editar
 * `LeadDataGrid`, que es del core.
 */
class ColumnaInmuebleEnLeads
{
    /**
     * Suma los códigos al SELECT, agrupados por lead.
     *
     * Un lead puede tener varios inmuebles, así que se concatenan en vez de
     * multiplicar las filas del listado con un JOIN directo. El DataGrid del
     * core ya agrupa por `leads.id`, que es lo que hace válido el GROUP_CONCAT.
     *
     * El evento entrega el propio DataGrid —no el query— y para este punto la
     * consulta ya está construida, así que se toma de ahí.
     */
    public function extenderConsulta(DataGrid $datagrid): void
    {
        $query = $datagrid->getQueryBuilder();

        $prefijo = DB::getTablePrefix();

        $query->leftJoin('inmueble_lead', 'inmueble_lead.lead_id', '=', 'leads.id')
            ->leftJoin('inmuebles', 'inmuebles.id', '=', 'inmueble_lead.inmueble_id')
            ->addSelect(DB::raw("GROUP_CONCAT(DISTINCT {$prefijo}inmuebles.codigo ORDER BY {$prefijo}inmuebles.codigo SEPARATOR ', ') as inmuebles_codigos"));

        $datagrid->addFilter('inmuebles_codigos', 'inmuebles.codigo');
    }

    public function agregarColumna(DataGrid $datagrid): void
    {
        $datagrid->addColumn([
            'index'      => 'inmuebles_codigos',
            'label'      => 'Inmueble',
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => false,
            'closure'    => fn ($fila) => $fila->inmuebles_codigos ?: '—',
        ]);
    }
}
