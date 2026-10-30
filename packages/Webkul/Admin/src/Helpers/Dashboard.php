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
            'total_quotations'      => $this->quoteReporting->getTotalQuotesProgress(),
            'total_contacts'        => $this->personReporting->getTotalPersonsProgress(),
            'total_consultas'       => $this->leadReporting->getTotalConsultasProgress(),
            'total_organizations'   => $this->organizationReporting->getTotalOrganizationsProgress(),
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
     * Returns the services requested within the selected dashboard date range.
     */
    public function getServicesRequestedStats(): array
    {
        return $this->productReporting->getAllServicesRequested();
    }

    /**
     * Returns the evolution statistics (current vs previous period).
     */
    public function getEvolutionStats(): array
    {
        return $this->leadReporting->getLeadsEvolution();
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

    public function getLeadsByUsersStats(): array
    {
        return $this->leadReporting->getLeadsCountByUsers();
    }

    public function getResponseTimeByUsersStats(): array
    {
        return $this->leadReporting->getAverageResponseTimeByUsers();
    }

    public function getVentasByBranchesStats(): array
    {
        return $this->leadReporting->getVentasCountByBranches();
    }

    public function getLeadsByBranchesStats(): array
    {
        return $this->leadReporting->getLeadsCountByBranches();
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
