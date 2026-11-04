<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Admin\Helpers\Reporting\Activity;
use Webkul\Admin\Helpers\Reporting\Lead;
use Webkul\Admin\Helpers\Reporting\Organization;
use Webkul\Admin\Helpers\Reporting\Person;
use Webkul\Admin\Helpers\Reporting\Product;
use Webkul\Admin\Helpers\Reporting\Quote;

class Dashboard
{
    /**
     * Create a controller instance.
     *
     * @return void
     */
    public function __construct(
        protected Lead $leadReporting,
        protected Activity $activityReporting,
        protected Product $productReporting,
        protected Person $personReporting,
        protected Organization $organizationReporting,
        protected Quote $quoteReporting,
    ) {}

    /**
     * Returns the overall revenue statistics.
     */
    public function getRevenueStats(): array
    {
        return [
            'total_won_revenue'  => $this->leadReporting->getTotalWonLeadValueProgress(),
            'total_lost_revenue' => $this->leadReporting->getTotalLostLeadValueProgress(),
            'ventas_count'       => $this->leadReporting->getTotalVentasCountProgress(),
        ];
    }

    /**
     * Returns the overall statistics.
     */
    public function getOverAllStats(): array
    {
        return [
            'total_leads'           => $this->leadReporting->getTotalLeadsProgress(),
            'average_lead_value'    => $this->leadReporting->getAverageLeadValueProgress(),
            'average_leads_per_day' => $this->leadReporting->getAverageLeadsPerDayProgress(),
            'total_services'        => $this->productReporting->getTotalServicesProgress(),
            'total_products_sold'   => $this->productReporting->getTotalProductsSoldProgress(),
            'total_quotations'      => $this->quoteReporting->getTotalQuotesProgress(),
            'total_persons'         => $this->personReporting->getTotalPersonsProgress(),
            'total_organizations'   => $this->organizationReporting->getTotalOrganizationsProgress(),
        ];
    }

    /**
     * Returns leads statistics.
     */
    public function getTotalLeadsStats(): array
    {
        return [
            'all'  => [
                'over_time' => $this->leadReporting->getTotalLeadsOverTime(),
            ],

            'prospecto' => [
                'over_time' => $this->leadReporting->getTotalProspectoLeadsOverTime(),
            ],

            'confirmed' => [
                'over_time' => $this->leadReporting->getTotalConfirmedLeadsOverTime(),
            ],

            'won'  => [
                'over_time' => $this->leadReporting->getTotalWonLeadsOverTime(),
            ],
            'lost' => [
                'over_time' => $this->leadReporting->getTotalLostLeadsOverTime(),
            ],
        ];
    }

    /**
     * Returns total services statistics.
     */
    public function getTotalServicesStats(): array
    {
        return [
            'over_time' => $this->productReporting->getTotalProductsSoldOverTime(),
        ];
    }

    /**
     * Returns leads revenue statistics by sources.
     */
    public function getLeadsStatsBySources(): mixed
    {
        return $this->leadReporting->getTotalWonLeadValueBySources();
    }

    /**
     * Returns leads revenue statistics by types.
     */
    public function getLeadsStatsByTypes(): mixed
    {
        return $this->leadReporting->getTotalWonLeadValueByTypes();
    }

    /**
     * Returns open leads statistics by states.
     */
    public function getOpenLeadsByStates(): mixed
    {
        return $this->leadReporting->getOpenLeadsByStates();
    }

    /**
     * Returns open leads statistics by states with fixed pipeline order and Won/Lost at the end.
     */
    public function getOpenLeadsByStatesFixed(): mixed
    {
        return $this->leadReporting->getOpenLeadsByStatesFixed();
    }

    /**
     * Returns top selling products statistics.
     */
    public function getTopSellingProducts(): Collection
    {
        return $this->productReporting->getTopSellingProductsByRevenue(5);
    }

    /**
     * Sin límite: la torta tiene que sumar lo mismo que el KPI «Total productos
     * solicitados» (spec tablero-consistente, D3). El catálogo es chico.
     */
    public function getTopSellingProductsByQuantity(): Collection
    {
        return $this->productReporting->getTopSellingProductsByQuantity();
    }

