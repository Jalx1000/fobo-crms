<?php

namespace Webkul\Admin\Http\Controllers;

use Maatwebsite\Excel\Facades\Excel;
use Webkul\Admin\Exports\Dashboard\DashboardExport;
use Webkul\Admin\Exports\Dashboard\DashboardLeadsSheet;
use Webkul\Admin\Helpers\Dashboard;
use Webkul\Admin\Helpers\FiltroEjecutivo;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Repositories\PipelineRepository;

class DashboardController extends Controller
{
    /**
     * Request param functions
     *
     * @var array
     */
    protected $typeFunctions = [
        'over-all'                        => 'getOverAllStats',
        'revenue-stats'                   => 'getRevenueStats',
        'total-leads'                     => 'getTotalLeadsStats',
        'revenue-by-sources'              => 'getLeadsStatsBySources',
        'revenue-by-types'                => 'getLeadsStatsByTypes',
        'top-selling-products'            => 'getTopSellingProducts',
        'quantity-products'               => 'getTopSellingProductsByQuantity',
        'quantity-products-sold'          => 'getTopSellingProductsByQuantitySold',
        'top-persons'                     => 'getTopPersons',
        'open-leads-by-states'            => 'getOpenLeadsByStates',
        'open-leads-by-states-fixed'      => 'getOpenLeadsByStatesFixed',
        'vendedores'                      => 'getVendedoresStats',
        'ventas'                          => 'getVentasByUsersStats',
        'leads-by-users'                  => 'getLeadsByUsersStats',
        'leads-attention-by-users'        => 'getLeadsAttentionByUsersStats',
        'tiempo-por-vendedor'             => 'getResponseTimeByUsersStats',
        'distribucion-tiempo-respuesta'   => 'getResponseTimeDistributionStats',
        'ventas-por-sucursal'             => 'getVentasByBranchesStats',
        'leads-por-sucursal'              => 'getLeadsByBranchesStats',
        'ventas-por-ciudad'               => 'getVentasByPipelinesStats',
        'ventas-por-pipeline'             => 'getVentasByPipelinesStats',
        'leads-por-ciudad'                => 'getLeadsByPipelinesStats',
        'leads-por-pipeline'              => 'getLeadsByPipelinesStats',
        'leads-no-atendidos-por-ciudad'   => 'getNotAttendedLeadsByCityStats',
        'entregados-sin-cotizacion'       => 'getEntregadosSinCotizacionStats',
        'total-leads-by-stages'           => 'getTotalLeadsByStages',
        'total-leads-by-stages-over-time' => 'getTotalLeadsByStagesOverTime',
        'total-services'                  => 'getTotalServicesStats',
        'evolution'                       => 'getEvolutionStats',
    ];

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected PipelineRepository $pipelineRepository,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $this->applyGlobalFilterDefaults();

        $dashboardHelper = app(Dashboard::class);

