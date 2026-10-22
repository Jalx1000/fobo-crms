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

    public function getTopSellingProductsByQuantity(): array
    {
        return $this->tortaDeProductos($this->productReporting->getTopSellingProductsByQuantity(), false);
    }

    /**
     * Returns most sold products (Won/Ganado stage, i.e. delivered) statistics.
     */
    public function getTopSellingProductsByQuantitySold(): array
    {
        return $this->tortaDeProductos($this->productReporting->getTopSellingProductsByQuantitySold(), true);
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

    /**
     * Detalle paginado de "tiempo en responder", una fila por contacto.
     */
    public function getResponseTimeDetailStats(): array
    {
        return $this->leadReporting->getResponseTimeDetailByContact();
    }

    /**
     * Detalle paginado de "tiempo por etapa", una fila por lead.
     */
    public function getStageDwellTimeStats(): array
    {
        return $this->leadReporting->getStageDwellTimeByLead();
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
    /**
     * Cuántas plantillas de WhatsApp se mandaron en el período.
     *
     * Existe porque cada plantilla SE PAGA. Es el único gasto del CRM que crece solo
     * —la bienvenida sale con cada alta de contacto, el recordatorio de recompra con
     * cada ciclo de cada cliente— y sin un número a la vista nadie se entera hasta
     * que llega la factura de Meta.
     *
     * Se cuentan las que SALIERON (sent/delivered/read) y no las encoladas ni las
     * fallidas: Meta cobra el mensaje entregado, no el intento.
     */
    public function getPlantillasEnviadasStats(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('whatsapp_messages')) {
            return ['total' => 0, 'por_motivo' => []];
        }

        $base = fn () => \Illuminate\Support\Facades\DB::table('whatsapp_messages')
            ->where('whatsapp_messages.direction', 'outbound')
            ->where('whatsapp_messages.type', 'template')
            ->whereIn('whatsapp_messages.status', ['sent', 'delivered', 'read']);

        // Calificadas: la lista de envíos se une con conversaciones y personas.
        $enRango = fn ($q) => $q->whereBetween('whatsapp_messages.created_at', [
            $this->getStartDate(),
            $this->getEndDate(),
        ]);

        $total = (clone $enRango($base()))->count();

        /*
         * Cortado por plantilla y no sólo el total: "mandamos 400" no dice nada, y
         * "320 de bienvenida y 80 de recordatorio" dice si el gasto viene de captar
         * clientes nuevos o de insistirle a los que ya están.
         *
         * El nombre sale del payload, que es donde SendMessage lo escribe. En MySQL
         * la columna es JSON nativo, así que se lee con ->> y no con un LIKE.
         */
        $porMotivo = $enRango($base())
            ->selectRaw("json_unquote(json_extract(payload, '$.template')) as plantilla, count(*) as total")
            ->groupBy('plantilla')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [
                'plantilla' => (string) ($f->plantilla ?: 'sin nombre'),
                'total'     => (int) $f->total,
            ])
            ->all();

        /*
         * A quién se le mandó cada una: permite revisar si un recordatorio le
         * llegó a un cliente o si la bienvenida salió dos veces. Las últimas 100
         * del período; el total de arriba cuenta todas.
         */
        $envios = $enRango($base())
            ->leftJoin('whatsapp_conversations as c', 'c.id', '=', 'whatsapp_messages.conversation_id')
            ->leftJoin('persons as p', 'p.id', '=', 'c.person_id')
            ->orderByDesc('whatsapp_messages.created_at')
            ->limit(100)
            ->get([
                'whatsapp_messages.created_at',
                'whatsapp_messages.status',
                \Illuminate\Support\Facades\DB::raw("json_unquote(json_extract(".\Illuminate\Support\Facades\DB::getTablePrefix()."whatsapp_messages.payload, '$.template')) as plantilla"),
                'c.wa_phone',
                'c.wa_name',
                'p.name as cliente',
            ])
            ->map(fn ($f) => [
                'fecha'     => (string) $f->created_at,
                'plantilla' => (string) ($f->plantilla ?: 'sin nombre'),
                'cliente'   => (string) ($f->cliente ?: $f->wa_name ?: '—'),
                'telefono'  => (string) ($f->wa_phone ?: '—'),
                'estado'    => (string) $f->status,
            ])
            ->all();

        return [
            'total'      => $total,
            'por_motivo' => $porMotivo,
            'envios'     => $envios,
        ];
    }

    /**
     * Si el usuario en sesión ve las plantillas enviadas: administradores y
     * encargados. Es un gasto de la empresa y la lista trae teléfonos de
     * clientes de todas las ciudades, que un distribuidor no tiene por qué ver.
     */
    public static function vePlantillas(): bool
    {
        $role = auth()->guard('user')->user()?->role;

        return $role !== null && ($role->permission_type === 'all' || $role->name === 'Encargado');
    }

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

    /**
     * Las tortas de productos con su tabla (formato de Kolberg): TODOS los
     * productos —antes eran los 5 primeros y la torta no sumaba el total— y los
     * totales de la última fila. Los pedidos se cuentan distintos: un pedido con
     * dos productos es uno.
     */
    protected function tortaDeProductos(Collection $productos, bool $soloEntregados): array
    {
        return [
            'products'    => $productos->values(),
            'total_qty'   => (int) $productos->sum('total_qty_ordered'),
            'total_leads' => $this->productReporting->getLeadsWithProductsCount($soloEntregados),
        ];
    }
}
