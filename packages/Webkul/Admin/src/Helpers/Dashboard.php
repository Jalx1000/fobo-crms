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
            // Inmuebles, no productos: los mismos totales que las tortas de
            // abajo (fila «Total» de cada tabla). Las claves se conservan para
            // no tocar la plantilla ni la exportación.
            'total_services'        => $this->inmueblesProgress(false),
            'total_products_sold'   => $this->inmueblesProgress(true),
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

    public function getTopSellingProductsByQuantity(): Collection
    {
        return $this->productReporting->getTopSellingProductsByQuantity(5);
    }

    /**
     * Returns most sold products (Won/Ganado stage, i.e. delivered) statistics.
     */
    public function getTopSellingProductsByQuantitySold(): Collection
    {
        return $this->productReporting->getTopSellingProductsByQuantitySold(5);
    }

    /**
     * Inmuebles más consultados en el período.
     *
     * Reemplaza a «Productos más solicitados»: en una inmobiliaria lo que se
     * ofrece son inmuebles, y vivían en otra tabla (`inmueble_lead`), así que
     * la tarjeta de productos mostraba el conteo de algo que nadie carga.
     *
     * El helper se resuelve con `app()` y no por el constructor para no atar el
     * paquete Admin al de Inmobiliaria: Admin es la base, Inmobiliaria es la
     * extensión, y la dependencia al revés rompería una instancia sin ella.
     */
    public function getInmueblesMasSolicitados(): array
    {
        return $this->inmuebleReporting()->getTabla();
    }

    /**
     * Inmuebles efectivamente cerrados, con las mismas etapas que el resto del
     * tablero.
     */
    public function getInmueblesCerrados(): array
    {
        return $this->inmuebleReporting()->getTabla(soloCerrados: true);
    }

    /**
     * Consultas a inmuebles del período contra el anterior, con el mismo
     * formato que el resto de las tarjetas (previous/current/progress).
     */
    protected function inmueblesProgress(bool $soloCerrados): array
    {
        $reporting = $this->inmuebleReporting();

        $previous = $reporting->totalConsultas($this->leadReporting->getLastStartDate(), $this->leadReporting->getLastEndDate(), $soloCerrados);
        $current = $reporting->totalConsultas($this->leadReporting->getStartDate(), $this->leadReporting->getEndDate(), $soloCerrados);

        return [
            'previous' => $previous,
            'current'  => $current,
            'progress' => $this->leadReporting->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Estado de la cartera: por estado y cuántos sin fotos.
     *
     * @return array<string, mixed>
     */
    public function getSaludDeCarteraStats(): array
    {
        return $this->inmuebleReporting()->getSaludDeCartera();
    }

    /**
     * Avance de las metas del período.
     *
     * Toma el período del mes de la fecha de inicio del filtro, no del día de
     * hoy: si el gerente mira septiembre, quiere las metas de septiembre.
     *
     * @return array<string, mixed>
     */
    public function getMetasStats(): array
    {
        return app(\Webkul\Admin\Helpers\Reporting\Metas::class)
            ->getAvance($this->leadReporting->getStartDate()->format('Y-m'));
    }

    /**
     * Estado operativo de WhatsApp: lo que requiere atención ahora mismo.
     *
     * No toma el filtro de fechas del tablero a propósito. A un gerente no le
     * sirve «cuántas quedaron sin responder en agosto»; le sirve cuántas hay
     * sin responder en este momento, que es sobre lo que puede actuar.
     *
     * @return array<string, mixed>
     */
    public function getOperacionWhatsappStats(): array
    {
        return app(\Webkul\Whatsapp\Helpers\Reporting\Operacion::class)->getResumen();
    }

    /**
     * El reporte de inmuebles, con el mismo rango de fechas que el resto.
     */
    protected function inmuebleReporting(): \Webkul\Inmobiliaria\Helpers\Reporting\Inmueble
    {
        return app(\Webkul\Inmobiliaria\Helpers\Reporting\Inmueble::class)
            ->setStartDate($this->leadReporting->getStartDate())
            ->setEndDate($this->leadReporting->getEndDate());
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

    public function getNotAttendedLeadsByCityStats(): array
    {
        return $this->leadReporting->getNotAttendedLeadsCountByPipelines();
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
            'valor-ventas'       => $this->leadReporting->getEvolution('valor-ventas'),
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
