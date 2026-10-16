<?php

namespace Webkul\Admin\Helpers\Reporting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\ProductRepository;
use Webkul\Lead\Repositories\StageRepository;

class Product extends AbstractReporting
{
    /**
     * The won (delivered/sold) stage ids. Matches the same convention used in Reporting\Lead.
     */
    protected array $wonStageIds;

    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected StageRepository $stageRepository,
    ) {
        $this->wonStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'won')
            ->orWhere('code', 'pedido-entregado')
            ->orWhereRaw("LOWER(name) LIKE '%won%'")
            ->orWhereRaw("LOWER(name) LIKE '%ganado%'")
            ->orWhereRaw("LOWER(name) LIKE '%entregado%'")
            ->pluck('id')
            ->toArray();

        parent::__construct();
    }

    /**
     * Gets top-selling products by revenue.
     *
     * @param  int  $limit
     */
    public function getTopSellingProductsByRevenue($limit = 200): Collection
    {
        $tablePrefix = DB::getTablePrefix();

        $items = $this->productRepository
            ->resetModel()
            ->with('product')
            // `leads` se une una sola vez (abajo). Unirla de nuevo con user_id
            // daba «Not unique table/alias» desde que el alcance lo fuerza
            // para los ejecutivos (spec tablero-por-asesor).
            ->when(request('user_id'), fn ($q) => $q->where('leads.user_id', request('user_id')))
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
            })
            ->leftJoin('leads', 'lead_products.lead_id', '=', 'leads.id')
            ->leftJoin('products', 'lead_products.product_id', '=', 'products.id')
            ->select('*')
            ->addSelect(DB::raw('SUM('.$tablePrefix.'lead_products.amount) as revenue'))
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->having(DB::raw('SUM('.$tablePrefix.'lead_products.amount)'), '>', 0)
            ->groupBy('product_id')
            ->orderBy('revenue', 'DESC')
            ->limit($limit)
            ->get();

        $items = $items->map(function ($item) {
            return [
                'id'                => $item->product_id,
                'name'              => $item->name,
                'price'             => $item->product?->price,
                'formatted_price'   => core()->formatBasePrice($item->price),
                'revenue'           => $item->revenue,
                'formatted_revenue' => core()->formatBasePrice($item->revenue),
            ];
        });

        return $items;
    }

    /**
     * Productos más solicitados: unidades cotizadas de cada producto, más en
     * cuántas cotizaciones aparece (decisión D3 del 15/10/2026: la porción es
     * la cantidad, p. ej. 4 cotizaciones que suman 29 bolsas = 29). Las
     * cotizaciones de pedidos «Sin ciudad» no cuentan (P2 pendiente).
     */
    public function getTopSellingProductsByQuantity($limit = null): Collection
    {
        return $this->cotizadoPorProducto($this->startDate, $this->endDate)
            ->take($limit ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Unidades cotizadas por producto (spec tablero-consistente, D3): la fuente
     * de verdad de los productos es la cotización, no lo que se carga en el
     * pedido (lead_products casi siempre va con cantidad 0). El KPI y la torta
     * salen de acá, así que la torta siempre suma lo mismo que el KPI.
     *
     * Se toman las cotizaciones de los pedidos CREADOS en el rango, igual que
     * el resto del tablero, con la ciudad y el ACL del pedido. Con $stageIds se
     * limita a pedidos en esas etapas (p. ej. entregados). Mientras la
     * pregunta P2 siga abierta, cada par KPI/torta conserva su regla de «Sin
     * ciudad»: solicitados la deja afuera, vendidos la cuenta.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @param  array<int, int>|null  $stageIds
     * @return Collection<int, array{id: int, name: string, price: mixed, formatted_price: string, solicitudes: int, total_qty_ordered: float}>
     */
    protected function cotizadoPorProducto($startDate, $endDate, ?array $stageIds = null, bool $sinCiudadAfuera = true): Collection
    {
        $p = DB::getTablePrefix();

        $query = DB::table('quote_items')
            ->join('quotes', 'quotes.id', '=', 'quote_items.quote_id')
            ->join('lead_quotes', 'lead_quotes.quote_id', '=', 'quotes.id')
            ->join('leads', 'leads.id', '=', 'lead_quotes.lead_id')
            ->join('products', 'products.id', '=', 'quote_items.product_id')
            ->whereBetween('leads.created_at', [$startDate, $endDate])
            ->when($stageIds !== null, fn ($q) => $q->whereIn('leads.lead_pipeline_stage_id', $stageIds ?: [0]));

        $this->pedidosDelTablero($query);

        if ($sinCiudadAfuera) {
            $this->excluirSinCiudad($query);
        }

        return $query
            ->groupBy('products.id', 'products.name', 'products.price')
            ->select('products.id', 'products.name', 'products.price')
            ->selectRaw("COUNT(DISTINCT {$p}quotes.id) as solicitudes")
            ->selectRaw("COALESCE(SUM({$p}quote_items.quantity), 0) as total_qty_ordered")
            ->orderByDesc('total_qty_ordered')
            ->orderByDesc('solicitudes')
            ->get()
            ->map(fn ($item) => [
                'id'                => (int) $item->id,
                'name'              => $item->name,
                'price'             => $item->price,
                'formatted_price'   => core()->formatBasePrice($item->price),
                'solicitudes'       => (int) $item->solicitudes,
                'total_qty_ordered' => (float) $item->total_qty_ordered,
            ]);
    }

    /**
     * Productos más vendidos: unidades cotizadas de los pedidos ENTREGADOS
     * (D4/D5). Mismo método que el KPI «Total productos vendidos», así la
     * torta suma lo mismo. Un entregado sin cotización no suma (D6).
     *
     * @param  int|null  $limit
     */
    public function getTopSellingProductsByQuantitySold($limit = null): Collection
    {
        return $this->cotizadoPorProducto($this->startDate, $this->endDate, $this->wonStageIds, sinCiudadAfuera: false)
            ->take($limit ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Retrieves total services (products sold) and their progress.
     */
    public function getTotalServicesProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalServices($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalServices($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total services by date
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalServices($startDate, $endDate): int
    {
        // KPI «Total productos solicitados» = suma de la torta de solicitados (D3).
        return (int) $this->cotizadoPorProducto($startDate, $endDate)->sum('total_qty_ordered');
    }

    /**
     * KPI «Total productos vendidos (Entregados)» y su progreso: unidades
     * cotizadas de los pedidos entregados creados en el período (D4/D5). Es
     * la suma de la torta «Productos más vendidos».
     */
    public function getTotalProductsSoldProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalProductsSold($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalProductsSold($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total products sold (delivered) by date.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalProductsSold($startDate, $endDate): int
    {
        // KPI «Total productos vendidos (Entregados)» = suma de la torta de más vendidos.
        return (int) $this->cotizadoPorProducto($startDate, $endDate, $this->wonStageIds, sinCiudadAfuera: false)
            ->sum('total_qty_ordered');
    }

    /**
     * Returns total products sold over time
     *
     * @param  string  $period
     */
    public function getTotalProductsSoldOverTime($period = 'auto'): array
    {
        $period = $this->determinePeriod($period);

        $intervals = $this->generateTimeIntervals($this->startDate, $this->endDate, $period);

        $groupColumn = $this->getGroupColumn(DB::getTablePrefix().'leads.created_at', $period); // con prefijo kl_: si no, 500

        $tablePrefix = DB::getTablePrefix();

        $query = $this->productRepository
            ->resetModel()
            ->leftJoin('leads', 'lead_products.lead_id', '=', 'leads.id')
            ->select(
                DB::raw("$groupColumn AS date"),
                DB::raw('SUM('.$tablePrefix.'lead_products.quantity) AS count')
            )
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            // Ciudad y alcance del asesor, como el resto del tablero.
            ->tap(fn ($q) => $this->pedidosDelTablero($q))
            ->groupBy(DB::raw($groupColumn))
            ->orderBy(DB::raw($groupColumn));

        $results = $query->get();
        $resultLookup = $results->keyBy('date');

        $stats = [];

        foreach ($intervals as $interval) {
            $result = $resultLookup->get($interval['key']);

            $stats[] = [
                'label' => $interval['label'],
                'count' => $result ? (int) $result->count : 0,
            ];
        }

        return $stats;
    }

    /**
     * Ventas por intervalo, desde las cotizaciones de los pedidos ENTREGADOS
     * (D4): `count` = unidades cotizadas, `total` = suma de `grand_total` de
     * esas cotizaciones. Mismo universo que el KPI «Total productos vendidos»
     * (fecha de creación del pedido, ciudad, ACL y «Sin ciudad» incluida), así
     * la pestaña de Evolución suma lo mismo que el KPI. Devuelve la forma de
     * Lead::getOverTimeStats para armar el payload igual.
     *
     * Las unidades se agregan por cotización antes del join: sumar
     * `grand_total` sobre las líneas lo multiplicaría por la cantidad de ítems.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getUnitsSoldOverTime($startDate, $endDate, string $period): array
    {
        $intervals = $this->generateTimeIntervals($startDate, $endDate, $period);

        $p = DB::getTablePrefix();

        // Raw expression: qualify with the real (prefixed) table name since this is a joined query.
        $groupColumn = $this->getGroupColumn($p.'leads.created_at', $period);

        $unidades = DB::table('quote_items')
            ->select('quote_id')
            ->selectRaw('SUM(quantity) AS unidades')
            ->groupBy('quote_id');

        $query = DB::table('quotes')
            ->join('lead_quotes', 'lead_quotes.quote_id', '=', 'quotes.id')
            ->join('leads', 'leads.id', '=', 'lead_quotes.lead_id')
            ->leftJoinSub($unidades, 'qi', 'qi.quote_id', '=', 'quotes.id')
            ->whereIn('leads.lead_pipeline_stage_id', $this->wonStageIds ?: [0])
            ->whereBetween('leads.created_at', [$startDate, $endDate]);

        $this->pedidosDelTablero($query);

        $results = $query
            ->selectRaw("{$groupColumn} AS date")
            ->selectRaw("COALESCE(SUM({$p}qi.unidades), 0) AS count")
            ->selectRaw("COALESCE(SUM({$p}quotes.grand_total), 0) AS total")
            ->groupBy(DB::raw($groupColumn))
            ->get()
            ->keyBy('date');

        $stats = [];

        foreach ($intervals as $interval) {
            $result = $results->get($interval['key']);

            $stats[] = [
                'label' => $interval['label'],
                'count' => $result ? (int) $result->count : 0,
                'total' => $result ? (float) $result->total : 0,
            ];
        }

        return $stats;
    }

    /**
     * Returns the current and previous period units-sold buckets (same period
     * granularity) for the dashboard evolution card.
     */
    public function getUnitsSoldEvolution(): array
    {
        $period = $this->determinePeriod('auto');

        return [
            'current'  => $this->getUnitsSoldOverTime($this->startDate, $this->endDate, $period),
            'previous' => $this->getUnitsSoldOverTime($this->lastStartDate, $this->lastEndDate, $period),
            'period'   => $period,
        ];
    }

    /**
     * Generate time intervals based on period
     */
    protected function generateTimeIntervals(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate, string $period): array
    {
        $intervals = [];

        // Anchor the first bucket to the period boundary so the bucket keys align
        // with the SQL grouping AND the bucket containing the end date is never
        // dropped (a mid-period start date used to skip the last week/month).
        $current = match ($period) {
            'week'  => $startDate->copy()->startOfWeek(\Carbon\CarbonInterface::SUNDAY),
            'month' => $startDate->copy()->startOfMonth(),
            'year'  => $startDate->copy()->startOfYear(),
            default => $startDate->copy()->startOfDay(),
        };

        while ($current <= $endDate) {
            $interval = [
                'key'   => $this->formatDateForGrouping($current, $period),
                'label' => $this->formatDateForLabel($current, $period),
            ];

            $intervals[] = $interval;

            switch ($period) {
                case 'day':
                    $current->addDay();
                    break;
                case 'week':
                    $current->addWeek();
                    break;
                case 'month':
                    $current->addMonth();
                    break;
                case 'year':
                    $current->addYear();
                    break;
            }
        }

        return $intervals;
    }

    protected function determinePeriod(string $period): string
    {
        if ($period !== 'auto') {
            return $period;
        }

        $days = $this->startDate->diffInDays($this->endDate);

        if ($days <= 31) {
            return 'day';
        } elseif ($days <= 90) {
            return 'week';
        } elseif ($days <= 365) {
            return 'month';
        }

        return 'year';
    }

    protected function getGroupColumn(string $dateColumn, string $period): string
    {
        return match ($period) {
            'day'   => "DATE_FORMAT($dateColumn, '%Y-%m-%d')",
            'week'  => "DATE_FORMAT(DATE_ADD($dateColumn, INTERVAL(1-DAYOFWEEK($dateColumn)) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT($dateColumn, '%Y-%m')",
            'year'  => "DATE_FORMAT($dateColumn, '%Y')",
            default => "DATE_FORMAT($dateColumn, '%Y-%m-%d')",
        };
    }

    protected function formatDateForGrouping(\Carbon\Carbon $date, string $period): string
    {
        return match ($period) {
            'day'   => $date->format('Y-m-d'),
            'week'  => $date->copy()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            'year'  => $date->format('Y'),
            default => $date->format('Y-m-d'),
        };
    }

    protected function formatDateForLabel(\Carbon\Carbon $date, string $period): string
    {
        return match ($period) {
            'day'   => $date->format('d M'),
            'week'  => $date->copy()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->format('d M').' - '.$date->copy()->endOfWeek(\Carbon\CarbonInterface::SATURDAY)->format('d M'),
            'month' => $date->format('M Y'),
            'year'  => $date->format('Y'),
            default => $date->format('d M'),
        };
    }
}
