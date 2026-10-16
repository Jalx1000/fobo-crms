<?php

namespace Webkul\Admin\Helpers\Reporting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;

class Person extends AbstractReporting
{
    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct(protected PersonRepository $personRepository)
    {
        parent::__construct();
    }

    /**
     * Retrieves total persons and their progress.
     */
    public function getTotalPersonsProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalPersons($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalPersons($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total persons by date
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalPersons($startDate, $endDate): int
    {
        $pipelineId = is_numeric(request('pipeline_id')) ? (int) request('pipeline_id') : null;

        // Este KPI cuenta CONTACTOS (personas registradas por persons.created_at) y debe
        // cuadrar 1:1 con el módulo de Contactos. Por eso la ciudad se filtra igual que
        // la lista: por el atributo del contacto (NO por el pipeline del lead) y sin JOIN
        // a leads, para no perder a las personas que aún no tienen ningún lead.
        $query = $this->personRepository
            ->resetModel()
            ->whereBetween('persons.created_at', [$startDate, $endDate]);

        // Un ejecutivo cuenta solo sus contactos («Asesor Asignado» = él), igual
        // que la lista de Contactos con permiso individual (spec tablero-por-asesor).
        // Administrador y Supervisores: los del ejecutivo elegido en el tablero.
        \Webkul\Admin\Helpers\FiltroEjecutivo::limitarTablero($query, 'persons.user_id');

        // La ciudad del contacto, con la misma regla que la lista de Contactos
        // (atributo `ciudad`, por nombre; «Sin ciudad» = sin ciudad).
        if ($pipelineId) {
            app(\Webkul\Admin\Services\CiudadCliente::class)->filtrarPorPipeline($query, $pipelineId);
        }

        return $query->distinct('persons.id')->count('persons.id');
    }

    /**
     * Gets top customers by revenue.
     *
     * @param  int  $limit
     */
    public function getTopCustomersByRevenue($limit = null): Collection
    {
        $tablePrefix = DB::getTablePrefix();

        $items = $this->personRepository
            ->resetModel()
            // `leads` se une una sola vez (abajo): ver Product::getTopSellingProductsByRevenue.
            ->when(request('user_id'), fn ($q) => $q->where('leads.user_id', request('user_id')))
            ->when(request('organization_id'), function ($q) {
                $q->where('persons.organization_id', request('organization_id'));
            })
            ->leftJoin('leads', 'persons.id', '=', 'leads.person_id')
            ->select('*', 'persons.id as id')
            ->addSelect(DB::raw('SUM('.$tablePrefix.'leads.lead_value) as revenue'))
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->having(DB::raw('SUM('.$tablePrefix.'leads.lead_value)'), '>', 0)
            ->groupBy('person_id')
            ->orderBy('revenue', 'DESC')
            ->limit($limit)
            ->get();

        $items = $items->map(function ($item) {
            return [
                'id'                => $item->id,
                'name'              => $item->name,
                'emails'            => $item->emails,
                'contact_numbers'   => $item->contact_numbers,
                'revenue'           => $item->revenue,
                'formatted_revenue' => core()->formatBasePrice($item->revenue),
            ];
        });

        return $items;
    }
}
