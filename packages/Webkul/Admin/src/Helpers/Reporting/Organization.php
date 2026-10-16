<?php

namespace Webkul\Admin\Helpers\Reporting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\OrganizationRepository;

class Organization extends AbstractReporting
{
    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct(protected OrganizationRepository $organizationRepository)
    {
        parent::__construct();
    }

    /**
     * Retrieves total organizations and their progress.
     */
    public function getTotalOrganizationsProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalOrganizations($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalOrganizations($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total organizations by date
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalOrganizations($startDate, $endDate): int
    {
        // Un ejecutivo cuenta solo las organizaciones a su nombre (spec tablero-por-asesor).
        // Administrador y Supervisores: las del ejecutivo elegido en el tablero.
        return \Webkul\Admin\Helpers\FiltroEjecutivo::limitarTablero(
            $this->organizationRepository->resetModel()->whereBetween('organizations.created_at', [$startDate, $endDate]),
            'organizations.user_id'
        )->count();
    }

    /**
     * Gets top customers by revenue.
     *
     * @param  int  $limit
     */
    public function getTopOrganizationsByRevenue($limit = null): Collection
    {
        $tablePrefix = DB::getTablePrefix();

        $items = $this->organizationRepository
            ->resetModel()
            ->leftJoin('persons', 'organizations.id', '=', 'persons.organization_id')
            ->leftJoin('leads', 'persons.id', '=', 'leads.person_id')
            ->select('*', 'persons.id as id')
            ->addSelect(DB::raw('SUM('.$tablePrefix.'leads.lead_value) as revenue'))
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->having(DB::raw('SUM('.$tablePrefix.'leads.lead_value)'), '>', 0)
            ->groupBy('organization_id')
            ->orderBy('revenue', 'DESC')
            ->limit($limit)
            ->get();

        $items = $items->map(function ($item) {
            return [
                'id'                => $item->id,
                'name'              => $item->name,
                'revenue'           => $item->revenue,
                'formatted_revenue' => core()->formatBasePrice($item->revenue),
            ];
        });

        return $items;
    }
}