    /**
     * Returns most sold products (Won/Ganado stage, i.e. delivered) statistics.
     */
    public function getTopSellingProductsByQuantitySold(): Collection
    {
        // Sin límite: la torta suma lo mismo que el KPI de vendidos (D5).
        return $this->productReporting->getTopSellingProductsByQuantitySold();
    }

    /**
     * Returns top selling products statistics.
     */
    public function getTopPersons(): Collection
    {
        return $this->personReporting->getTopCustomersByRevenue(5);
    }

    public function getVendedoresStats(): Collection
    {
        return $this->leadReporting->getVendedoresStats(5);
    }

    public function getVentasOverTimeStats(): array
    {
        return $this->leadReporting->getVentasOverTimeByUser();
    }

    public function getVentasByUsersStats(): array
    {
        return $this->leadReporting->getVentasCountByUsers();
    }

    public function getLeadsByUsersStats(): array
    {
        return $this->leadReporting->getLeadsCountByUsers();
    }

    public function getLeadsAttentionByUsersStats(): array
    {
        return $this->leadReporting->getLeadsAttentionCountByUsers();
    }

    public function getResponseTimeByUsersStats(): array
    {
        return $this->leadReporting->getAverageResponseTimeByUsers();
    }

    public function getResponseTimeDistributionStats(): array
    {
        return $this->leadReporting->getResponseTimeDistribution();
    }

    public function getVentasByBranchesStats(): array
    {
        return $this->leadReporting->getVentasCountByBranches();
    }

    public function getLeadsByBranchesStats(): array
    {
        return $this->leadReporting->getLeadsCountByBranches();
    }

    public function getVentasByPipelinesStats(): array
    {
        return $this->leadReporting->getVentasCountByPipelines();
    }

    public function getLeadsByPipelinesStats(): array
    {
        return $this->leadReporting->getLeadsCountByPipelines();
    }

    public function getEntregadosSinCotizacionStats(): array
    {
        return $this->leadReporting->getEntregadosSinCotizacion();
    }

    public function getPedidosByCityStats(): array
    {
        return $this->leadReporting->getPedidosCountByPipelines();
    }

    /**
     * Returns the evolution statistics for all metrics: each one carries the
     * current period series plus the previous period's daily average as baseline.
     */
    public function getEvolutionStats(): array
    {
        $units = $this->productReporting->getUnitsSoldEvolution();

        return [
            'ventas'             => $this->leadReporting->getEvolution('ventas'),
            // Valor de ventas = total de las cotizaciones de entregados (D4), no leads.lead_value.
            'valor-ventas'       => $this->leadReporting->buildEvolutionPayload($units['current'], $units['previous'], 'total', 'currency', $units['period']),
            'pedidos-creados'    => $this->leadReporting->getEvolution('pedidos-creados'),
            'productos-vendidos' => $this->leadReporting->buildEvolutionPayload($units['current'], $units['previous'], 'count', 'number', $units['period']),
        ];
    }

    /**
     * Returns total leads by pipeline stages counts.
     */
    public function getTotalLeadsByStages(): array
    {
        return $this->leadReporting->getTotalLeadsByStages();
    }

    /**
     * Returns total leads by stages over time buckets.
     */
    public function getTotalLeadsByStagesOverTime(): array
    {
        return $this->leadReporting->getTotalLeadsByStagesOverTime();
    }

    /**
     * Get the start date.
     *
     * @return \Carbon\Carbon
     */
    public function getStartDate(): Carbon
    {
        return $this->leadReporting->getStartDate();
    }

    /**
     * Get the end date.
     *
     * @return \Carbon\Carbon
     */
    public function getEndDate(): Carbon
    {
        return $this->leadReporting->getEndDate();
    }

    /**
     * Returns date range
     */
    public function getDateRange(): string
    {
        return $this->getStartDate()->format('d M').' - '.$this->getEndDate()->format('d M');
    }
}