        return view('admin::dashboard.index')->with([
            'startDate' => $dashboardHelper->getStartDate(),
            'endDate'   => $dashboardHelper->getEndDate(),
            'pipelines' => $this->pipelineRepository->all(['id', 'name']),
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats()
    {
        $type = request()->query('type');

        if (! array_key_exists($type, $this->typeFunctions)) {
            return response()->json([
                'message' => 'Parámetro type inválido o ausente.',
            ], 422);
        }

        $this->applyGlobalFilterDefaults();

        $this->aplicarAlcance();

        /**
         * Resolve the helper AFTER injecting the global-filter defaults, because
         * the reporting reads start/end in its constructor.
         */
        $dashboardHelper = app(Dashboard::class);

        $stats = $dashboardHelper->{$this->typeFunctions[$type]}();

        return response()->json([
            'statistics' => $stats,
            'date_range' => $dashboardHelper->getDateRange(),
        ]);
    }

    /**
     * Download the dashboard as an Excel/CSV file: a KPI summary sheet plus a
     * detail sheet with the underlying lead/pedido rows and custom attributes,
     * honoring the active city/date filter.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export()
    {
        $format = request()->query('format', 'xlsx');

        if (! in_array($format, ['csv', 'xls', 'xlsx'])) {
            $format = 'xlsx';
        }

        $this->applyGlobalFilterDefaults();

        $this->aplicarAlcance();

        /**
         * Translate the dashboard's global filter (pipeline_id + start/end) into
         * the params LeadDataGrid expects for the detail sheet: a custom date
         * range, and 'all' cities when no specific city is selected.
         */
        if (($start = request('start')) && ($end = request('end'))) {
            request()->merge([
                'date_filter' => 'custom',
                'date_from'   => $start,
                'date_to'     => $end,
            ]);
        }

        if (! is_numeric(request('pipeline_id'))) {
            request()->merge(['pipeline_id' => 'all']);
        }

        $range = (($from = request('start')) && ($to = request('end')))
            ? $from.'_'.$to
            : now()->format('Y-m-d');

        $fileName = 'tablero_'.$range.'.'.$format;

        /**
         * CSV cannot hold multiple sheets, so fall back to the detail rows
         * (the analytically useful part) for that format.
         */
        if ($format === 'csv') {
            return Excel::download(new DashboardLeadsSheet, $fileName);
        }

        return Excel::download(new DashboardExport(app(Dashboard::class)), $fileName);
    }

    /**
     * Apply the shared global filters (city + date range) as request defaults
     * when the request doesn't carry them, so a card's initial unfiltered load
     * stays consistent with the Pedidos module (and avoids a blank/data race).
     *
     * Uses has() (not filled()) so an explicit empty pipeline_id ("Todas") from
     * the dashboard UI is respected as "no city filter" rather than overridden.
     */
    private function applyGlobalFilterDefaults(): void
    {
        if (! request()->has('pipeline_id')) {
            $city = request()->cookie('global_pipeline_id');

            // Only numeric ids filter; 'all'/empty means no city restriction.
            if (is_numeric($city)) {
                request()->merge(['pipeline_id' => $city]);
            }
        }

        if (! request()->has('start') && ! request()->has('end')) {
            $range = request()->cookie('global_date_range');

            if ($range) {
                [$from, $to] = array_pad(explode('|', $range), 2, null);

                if ($from && $to) {
                    request()->merge(['start' => $from, 'end' => $to]);
                }
            }
        }
    }

    /**
     * Quien no ve el total (spec tablero-por-asesor, D1–D3, D5) recibe solo lo
     * suyo, en el servidor y para TODAS las tarjetas y el Excel. Lo mismo
     * para Administrador y Supervisores cuando eligen un ejecutivo en el
     * selector del tablero (FiltroEjecutivo::usuarioDelTablero()):
     *
     *  - `user_id` se pisa con su id: lo que venga en la URL se ignora.
     *  - Un scope global en Lead filtra cada consulta de Eloquent sobre leads.
     *    Así una tarjeta que se olvide de filtrar no muestra la cartera de
     *    otro. Las consultas con DB::table pasan por
     *    AbstractReporting::pedidosDelTablero(), que usa la misma regla.
     *
     * El scope es estático (queda registrado en el modelo para todo el
     * proceso), por eso NO guarda el id de quien lo registró: pregunta a
     * FiltroEjecutivo en cada consulta. Si no, en un worker o en los tests, el
     * siguiente usuario heredaría el filtro del anterior.
     */
    private function aplicarAlcance(): void
    {
        // Ejecutivo: el suyo. Administrador y Supervisores: el elegido en el
        // selector «Ejecutivo comercial» del tablero, si eligieron uno.
        $userId = FiltroEjecutivo::usuarioDelTablero();

        if ($userId === null) {
            return;
        }

        request()->merge(['user_id' => $userId]);
        request()->query->set('user_id', $userId);

        LeadProxy::modelClass()::addGlobalScope(
            'alcance-tablero',
            fn ($query) => FiltroEjecutivo::limitarTablero($query->getQuery()),
        );
    }
}
