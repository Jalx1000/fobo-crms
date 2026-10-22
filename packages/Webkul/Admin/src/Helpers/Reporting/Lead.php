<?php

namespace Webkul\Admin\Helpers\Reporting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Lead\Repositories\StageRepository;
use Webkul\User\Repositories\UserRepository;

class Lead extends AbstractReporting
{
    /**
     * Semáforo de "tiempo en responder": hasta 4 h verde, hasta 24 h amarillo,
     * más de 24 h rojo. El límite rojo lo fija el negocio.
     */
    protected const GREEN_LIMIT_SECONDS = 4 * 3600;

    protected const RED_LIMIT_SECONDS = 24 * 3600;

    /**
     * Rol de la cuenta que crea los leads entrantes (kohlberg@sofopolis.com) y
     * rol de quien debe atenderlos. Deben coincidir con `roles.name` exactamente.
     */
    protected string $systemRoleName = 'Sistema';

    protected string $managerRoleName = 'Encargado';

    /**
     * Roles que ven el tablero completo aunque no sean `permission_type = all`.
     * Supervisores no tiene leads propios: restringirlo lo dejaría con la tarjeta
     * vacía, cuando su trabajo es justamente controlar a los encargados.
     */
    protected array $fullViewRoleNames = ['Supervisores'];

    /**
     * The channel ids.
     */
    protected array $stageIds;

    /**
     * The all stage ids.
     */
    protected array $allStageIds;

    /**
     * The won stage ids.
     */
    protected array $wonStageIds;

    /**
     * The lost stage ids.
     */
    protected array $lostStageIds;

    /**
     * The confirmed stage ids.
     */
    protected array $confirmedStageIds;

    /**
     * The prospecto stage ids.
     */
    protected array $prospectoStageIds;

    /**
     * The "No atendido" stage ids (primera etapa del pipeline, lead aún sin
     * trabajar). En esta base no existe una etapa "Prospecto": la etapa inicial
     * se llama literalmente "No atendido" (code `no-atendido`).
     */
    protected array $notAttendedStageIds;

    /**
     * The "Cliente sin interés" stage ids. Terminal, igual que entregados y
     * cancelados, pero no lo capturan ni `wonStageIds` ni `lostStageIds`.
     */
    protected array $noInterestStageIds;

    /**
     * Umbrales del semáforo de "tiempo por etapa", en segundos, indexados por el
     * code normalizado de la etapa. `default` cubre cualquier etapa que se agregue
     * después.
     *
     * "No atendido" hereda la regla de negocio ya vigente (4 h / 24 h): mide el
     * tiempo en contestar un WhatsApp entrante. "Cliente confirmado" mide algo
     * distinto -- preparar y entregar el pedido -- y con ese mismo umbral saldría
     * rojo siempre, con lo que el color dejaría de informar; se le dan 48 h / 120 h
     * (2 y 5 días).
     */
    protected array $stageDwellThresholds = [
        'no-atendido'        => ['green' => 4 * 3600, 'red' => 24 * 3600],
        'cliente-confirmado' => ['green' => 48 * 3600, 'red' => 120 * 3600],
        'default'            => ['green' => self::GREEN_LIMIT_SECONDS, 'red' => self::RED_LIMIT_SECONDS],
    ];

    /**
     * Role names excluded from vendor/sales statistics. Must match `roles.name` in the database exactly.
     */
    protected array $ignoredRoleNames = ['Supervisores', 'Administrador'];

    /**
     * User names excluded from vendor/sales statistics (e.g. the catch-all
     * "Agente" account used for unassigned leads). Must match `users.name`
     * exactly.
     */
    protected array $ignoredUserNames = ['Agente'];

    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct(
        protected LeadRepository $leadRepository,
        protected StageRepository $stageRepository,
        protected PipelineRepository $pipelineRepository,
        protected UserRepository $userRepository,
        protected AttributeRepository $attributeRepository,
    ) {
        $this->allStageIds = $this->stageRepository->pluck('id')->toArray();

        $this->wonStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'won')
            ->orWhere('code', 'pedido-entregado')
            ->orWhereRaw("LOWER(name) LIKE '%won%'")
            ->orWhereRaw("LOWER(name) LIKE '%ganado%'")
            ->orWhereRaw("LOWER(name) LIKE '%entregado%'")
            ->pluck('id')
            ->toArray();

        $this->lostStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'lost')
            ->orWhere('code', 'pedidos-cancelado')
            ->orWhereRaw("LOWER(name) LIKE '%lost%'")
            ->orWhereRaw("LOWER(name) LIKE '%perdido%'")
            ->orWhereRaw("LOWER(name) LIKE '%cancelado%'")
            ->pluck('id')
            ->toArray();

        $this->confirmedStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'pedidos-confirmado')
            ->orWhereRaw("LOWER(name) LIKE '%confirmado%'")
            ->pluck('id')
            ->toArray();

        $this->prospectoStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'prospectos')
            ->orWhereRaw("LOWER(name) LIKE '%prospecto%'")
            ->pluck('id')
            ->toArray();

        $this->notAttendedStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'like', '%no-atendido%')
            ->orWhereRaw("LOWER(name) LIKE '%no atendido%'")
            ->pluck('id')
            ->toArray();

        /*
         * Sensia no tiene etapa "No atendido": el pedido que nadie trabajó todavía
         * está en "Prospectos". Sin esto la lista quedaba vacía y "Atendidos vs no
         * atendidos" contaba todo como atendido (la barra roja nunca aparecía).
         */
        if (! $this->notAttendedStageIds) {
            $this->notAttendedStageIds = $this->prospectoStageIds;
        }

        $this->noInterestStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'like', '%sin-inter%')
            ->orWhereRaw("LOWER(name) LIKE '%sin inter%'")
            ->pluck('id')
            ->toArray();

        parent::__construct();
    }

    /**
     * Etapas del proceso «Abierto» = Prospecto + Pedido confirmado (aún sin entregar ni
     * cancelado). El panel de control contabiliza los «Prospectos» como los clientes potenciales que se encuentran actualmente en estas
     * etapas, por lo que las fichas coinciden con el kanban (columnas «Prospecto» y «Confirmado»).
     */
    protected function openStageIds(): array
    {
        return array_values(array_unique(array_merge($this->prospectoStageIds, $this->confirmedStageIds))) ?: [0];
    }

    /**
     * Expresión CASE de SQL para la «fecha del evento» de un cliente potencial en función de su
     * fase actual (Opción B): prospecto => created_at, confirmado => confirmed_at,
     * entregado/cancelado => closed_at. Recurre a created_at cuando la
     * fecha del evento es nula (filas heredadas). Se utiliza para que todas las métricas basadas en la etapa y el kanban
     * cuenten un cliente potencial en la fecha en que alcanzó su etapa actual.
     */
    protected function stageEventDateExpr(string $table = 'leads'): string
    {
        $prefix = DB::getTablePrefix();
        $col = $prefix.$table;

        /**
         * Decisión de negocio: contar cada lead por su FECHA DE CREACIÓN
         * (created_at), no por la fecha del evento de su etapa actual. Así el
         * conteo de leads/tablero cuadra con el módulo de Contactos, que filtra
         * las personas por persons.created_at: en una ventana de N días, "leads"
         * y "contactos" cuentan el mismo conjunto (los creados en la ventana) y
         * un cliente viejo que solo AVANZÓ de etapa dentro de la ventana ya no
         * infla el conteo de leads.
         *
         * (Se conserva el método como punto único para no tocar sus 8 llamadores;
         * la variante por fecha de evento quedó descartada a propósito.)
         */
        return "{$col}.created_at";
    }

    /**
     * Excludes users whose role is in $ignoredRoleNames (e.g. Supervisores, Administrador)
     * from a query, via subquery to avoid join collisions with already-joined tables.
     */
    private function excludeIgnoredRoles($query, string $userIdColumn = 'users.id')
    {
        return $query->whereNotIn($userIdColumn, function ($sub) {
            $sub->select('users.id')
                ->from('users')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->whereIn('roles.name', $this->ignoredRoleNames);
        });
    }

    /**
     * Returns current customers over time
     *
     * @param  string  $period
     */
    public function getTotalLeadsOverTime($period = 'auto'): array
    {
        // «Prospectos» = clientes potenciales que se encuentran actualmente en «Prospecto» o «Pedido confirmado», contabilizados
        // según la fecha del evento de cada fase, para que coincidan con el kanban.
        $this->stageIds = $this->openStageIds();

        $period = $this->determinePeriod($period);

        return $this->getOverTimeStats($this->startDate, $this->endDate, 'leads.id', $this->stageEventDateExpr(), $period);
    }

    /**
     * Devuelve los clientes potenciales que se encuentran actualmente en la fase «Prospecto» a lo largo del tiempo (el segmento puro
     * de «Prospectos» B'), agrupados por created_at mediante la
     * expresión compartida event-date. Sumado a getTotalConfirmedLeadsOverTime
     *, equivale a getTotalLeadsOverTime (el total del gráfico de barras apiladas).
     *
     * @param  string  $period
     */
    public function getTotalProspectoLeadsOverTime($period = 'auto'): array
    {
        $this->stageIds = $this->prospectoStageIds ?: [0];

        $period = $this->determinePeriod($period);

        return $this->getOverTimeStats($this->startDate, $this->endDate, 'leads.id', $this->stageEventDateExpr(), $period);
    }

    /**
     * Muestra los clientes potenciales que se encuentran actualmente en la fase «Pedido confirmado» a lo largo del tiempo (el
     * segmento «confirmado» de «Prospectos» B'), agrupados por confirmed_at mediante la
     * expresión compartida event-date.
     *
     * @param  string  $period
     */
    public function getTotalConfirmedLeadsOverTime($period = 'auto'): array
    {
        $this->stageIds = $this->confirmedStageIds ?: [0];

        $period = $this->determinePeriod($period);

        return $this->getOverTimeStats($this->startDate, $this->endDate, 'leads.id', $this->stageEventDateExpr(), $period);
    }

    /**
     * Returns current customers over time
     *
     * @param  string  $period
     */
    public function getTotalWonLeadsOverTime($period = 'auto'): array
    {
        $this->stageIds = $this->wonStageIds;

        $period = $this->determinePeriod($period);

        // Contar los entregados por fecha de creación del lead (created_at), igual
        // que el resto del tablero, para que la línea verde cuadre con la "Vista
        // por etapa" y con Evolución.
        return $this->getOverTimeStats($this->startDate, $this->endDate, 'leads.id', $this->stageEventDateExpr(), $period);
    }

    /**
     * Returns current customers over time
     *
     * @param  string  $period
     */
    public function getTotalLostLeadsOverTime($period = 'auto'): array
    {
        $this->stageIds = $this->lostStageIds;

        $period = $this->determinePeriod($period);

        return $this->getOverTimeStats($this->startDate, $this->endDate, 'leads.id', $this->stageEventDateExpr(), $period);
    }

    /**
     * Devuelve la carga útil de evolución para una métrica basada en valores líderes: el periodo actual
     * serie por segmento, más la media del periodo anterior por segmento como referencia.
     *
     * @param  string  $metric  ventas | valor-ventas | pedidos-creados
     */
    public function getEvolution(string $metric): array
    {
        switch ($metric) {
            case 'valor-ventas':
                $this->stageIds = $this->wonStageIds;
                $dateColumn = $this->stageEventDateExpr();
                $valueColumn = 'leads.lead_value';
                $key = 'total';
                $format = 'currency';
                break;

            case 'pedidos-creados':
                // "Prospectos" = leads currently open (Prospecto + Confirmado), by
                // each stage's event date.
                $this->stageIds = $this->openStageIds();
                $dateColumn = $this->stageEventDateExpr();
                $valueColumn = 'leads.id';
                $key = 'count';
                $format = 'number';
                break;

            case 'ventas':
            default:
                $this->stageIds = $this->wonStageIds;
                $dateColumn = $this->stageEventDateExpr();
                $valueColumn = 'leads.id';
                $key = 'count';
                $format = 'number';
                break;
        }

        $period = $this->determinePeriod('auto');

        $current = $this->getOverTimeStats($this->startDate, $this->endDate, $valueColumn, $dateColumn, $period);
        $previous = $this->getOverTimeStats($this->lastStartDate, $this->lastEndDate, $valueColumn, $dateColumn, $period);

        return $this->buildEvolutionPayload($current, $previous, $key, $format, $period);
    }

    /**
     * Genera la carga útil de evolución a partir de los intervalos temporales actuales y anteriores:
     * la serie actual, la serie anterior (alineadas intervalo por intervalo), los totales,
     * las medias por intervalo, los intervalos de fechas comparados, el progreso y el formato numérico.
     */
    public function buildEvolutionPayload(array $current, array $previous, string $key, string $format, ?string $period = null): array
    {
        $labels = array_map(fn ($row) => $row['label'], $current);

        $data = array_map(fn ($row) => $key === 'total' ? round((float) $row['total'], 2) : (int) $row['count'], $current);

        $previousValues = array_map(fn ($row) => $key === 'total' ? round((float) $row['total'], 2) : (int) $row['count'], $previous);

        /**
         * Ajusta la serie anterior a la longitud de la serie actual para que el gráfico pueda
         * comparar cada intervalo por su posición (día 1 frente a día 1, ...). Rellena con valores nulos
         * o trunca los datos cuando los recuentos de los intervalos difieran en los límites de los periodos.
         */
        $previousData = [];

        for ($i = 0; $i < count($data); $i++) {
            $previousData[] = array_key_exists($i, $previousValues) ? $previousValues[$i] : null;
        }

        $currentTotal = array_sum($data);
        $previousTotal = array_sum($previousValues);

        $currentAverage = count($data) ? $currentTotal / count($data) : 0;
        $previousAverage = count($previousValues) ? $previousTotal / count($previousValues) : 0;

        $formatNumber = fn ($value) => $format === 'currency'
            ? core()->formatBasePrice($value)
            : (string) (round($value) == $value ? (int) $value : round($value, 1));

        return [
            'labels'                     => $labels,
            'data'                       => $data,
            'previous_data'              => $previousData,
            'period'                     => $period,
            'current_total'              => round($currentTotal, 2),
            'previous_total'             => round($previousTotal, 2),
            'current_total_formatted'    => $formatNumber($currentTotal),
            'previous_total_formatted'   => $formatNumber($previousTotal),
            'current_average'            => round($currentAverage, 2),
            'previous_average'           => round($previousAverage, 2),
            'current_average_formatted'  => $format === 'currency' ? core()->formatBasePrice($currentAverage) : (string) round($currentAverage, 1),
            'previous_average_formatted' => $format === 'currency' ? core()->formatBasePrice($previousAverage) : (string) round($previousAverage, 1),
            'current_range'              => $this->startDate->format('d M').' – '.$this->endDate->format('d M'),
            'previous_range'             => $this->lastStartDate->format('d M').' – '.$this->lastEndDate->format('d M'),
            'progress'                   => $this->getPercentageChange($previousTotal, $currentTotal),
            'format'                     => $format,
        ];
    }

    public function getVendedoresStats($limit = null)
    {
        $tablePrefix = DB::getTablePrefix();

        $userId = is_numeric(request('user_id')) ? request('user_id') : null;
        $organizationId = is_numeric(request('organization_id')) ? request('organization_id') : null;
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $buildQuery = function ($startDate, $endDate) use ($tablePrefix, $userId, $organizationId, $pipelineId) {
            $query = $this->leadRepository
                ->resetModel()
                ->when($userId, function ($q) use ($userId) {
                    $q->where('leads.user_id', $userId);
                })
                ->when($organizationId, function ($q) use ($organizationId) {
                    $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                        ->where('persons.organization_id', $organizationId);
                })
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->leftJoin('lead_activities', 'leads.id', '=', 'lead_activities.lead_id')
                ->leftJoin('activities', 'lead_activities.activity_id', '=', 'activities.id')
                ->select(
                    'users.id as user_id',
                    'users.name as vendedor_name'
                )
                ->addSelect(DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) as total_leads'))
                ->addSelect(DB::raw('SUM(CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.implode(',', $this->wonStageIds).') THEN 1 ELSE 0 END) as sales_count'))
                ->addSelect(DB::raw('SUM(CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.implode(',', $this->wonStageIds).') THEN '.$tablePrefix.'leads.lead_value ELSE 0 END) as total_sales_amount'))
                ->addSelect(DB::raw('AVG(CASE WHEN '.$tablePrefix.'activities.created_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, '.$tablePrefix.'leads.created_at, '.$tablePrefix.'activities.created_at) END) as average_response_time_seconds'))
                ->whereBetween('leads.created_at', [$startDate, $endDate])
                ->groupBy('users.id', 'users.name');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            // bouncer()->getAuthorizedUserIds() returns null for global access (no restriction needed);
            // it returns an array (possibly empty) when access must be scoped, and an empty array must
            // still be applied so it correctly yields zero rows rather than silently skipping the filter.
            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            return $query->get()->keyBy('user_id');
        };

        $current = $buildQuery($this->startDate, $this->endDate);
        $previous = $buildQuery($this->lastStartDate, $this->lastEndDate);

        $items = $current->map(function ($item) use ($previous) {
            $prev = $previous->get($item->user_id);
            $prevSalesAmount = $prev ? (float) $prev->total_sales_amount : 0;

            return [
                'user_id'                       => $item->user_id,
                'name'                          => $item->vendedor_name,
                'total_leads'                   => (int) $item->total_leads,
                'sales_count'                   => (int) $item->sales_count,
                'total_sales_amount'            => (float) $item->total_sales_amount,
                'formatted_total_sales_amount'  => core()->formatBasePrice($item->total_sales_amount),
                'average_response_time_seconds' => $item->average_response_time_seconds ? (int) $item->average_response_time_seconds : null,
                'previous_total_sales_amount'   => $prevSalesAmount,
                'progress'                      => $this->getPercentageChange($prevSalesAmount, (float) $item->total_sales_amount),
            ];
        })->sortByDesc('total_sales_amount')->values();

        if ($limit) {
            $items = $items->take($limit);
        }

        return $items;
    }

    public function getVentasOverTimeByUser($period = 'auto'): array
    {
        $period = $this->determinePeriod($period);

        $intervals = $this->generateTimeIntervals($this->startDate, $this->endDate, $period);

        $tablePrefix = DB::getTablePrefix();

        // Se califica con el nombre real (prefijado) de la tabla: la query une
        // `users`, que también tiene `created_at`, así que sin calificar MySQL
        // rechaza la columna por ambigua.
        $groupColumn = $this->getGroupColumn($tablePrefix.'leads.created_at', $period);

        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $query = $this->leadRepository
            ->resetModel()
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->select(
                DB::raw("$groupColumn AS date"),
                'users.name as user_name',
                DB::raw('COUNT(DISTINCT CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.implode(',', $this->wonStageIds).') THEN '.$tablePrefix.'leads.id END) AS count')
            )
            ->whereIn('leads.lead_pipeline_stage_id', $this->wonStageIds)
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->when($pipelineId, function ($q) use ($pipelineId) {
                $q->where('leads.lead_pipeline_id', $pipelineId);
            })
            ->groupBy(DB::raw($groupColumn), 'users.id', 'users.name')
            ->orderBy(DB::raw($groupColumn));

        $query = $this->excludeIgnoredRoles($query, 'users.id');

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $query->whereIn('users.id', $userIds);
        }

        $results = $query->get();

        $byDate = [];

        foreach ($results as $row) {
            $key = $row->date;
            $user = $row->user_name ?? '-';
            $byDate[$key] ??= [];
            $byDate[$key][$user] = (int) $row->count;
        }

        $usersQuery = $this->userRepository->resetModel()->select('id', 'name');
        $usersQuery = $this->excludeIgnoredRoles($usersQuery, 'id');

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->pluck('name')->toArray();

        $stats = [];

        foreach ($intervals as $interval) {
            $dateKey = $interval['key'];
            $usersCounts = [];
            foreach ($users as $u) {
                $usersCounts[] = [
                    'name'  => $u,
                    'count' => (int) ($byDate[$dateKey][$u] ?? 0),
                ];
            }

            $stats[] = [
                'label' => $interval['label'],
                'users' => $usersCounts,
            ];
        }

        return [
            'over_time' => $stats,
            'users'     => $users,
        ];
    }

    public function getLeadsCountByUsers(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $buildCounts = function ($startDate, $endDate) use ($tablePrefix, $pipelineId) {
            $query = $this->leadRepository
                ->resetModel()
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->select(
                    'users.id as user_id',
                    'users.name as user_name',
                    DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) AS count')
                )
                // "Prospectos" = leads abiertos (Prospecto + Confirmado) por fecha de evento.
                ->whereIn('leads.lead_pipeline_stage_id', $this->openStageIds())
                ->whereRaw('('.$this->stageEventDateExpr().') BETWEEN ? AND ?', [$startDate, $endDate])
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->groupBy('users.id', 'users.name');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            $countsByUser = [];

            foreach ($query->get() as $row) {
                $countsByUser[$row->user_name ?? '-'] = (int) $row->count;
            }

            return $countsByUser;
        };

        $currentCounts = $buildCounts($this->startDate, $this->endDate);
        $previousCounts = $buildCounts($this->lastStartDate, $this->lastEndDate);

        $usersQuery = $this->userRepository->resetModel()->select('id', 'name');
        $usersQuery = $this->excludeIgnoredRoles($usersQuery, 'id');

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->orderBy('name')->pluck('name')->toArray();

        $data = [];
        $previousData = [];
        foreach ($users as $u) {
            $data[] = $currentCounts[$u] ?? 0;
            $previousData[] = $previousCounts[$u] ?? 0;
        }

        return [
            'labels'        => $users,
            'data'          => $data,
            'previous_data' => $previousData,
            'users'         => $users,
        ];
    }

    /**
     * Cuenta pedidos por encargado divididos en dos categorías según su etapa
     * actual, dentro del periodo actual (por created_at):
     *   - "No atendidos" = pedidos en la etapa "No atendido"
     *                      ($notAttendedStageIds), es decir el lead aún sin trabajar.
     *   - "Atendidos"    = pedidos en TODAS las demás etapas (Confirmado,
     *                      Entregado, Cancelado y Sin interés).
     * Reemplaza la comparación actual-vs-anterior para este card; solo periodo
     * actual. Mantiene el mismo filtro de pipeline, roles ignorados y ACL que
     * las demás métricas por usuario.
     */
    public function getLeadsAttentionCountByUsers(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $notAttendedIds = implode(',', $this->notAttendedStageIds ?: [0]);

        $query = $this->leadRepository
            ->resetModel()
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(DISTINCT CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.$notAttendedIds.') THEN '.$tablePrefix.'leads.id END) AS not_attended'),
                DB::raw('COUNT(DISTINCT CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id NOT IN ('.$notAttendedIds.') THEN '.$tablePrefix.'leads.id END) AS attended')
            )
            ->whereRaw('('.$this->stageEventDateExpr().') BETWEEN ? AND ?', [$this->startDate, $this->endDate])
            ->when($pipelineId, function ($q) use ($pipelineId) {
                $q->where('leads.lead_pipeline_id', $pipelineId);
            })
            ->groupBy('users.id', 'users.name');

        $query = $this->excludeIgnoredRoles($query, 'users.id');

        if ($this->ignoredUserNames) {
            $query->whereNotIn('users.name', $this->ignoredUserNames);
        }

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $query->whereIn('users.id', $userIds);
        }

        // Se indexa por user_id (no por nombre) para que dos asesores homónimos
        // no colisionen ni se sobreescriban sus conteos.
        $attendedById = [];
        $notAttendedById = [];

        foreach ($query->get() as $row) {
            $attendedById[$row->user_id] = (int) $row->attended;
            $notAttendedById[$row->user_id] = (int) $row->not_attended;
        }

        $usersQuery = $this->userRepository->resetModel()->select('id', 'name');
        $usersQuery = $this->excludeIgnoredRoles($usersQuery, 'id');

        if ($this->ignoredUserNames) {
            $usersQuery->whereNotIn('name', $this->ignoredUserNames);
        }

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->orderBy('name')->get(['id', 'name']);

        // Nombres que aparecen más de una vez: se desambiguan con el id para que
        // dos asesores homónimos no se muestren como barras idénticas en el chart.
        $duplicateNames = $users->groupBy('name')
            ->filter(fn ($group) => $group->count() > 1)
            ->keys()
            ->flip();

        $labels = [];
        $attended = [];
        $notAttended = [];

        foreach ($users as $u) {
            $labels[] = $duplicateNames->has($u->name)
                ? $u->name.' (#'.$u->id.')'
                : $u->name;
            $attended[] = $attendedById[$u->id] ?? 0;
            $notAttended[] = $notAttendedById[$u->id] ?? 0;
        }

        return [
            'labels'       => $labels,
            'attended'     => $attended,
            'not_attended' => $notAttended,
            'users'        => $labels,
        ];
    }

    public function getLeadsCountByBranches(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $attribute = $this->attributeRepository->findWhere([
            'code'        => 'sucursal',
            'entity_type' => 'persons',
        ])->first();

        $buildCounts = function ($startDate, $endDate) use ($tablePrefix, $pipelineId, $attribute) {
            $query = $this->leadRepository
                ->resetModel()
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                ->leftJoin('attribute_values as av', function ($join) use ($attribute) {
                    $join->on('persons.id', '=', 'av.entity_id')
                        ->where('av.entity_type', '=', 'persons')
                        ->when($attribute, fn ($j) => $j->where('av.attribute_id', '=', $attribute->id));
                })
                ->select(
                    DB::raw($tablePrefix.'av.integer_value as branch_id'),
                    DB::raw($tablePrefix.'av.text_value as branch_text'),
                    DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) AS count')
                )
                ->whereBetween('leads.created_at', [$startDate, $endDate])
                ->whereNotIn('leads.lead_pipeline_stage_id', $this->lostStageIds)
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->groupBy('branch_id', 'branch_text');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            $countsByName = [];

            foreach ($query->get() as $row) {
                $name = null;

                if ($attribute && $attribute->lookup_type && $row->branch_id) {
                    $entity = $this->attributeRepository->getLookUpEntity($attribute->lookup_type, $row->branch_id, ['id', 'name']);
                    $name = $entity?->name;
                }

                $name = $name ?? ($row->branch_text ?: 'Sin sucursal');

                $countsByName[$name] = ($countsByName[$name] ?? 0) + (int) $row->count;
            }

            return $countsByName;
        };

        $currentCounts = $buildCounts($this->startDate, $this->endDate);
        $previousCounts = $buildCounts($this->lastStartDate, $this->lastEndDate);

        $labels = array_unique(array_merge(array_keys($currentCounts), array_keys($previousCounts)));
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        $data = [];
        $previousData = [];
        foreach ($labels as $label) {
            $data[] = $currentCounts[$label] ?? 0;
            $previousData[] = $previousCounts[$label] ?? 0;
        }

        return [
            'labels'        => $labels,
            'data'          => $data,
            'previous_data' => $previousData,
        ];
    }

    public function getVentasCountByUsers(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $buildCounts = function ($startDate, $endDate) use ($tablePrefix, $pipelineId) {
            $query = $this->leadRepository
                ->resetModel()
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->select(
                    'users.id as user_id',
                    'users.name as user_name',
                    DB::raw('COUNT(DISTINCT CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.implode(',', $this->wonStageIds).') THEN '.$tablePrefix.'leads.id END) AS count')
                )
                ->whereBetween('leads.created_at', [$startDate, $endDate])
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->groupBy('users.id', 'users.name');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            $countsByUser = [];

            foreach ($query->get() as $row) {
                $countsByUser[$row->user_name ?? '-'] = (int) $row->count;
            }

            return $countsByUser;
        };

        $currentCounts = $buildCounts($this->startDate, $this->endDate);
        $previousCounts = $buildCounts($this->lastStartDate, $this->lastEndDate);

        $usersQuery = $this->userRepository->resetModel()->select('id', 'name');
        $usersQuery = $this->excludeIgnoredRoles($usersQuery, 'id');

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->orderBy('name')->pluck('name')->toArray();

        $data = [];
        $previousData = [];
        foreach ($users as $u) {
            $data[] = $currentCounts[$u] ?? 0;
            $previousData[] = $previousCounts[$u] ?? 0;
        }

        return [
            'labels'        => $users,
            'data'          => $data,
            'previous_data' => $previousData,
            'users'         => $users,
        ];
    }

    /**
     * "Tiempo en responder" por asesor: tiempo desde la entrada a Prospecto
     * (leads.created_at) hasta el PRIMER cambio de etapa (leads.first_stage_change_at),
     * promediado por usuario. Si el lead sigue en Prospecto sin transición, el reloj
     * corre hasta NOW(). Inmune a reaperturas/movimientos accidentales por ser la
     * 1ª transición pegajosa. El front convierte segundos -> horas.
     */
    public function getAverageResponseTimeByUsers(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        /**
         * "Ahora" en la zona de la app (America/La_Paz), no el NOW() de MySQL (UTC).
         * created_at se guarda en hora local, así que usar el NOW() de SQL (4h
         * adelantado) inflaba el tiempo de los leads abiertos. Se calcula una sola
         * vez para que el período actual y el anterior usen el mismo instante.
         */
        $now = now()->toDateTimeString();

        /**
         * Regla "por etapa actual": el reloj se mide desde created_at hasta la fecha
         * de la ETAPA donde el lead está HOY (no la 1ª transición):
         *   - Prospecto            -> NOW (sigue abierto, el tiempo aumenta)
         *   - Confirmado           -> confirmed_at
         *   - Entregado/Cancelado  -> closed_at  (ej. #161: 22-jun -> 29-jun, no 11 min)
         * Un lead confirmado por error y devuelto a Prospecto vuelve a contar como
         * abierto (cae en la rama Prospecto -> NOW).
         */
        $prospectoIds = implode(',', $this->prospectoStageIds ?: [0]);
        $confirmadoIds = implode(',', $this->confirmedStageIds ?: [0]);

        $buildAverages = function ($startDate, $endDate) use ($tablePrefix, $pipelineId, $now, $prospectoIds, $confirmadoIds) {
            $query = $this->leadRepository
                ->resetModel()
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->select(
                    'users.id as user_id',
                    'users.name as user_name'
                )
                ->selectRaw(
                    'AVG(TIMESTAMPDIFF(SECOND, '.$tablePrefix.'leads.created_at, '
                    .'CASE '
                    .'WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.$prospectoIds.') THEN ? '
                    .'WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.$confirmadoIds.') THEN COALESCE('.$tablePrefix.'leads.confirmed_at, ?) '
                    .'ELSE COALESCE('.$tablePrefix.'leads.closed_at, '.$tablePrefix.'leads.confirmed_at, ?) '
                    .'END'
                    .')) as avg_response_seconds',
                    [$now, $now, $now]
                )
                ->whereBetween('leads.created_at', [$startDate, $endDate])
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->groupBy('users.id', 'users.name');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            if ($this->ignoredUserNames) {
                $query->whereNotIn('users.name', $this->ignoredUserNames);
            }

            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            $avgByUser = [];

            foreach ($query->get() as $row) {
                $avgByUser[$row->user_name ?? '-'] = $row->avg_response_seconds ? (int) $row->avg_response_seconds : 0;
            }

            return $avgByUser;
        };

        $currentAverages = $buildAverages($this->startDate, $this->endDate);
        $previousAverages = $buildAverages($this->lastStartDate, $this->lastEndDate);

        $usersQuery = $this->userRepository->resetModel()->select('id', 'name');
        $usersQuery = $this->excludeIgnoredRoles($usersQuery, 'id');

        if ($this->ignoredUserNames) {
            $usersQuery->whereNotIn('name', $this->ignoredUserNames);
        }

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->orderBy('name')->pluck('name')->toArray();

        $data = [];
        $previousData = [];
        foreach ($users as $u) {
            $data[] = $currentAverages[$u] ?? 0;
            $previousData[] = $previousAverages[$u] ?? 0;
        }

        return [
            'labels'        => $users,
            'data'          => $data,
            'previous_data' => $previousData,
            'users'         => $users,
        ];
    }

    /**
     * Agrupa las etapas por su code normalizado. El pipeline es la ciudad, así que
     * cada etapa existe replicada una vez por ciudad ("No atendido" son los ids
     * 1, 11, 15, 23...): la columna de la tabla es el code, no el id. Se normaliza
     * el guion inicial porque el id 11 quedó grabado como `-no-atendido`.
     *
     * @return array<string, array>
     */
    protected function stageGroups(): array
    {
        $terminalIds = array_merge($this->wonStageIds, $this->lostStageIds, $this->noInterestStageIds);

        $groups = [];

        foreach ($this->stageRepository->resetModel()->get() as $stage) {
            $code = ltrim((string) $stage->code, '-');

            if (! isset($groups[$code])) {
                $groups[$code] = [
                    'code'       => $code,
                    'name'       => $stage->name,
                    'ids'        => [],
                    'sort_order' => $stage->sort_order ?? 0,
                    'terminal'   => true,
                ];
            }

            $groups[$code]['ids'][] = $stage->id;

            /**
             * El grupo es terminal solo si TODAS sus etapas lo son; basta una etapa
             * de la que se pueda salir para que la columna mida permanencia.
             */
            if (! in_array($stage->id, $terminalIds)) {
                $groups[$code]['terminal'] = false;
            }

            $groups[$code]['sort_order'] = min($groups[$code]['sort_order'], $stage->sort_order ?? 0);
        }

        uasort($groups, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $groups;
    }

    /**
     * Semáforo de "tiempo por etapa". A diferencia de `responseLight()`, el umbral
     * depende de la etapa: 4 h es exigible para contestar un lead pero absurdo para
     * entregar un pedido, y con un umbral único la columna saldría siempre roja.
     */
    protected function stageLight(?int $seconds, string $code): string
    {
        if (is_null($seconds)) {
            return 'gray';
        }

        $thresholds = $this->stageDwellThresholds[$code] ?? $this->stageDwellThresholds['default'];

        if ($seconds <= $thresholds['green']) {
            return 'green';
        }

        return $seconds <= $thresholds['red'] ? 'yellow' : 'red';
    }

    /**
     * "Tiempo por etapa", una fila por lead: cuánto permaneció ese lead en cada
     * etapa antes de que el asesor lo moviera, con una columna por etapa y el total
     * como suma de las columnas.
     *
     * La permanencia no sale de `leads` (que solo guarda la etapa actual) sino de
     * reconstruir la línea de tiempo desde el log de actividades, donde cada cambio
     * deja `additional` = {attribute: "Etapa", old: {value}, new: {value}}. Cada
     * estadía va desde que el lead entró a la etapa hasta el cambio siguiente,
     * calculado con LEAD() sobre el log ordenado por lead.
     *
     * Tres fuentes se unen para no perder tramos:
     *
     *   A. Estadías registradas: entra en `new.value`, sale con el cambio siguiente.
     *   B. Tramo anterior al primer cambio: el lead estuvo en `old.value` desde que
     *      se creó. En los leads del bot dura ~0 s (crea y marca "No atendido" en el
     *      mismo segundo), pero en los cargados a mano es el tramo real.
     *   C. Leads que nunca cambiaron de etapa: siguen donde nacieron, sin log.
     *
     * Reglas de negocio acordadas:
     *   - El reloj sigue corriendo contra "ahora" mientras el lead no salga de la
     *     etapa, para que un lead abandonado penalice en vez de desaparecer.
     *   - Las reaperturas SUMAN: si un lead vuelve a "No atendido", su segunda
     *     estadía se acumula a la primera. Un lead que además cambia de ciudad pasa
     *     por dos ids distintos de la misma etapa y también se consolida.
     *   - Se listan TODAS las etapas como columna. En las terminales (entregados /
     *     cancelados / sin interés) el número es la antigüedad desde que el lead
     *     entró, no una demora imputable al asesor -- no se sale de ellas -- así que
     *     van sin semáforo (`light` = "neutral") para no leerse como un atraso.
     *
     * "Ahora" se toma de la app (America/La_Paz) y no de NOW() de MySQL (UTC), que
     * va 4 h adelantado e inflaría todos los tramos abiertos.
     *
     * Viene paginado porque es una tabla de detalle. La permanencia se calcula solo
     * para los leads de la página, no para todo el período.
     */
    public function getStageDwellTimeByLead(int $perPage = 15): array
    {
        $pipelineId = is_numeric(request('pipeline_id')) ? (int) request('pipeline_id') : null;

        $encargadoId = is_numeric(request('encargado_id')) ? (int) request('encargado_id') : null;

        $page = max(1, (int) request('page', 1));

        $groups = $this->stageGroups();

        $query = $this->leadRepository
            ->resetModel()
            ->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->when($pipelineId, fn ($q) => $q->where('leads.lead_pipeline_id', $pipelineId))
            ->when($encargadoId, fn ($q) => $q->where('leads.user_id', $encargadoId));

        $this->applyUserScope($query);

        $total = (clone $query)->count('leads.id');

        /**
         * Acota la página al máximo disponible: si el filtro global reduce el
         * universo mientras el usuario está en una página alta, devolver la última
         * página real es mejor que una tabla vacía con "página 99 de 16".
         */
        $lastPage = max(1, (int) ceil($total / $perPage));

        $page = min($page, $lastPage);

        $leads = $query
            ->select(
                'leads.id as lead_id',
                'leads.title as lead_title',
                'leads.created_at as contact_at',
                'persons.name as person_name',
                'users.name as encargado_name',
                'lead_pipeline_stages.name as current_stage'
            )
            ->orderByDesc('leads.created_at')
            ->forPage($page, $perPage)
            ->get();

        $dwell = $this->stageDwellSeconds($leads->pluck('lead_id')->all(), $groups);

        $rows = $leads->map(function ($lead) use ($dwell, $groups) {
            $stages = [];

            $total = 0;

            foreach ($groups as $code => $group) {
                $seconds = $dwell[$lead->lead_id][$code] ?? null;

                $stages[$code] = [
                    'seconds' => $seconds,
                    'human'   => $this->humanizeSeconds($seconds),

                    /**
                     * En una etapa terminal el reloj mide antigüedad, no demora del
                     * asesor: se muestra el valor pero sin semáforo, para que no se
                     * lea como un atraso que alguien debería corregir.
                     */
                    'light'   => $group['terminal'] && ! is_null($seconds)
                        ? 'neutral'
                        : $this->stageLight($seconds, $code),
                ];

                $total += $seconds ?? 0;
            }

            return [
                'lead_id'        => $lead->lead_id,
                'person_name'    => $lead->person_name ?: ($lead->lead_title ?: '-'),
                'encargado_name' => $lead->encargado_name ?: '-',
                'current_stage'  => $lead->current_stage ?: '-',
                'contact_at'     => $lead->contact_at ? Carbon::parse($lead->contact_at)->format('d/m/Y H:i') : null,
                'stages'         => $stages,
                'total_seconds'  => $total,
                'total_human'    => $this->humanizeSeconds($total ?: null),
            ];
        })->values()->all();

        return [
            'columns' => array_values(array_map(function ($group) {
                $thresholds = $this->stageDwellThresholds[$group['code']] ?? $this->stageDwellThresholds['default'];

                return [
                    'code'     => $group['code'],
                    'name'     => $group['name'],
                    'terminal' => $group['terminal'],
                    'green'    => $group['terminal'] ? null : $this->humanizeSeconds($thresholds['green']),
                    'red'      => $group['terminal'] ? null : $this->humanizeSeconds($thresholds['red']),
                ];
            }, $groups)),
            'rows'       => $rows,
            'encargados' => $this->getManagers(),
            'meta'       => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
            ],
        ];
    }

    /**
     * Segundos de permanencia por lead y etapa para un puñado de leads concretos
     * (los de la página). Ver `getStageDwellTimeByLead()` para las reglas.
     *
     * @param  array<int>  $leadIds
     * @return array<int, array<string, int>>  [lead_id => [stage_code => seconds]]
     */
    protected function stageDwellSeconds(array $leadIds, array $groups): array
    {
        if (! $leadIds) {
            return [];
        }

        $prefix = DB::getTablePrefix();

        $ids = implode(',', array_map('intval', $leadIds));

        $now = now()->toDateTimeString();

        $stageAttribute = "JSON_UNQUOTE(JSON_EXTRACT(a.additional, '$.attribute')) = 'Etapa'";

        /**
         * El pipeline es la ciudad, así que cada etapa está replicada una vez por
         * ciudad. La columna de la tabla es el code, no el id: se traduce dentro del
         * SQL para que el GROUP BY trabaje ya sobre la columna final. Agrupar
         * después en PHP partía en dos la permanencia de un lead que cambia de
         * ciudad, en vez de sumarla.
         */
        $stageCase = 'CASE s.stage_id';

        foreach ($groups as $code => $group) {
            $stageCase .= ' WHEN '.implode(" THEN '{$code}' WHEN ", array_map('intval', $group['ids']))." THEN '{$code}'";
        }

        $stageCase .= ' END';

        $sql = "
            SELECT s.lead_id, {$stageCase} AS stage_code,
                   SUM(TIMESTAMPDIFF(SECOND, s.entered_at, COALESCE(s.left_at, ?))) AS seconds
            FROM (
                SELECT la.lead_id,
                       CAST(JSON_UNQUOTE(JSON_EXTRACT(a.additional, '$.new.value')) AS UNSIGNED) AS stage_id,
                       a.created_at AS entered_at,
                       LEAD(a.created_at) OVER (PARTITION BY la.lead_id ORDER BY a.created_at, a.id) AS left_at
                FROM {$prefix}activities a
                JOIN {$prefix}lead_activities la ON la.activity_id = a.id
                WHERE {$stageAttribute}
                  AND la.lead_id IN ({$ids})

                UNION ALL

                SELECT la.lead_id,
                       CAST(JSON_UNQUOTE(JSON_EXTRACT(a.additional, '$.old.value')) AS UNSIGNED) AS stage_id,
                       l.created_at AS entered_at,
                       a.created_at AS left_at
                FROM {$prefix}activities a
                JOIN {$prefix}lead_activities la ON la.activity_id = a.id
                JOIN {$prefix}leads l ON l.id = la.lead_id
                JOIN (
                    SELECT la2.lead_id, MIN(a2.id) AS first_activity_id
                    FROM {$prefix}activities a2
                    JOIN {$prefix}lead_activities la2 ON la2.activity_id = a2.id
                    WHERE JSON_UNQUOTE(JSON_EXTRACT(a2.additional, '$.attribute')) = 'Etapa'
                      AND la2.lead_id IN ({$ids})
                    GROUP BY la2.lead_id
                ) f ON f.first_activity_id = a.id

                UNION ALL

                SELECT l.id AS lead_id,
                       l.lead_pipeline_stage_id AS stage_id,
                       l.created_at AS entered_at,
                       NULL AS left_at
                FROM {$prefix}leads l
                WHERE l.id IN ({$ids})
                  AND NOT EXISTS (
                      SELECT 1
                      FROM {$prefix}activities a
                      JOIN {$prefix}lead_activities la ON la.activity_id = a.id
                      WHERE la.lead_id = l.id AND {$stageAttribute}
                  )
            ) s
            WHERE s.stage_id IS NOT NULL
            GROUP BY s.lead_id, stage_code
            HAVING stage_code IS NOT NULL
        ";

        $dwell = [];

        foreach (DB::select($sql, [$now]) as $row) {
            $dwell[$row->lead_id][$row->stage_code] = (int) $row->seconds;
        }

        return $dwell;
    }

    /**
     * "Tiempo en responder" detallado, una fila por lead/contacto. Reconstruye la
     * línea de tiempo desde el log de actividades (`activities` + `lead_activities`),
     * donde cada cambio de etapa deja `additional` = {attribute: "Etapa",
     * old: {value}, new: {value}} junto al `user_id` de quien lo hizo:
     *
     *   1. contacto  -> leads.created_at (llegada del lead)
     *   2. agente    -> 1ª actividad de un usuario con rol Sistema
     *                   (kohlberg@sofopolis.com) que deja la etapa en "No atendido"
     *   3. encargado -> 1ª salida de "No atendido" hacia cualquier otra etapa,
     *                   sin filtrar por rol (ver abajo)
     *
     * Dos detalles medidos contra la base que condicionan el filtrado:
     *
     *   - La transición NO se detecta por el nombre de la etapa sino por su ID
     *     ($notAttendedStageIds). El log guarda el nombre que la etapa tenía en
     *     ese momento, y las etapas se renombraron el 9-10/07/2026 ("Prospectos"
     *     -> "No atendido"): filtrar por nombre pierde todo lo anterior. El ID
     *     sobrevive a cualquier renombre futuro.
     *
     *   - El paso 3 no filtra por rol Encargado. En la operación real el bot
     *     (rol Sistema) registra la mayoría de los avances de etapa cuando el
     *     encargado atiende por WhatsApp; exigir rol Encargado marcaba como "sin
     *     atender" a ~46% de los leads que sí habían sido atendidos.
     *
     * Los leads cargados a mano por un encargado no tienen paso 2: ahí el tramo
     * queda en "sin dato" (gris) en vez de penalizar a nadie. Cuando el evento
     * todavía no ocurrió, el reloj corre contra "ahora", para que un lead sin
     * atender hace tres días se vea rojo y no vacío.
     *
     * Viene paginado porque es una tabla de detalle, no un gráfico.
     */
    public function getResponseTimeDetailByContact(int $perPage = 15): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;
        $encargadoId = is_numeric(request('encargado_id')) ? (int) request('encargado_id') : null;
        $page = max(1, (int) request('page', 1));

        /**
         * IDs de la etapa inicial "No atendido" (una por pipeline/ciudad). Se usan
         * en lugar del nombre porque el log guarda el nombre histórico.
         */
        $notAttendedIds = implode(',', $this->notAttendedStageIds ?: [0]);

        /**
         * Subconsulta correlacionada: primera marca de tiempo del log del lead que
         * cumple la transición de etapa (y el rol, si se exige uno). MIN() garantiza
         * que una reapertura posterior (volver a "No atendido" y salir otra vez) no
         * pise el tiempo de respuesta original. Los literales son constantes de este
         * método, no entrada del usuario.
         */
        $stageActivityAt = function (?string $roleName, string $extraConditions) use ($tablePrefix) {
            $roleJoin = $roleName
                ? ' JOIN '.$tablePrefix.'users au ON au.id = a.user_id'
                    .' JOIN '.$tablePrefix.'roles ar ON ar.id = au.role_id'
                : '';

            return '(SELECT MIN(a.created_at)'
                .' FROM '.$tablePrefix.'activities a'
                .' JOIN '.$tablePrefix.'lead_activities la ON la.activity_id = a.id'
                .$roleJoin
                .' WHERE la.lead_id = '.$tablePrefix.'leads.id'
                .($roleName ? " AND ar.name = '".$roleName."'" : '')
                .' AND a.additional IS NOT NULL'
                ." AND JSON_UNQUOTE(JSON_EXTRACT(a.additional, '$.attribute')) = 'Etapa'"
                .$extraConditions
                .')';
        };

        // El bot (rol Sistema) deja el lead en la etapa inicial "No atendido".
        $agentAt = $stageActivityAt(
            $this->systemRoleName,
            " AND JSON_EXTRACT(a.additional, '$.new.value') IN (".$notAttendedIds.')'
        );

        // Primera salida de "No atendido", la registre quien la registre.
        $encargadoAt = $stageActivityAt(
            null,
            " AND JSON_EXTRACT(a.additional, '$.old.value') IN (".$notAttendedIds.')'
            ." AND JSON_EXTRACT(a.additional, '$.new.value') NOT IN (".$notAttendedIds.')'
        );

        $query = $this->leadRepository
            ->resetModel()
            ->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->when($pipelineId, fn ($q) => $q->where('leads.lead_pipeline_id', $pipelineId))
            ->when($encargadoId, fn ($q) => $q->where('leads.user_id', $encargadoId));

        $this->applyUserScope($query);

        $total = (clone $query)->count('leads.id');

        /**
         * Acota la página al máximo disponible: si el filtro global reduce el
         * universo mientras el usuario está en una página alta, devolver la
         * última página real es mejor que una tabla vacía con "página 99 de 16".
         */
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = $query
            ->select(
                'leads.id as lead_id',
                'leads.title as lead_title',
                'leads.created_at as contact_at',
                'persons.name as person_name',
                'users.name as encargado_name'
            )
            ->selectRaw($agentAt.' as agent_at')
            ->selectRaw($encargadoAt.' as encargado_at')
            ->orderByDesc('leads.created_at')
            ->forPage($page, $perPage)
            ->get();

        $now = now();

        $items = $rows->map(function ($row) use ($now) {
            $contactAt = $row->contact_at ? Carbon::parse($row->contact_at) : null;
            $agentAt = $row->agent_at ? Carbon::parse($row->agent_at) : null;
            $encargadoAt = $row->encargado_at ? Carbon::parse($row->encargado_at) : null;

            $agentSeconds = $contactAt && $agentAt ? abs($agentAt->diffInSeconds($contactAt)) : null;

            $encargadoSeconds = $agentAt
                ? abs(($encargadoAt ?? $now)->diffInSeconds($agentAt))
                : null;

            $totalSeconds = $contactAt
                ? abs(($encargadoAt ?? $now)->diffInSeconds($contactAt))
                : null;

            return [
                'lead_id'           => $row->lead_id,
                'person_name'       => $row->person_name ?: ($row->lead_title ?: '-'),
                'encargado_name'    => $row->encargado_name ?: '-',
                'contact_at'        => $contactAt?->format('d/m/Y H:i'),
                'agent_at'          => $agentAt?->format('d/m/Y H:i'),
                'encargado_at'      => $encargadoAt?->format('d/m/Y H:i'),
                'agent_human'       => $this->humanizeSeconds($agentSeconds),
                'encargado_human'   => $this->humanizeSeconds($encargadoSeconds),
                'total_human'       => $this->humanizeSeconds($totalSeconds),
                'agent_light'       => $this->responseLight($agentSeconds),
                'encargado_light'   => $this->responseLight($encargadoSeconds),
                'total_light'       => $this->responseLight($totalSeconds),
                'is_pending'        => is_null($encargadoAt),
                'is_manual'         => is_null($agentAt),
            ];
        })->values()->all();

        return [
            'rows'       => $items,
            'encargados' => $this->getManagers(),
            'meta'       => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
            ],
        ];
    }

    /**
     * Semáforo de un tramo de respuesta.
     */
    protected function responseLight(?int $seconds): string
    {
        if (is_null($seconds)) {
            return 'gray';
        }

        if ($seconds <= self::GREEN_LIMIT_SECONDS) {
            return 'green';
        }

        if ($seconds <= self::RED_LIMIT_SECONDS) {
            return 'yellow';
        }

        return 'red';
    }

    /**
     * Duración legible y compacta para la tabla: "45 s", "12 m", "2 h 05 m", "3 d 14 h".
     */
    protected function humanizeSeconds(?int $seconds): ?string
    {
        if (is_null($seconds)) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.' s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' m';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600).' h '.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).' m';
        }

        return intdiv($seconds, 86400).' d '.intdiv($seconds % 86400, 3600).' h';
    }

    /**
     * Alcance de los tableros según el rol de quien mira:
     *
     *   - Roles de vista completa (Administrador, Supervisores, Sistema): null, o
     *     sea sin límite; ven todos los leads y todos los encargados del selector.
     *   - Encargado (y cualquier otro rol): solo su propio id.
     *
     * Se decide por rol y no por `users.view_permission` porque los encargados
     * están cargados como "global" para poder trabajar el kanban completo, y eso
     * dejaba el tablero abierto a todos. El bouncer sigue aplicándose encima como
     * segundo filtro, así que un usuario global-restringido nunca se amplía aquí.
     */
    protected function scopedUserIds(): ?array
    {
        $user = auth()->guard('user')->user();

        if (! $user) {
            return [0];
        }

        if (
            $user->role?->permission_type === 'all'
            || in_array($user->role?->name, $this->fullViewRoleNames, true)
        ) {
            return null;
        }

        return [$user->id];
    }

    /**
     * Aplica el alcance por rol y el del bouncer a una consulta de leads.
     */
    protected function applyUserScope($query, string $column = 'leads.user_id')
    {
        if (! is_null($userIds = $this->scopedUserIds())) {
            $query->whereIn($column, $userIds);
        }

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $query->whereIn($column, $userIds);
        }

        return $query;
    }

    /**
     * Usuarios con rol Encargado, para el selector de filtro de la tarjeta. Un
     * encargado se ve solo a sí mismo; el administrador ve a todos.
     */
    protected function getManagers(): array
    {
        $query = $this->userRepository
            ->resetModel()
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->where('roles.name', $this->managerRoleName);

        $this->applyUserScope($query, 'users.id');

        return $query
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }

    public function getVentasCountByBranches(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $attribute = $this->attributeRepository->findWhere([
            'code'        => 'sucursal',
            'entity_type' => 'persons',
        ])->first();

        $buildCounts = function ($startDate, $endDate) use ($tablePrefix, $pipelineId, $attribute) {
            $query = $this->leadRepository
                ->resetModel()
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                ->leftJoin('attribute_values as av', function ($join) use ($attribute) {
                    $join->on('persons.id', '=', 'av.entity_id')
                        ->where('av.entity_type', '=', 'persons')
                        ->when($attribute, fn ($j) => $j->where('av.attribute_id', '=', $attribute->id));
                })
                ->select(
                    DB::raw($tablePrefix.'av.integer_value as branch_id'),
                    DB::raw($tablePrefix.'av.text_value as branch_text'),
                    DB::raw('COUNT(DISTINCT CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.implode(',', $this->wonStageIds).') THEN '.$tablePrefix.'leads.id END) AS count')
                )
                ->whereBetween('leads.created_at', [$startDate, $endDate])
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->groupBy('branch_id', 'branch_text');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            $countsByName = [];

            foreach ($query->get() as $row) {
                $name = null;

                if ($attribute && $attribute->lookup_type && $row->branch_id) {
                    $entity = $this->attributeRepository->getLookUpEntity($attribute->lookup_type, $row->branch_id, ['id', 'name']);
                    $name = $entity?->name;
                }

                $name = $name ?? ($row->branch_text ?: 'Sin sucursal');

                $countsByName[$name] = ($countsByName[$name] ?? 0) + (int) $row->count;
            }

            return $countsByName;
        };

        $currentCounts = $buildCounts($this->startDate, $this->endDate);
        $previousCounts = $buildCounts($this->lastStartDate, $this->lastEndDate);

        $labels = array_unique(array_merge(array_keys($currentCounts), array_keys($previousCounts)));
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        $data = [];
        $previousData = [];
        foreach ($labels as $label) {
            $data[] = $currentCounts[$label] ?? 0;
            $previousData[] = $previousCounts[$label] ?? 0;
        }

        return [
            'labels'        => $labels,
            'data'          => $data,
            'previous_data' => $previousData,
        ];
    }

    public function getLeadsCountByPipelines(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $pipelines = $this->pipelineRepository->all();

        $buildCounts = function ($startDate, $endDate) use ($tablePrefix, $pipelineId) {
            $query = $this->leadRepository
                ->resetModel()
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->select(
                    'leads.lead_pipeline_id as pipeline_id',
                    DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) AS count')
                )
                // "Prospectos" = leads abiertos (Prospecto + Confirmado) por fecha de evento.
                ->whereIn('leads.lead_pipeline_stage_id', $this->openStageIds())
                ->whereRaw('('.$this->stageEventDateExpr().') BETWEEN ? AND ?', [$startDate, $endDate])
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->groupBy('leads.lead_pipeline_id');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            return $query->get()->keyBy('pipeline_id');
        };

        $currentResults = $buildCounts($this->startDate, $this->endDate);
        $previousResults = $buildCounts($this->lastStartDate, $this->lastEndDate);

        $labels = [];
        $data = [];
        $previousData = [];

        foreach ($pipelines as $pipeline) {
            $labels[] = $pipeline->name;
            $data[] = (int) ($currentResults->get($pipeline->id)->count ?? 0);
            $previousData[] = (int) ($previousResults->get($pipeline->id)->count ?? 0);
        }

        return [
            'labels'        => $labels,
            'data'          => $data,
            'previous_data' => $previousData,
        ];
    }

    /**
     * Leads "No atendidos" (etapa inicial "No atendido", $notAttendedStageIds)
     * agrupados por ciudad - cada pipeline representa una ciudad. Alimenta el
     * card doughnut "No atendidos por Ciudad". Cuenta por created_at y respeta
     * el filtro de pipeline, los roles ignorados y el ACL, igual que el resto
     * de métricas. Solo incluye ciudades con al menos un lead no atendido.
     */
    public function getNotAttendedLeadsCountByPipelines(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $pipelines = $this->pipelineRepository->all();

        $query = $this->leadRepository
            ->resetModel()
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->select(
                'leads.lead_pipeline_id as pipeline_id',
                DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) AS count')
            )
            ->whereIn('leads.lead_pipeline_stage_id', $this->notAttendedStageIds ?: [0])
            ->whereRaw('('.$this->stageEventDateExpr().') BETWEEN ? AND ?', [$this->startDate, $this->endDate])
            ->when($pipelineId, function ($q) use ($pipelineId) {
                $q->where('leads.lead_pipeline_id', $pipelineId);
            })
            ->groupBy('leads.lead_pipeline_id');

        $query = $this->excludeIgnoredRoles($query, 'users.id');

        if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
            $query->whereIn('users.id', $userIds);
        }

        $results = $query->get()->keyBy('pipeline_id');

        $labels = [];
        $data = [];

        foreach ($pipelines as $pipeline) {
            $count = (int) ($results->get($pipeline->id)->count ?? 0);

            if ($count === 0) {
                continue;
            }

            $labels[] = $pipeline->name;
            $data[] = $count;
        }

        return [
            'labels' => $labels,
            'data'   => $data,
        ];
    }

    public function getVentasCountByPipelines(): array
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        $pipelines = $this->pipelineRepository->all();

        $buildCounts = function ($startDate, $endDate) use ($tablePrefix, $pipelineId) {
            $query = $this->leadRepository
                ->resetModel()
                ->leftJoin('users', 'leads.user_id', '=', 'users.id')
                ->select(
                    'leads.lead_pipeline_id as pipeline_id',
                    DB::raw('COUNT(DISTINCT CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.implode(',', $this->wonStageIds).') THEN '.$tablePrefix.'leads.id END) AS count')
                )
                ->whereBetween('leads.created_at', [$startDate, $endDate])
                ->when($pipelineId, function ($q) use ($pipelineId) {
                    $q->where('leads.lead_pipeline_id', $pipelineId);
                })
                ->groupBy('leads.lead_pipeline_id');

            $query = $this->excludeIgnoredRoles($query, 'users.id');

            if (function_exists('bouncer') && ! is_null($userIds = bouncer()->getAuthorizedUserIds())) {
                $query->whereIn('users.id', $userIds);
            }

            return $query->get()->keyBy('pipeline_id');
        };

        $currentResults = $buildCounts($this->startDate, $this->endDate);
        $previousResults = $buildCounts($this->lastStartDate, $this->lastEndDate);

        $labels = [];
        $data = [];
        $previousData = [];

        foreach ($pipelines as $pipeline) {
            $labels[] = $pipeline->name;
            $data[] = (int) ($currentResults->get($pipeline->id)->count ?? 0);
            $previousData[] = (int) ($previousResults->get($pipeline->id)->count ?? 0);
        }

        return [
            'labels'        => $labels,
            'data'          => $data,
            'previous_data' => $previousData,
        ];
    }

    /**
     * Determine the appropriate period based on date range
     *
     * @param  string  $period
     */
    protected function determinePeriod($period = 'auto'): string
    {
        if ($period !== 'auto') {
            return $period;
        }

        $diffInDays = $this->startDate->diffInDays($this->endDate);
        $diffInMonths = $this->startDate->diffInMonths($this->endDate);
        $diffInYears = $this->startDate->diffInYears($this->endDate);

        if ($diffInYears > 3) {
            return 'year';
        } elseif ($diffInMonths > 6) {
            return 'month';
        } elseif ($diffInDays > 60) {
            return 'week';
        } else {
            return 'day';
        }
    }

    /**
     * Retrieves total leads and their progress.
     */
    public function getTotalLeadsProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalLeads($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalLeads($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total leads by date
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalLeads($startDate, $endDate): int
    {
        // "Prospectos" = leads currently open (Prospecto + Confirmado), by event date.
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('lead_pipeline_id', $pipelineId);
            })
            ->whereIn('lead_pipeline_stage_id', $this->openStageIds())
            ->whereRaw('('.$this->stageEventDateExpr().') BETWEEN ? AND ?', [$startDate, $endDate])
            ->count();
    }

    /**
     * Retrieves average leads per day and their progress.
     */
    public function getAverageLeadsPerDayProgress(): array
    {
        return [
            'previous' => $previous = $this->getAverageLeadsPerDay($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getAverageLeadsPerDay($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves average leads per day
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getAverageLeadsPerDay($startDate, $endDate): float
    {
        /**
         * Días INCLUSIVOS del período (08→09 = 2 días), igual que getDaysInterval()
         * y que el número de buckets que usa "Evolución". Antes se dividía por
         * diffInDays() (= días−1), por lo que en un rango de 2 días el "promedio"
         * salía igual al total (9/1 = 9) en vez de 9/2 = 4.5.
         */
        $days = $startDate->diffInDays($endDate) + 1;

        return $this->getTotalLeads($startDate, $endDate) / $days;
    }

    /**
     * Retrieves total lead value and their progress.
     */
    public function getTotalLeadValueProgress(): array
    {
        return [
            'previous'        => $previous = $this->getTotalLeadValue($this->lastStartDate, $this->lastEndDate),
            'current'         => $current = $this->getTotalLeadValue($this->startDate, $this->endDate),
            'formatted_total' => core()->formatBasePrice($current),
            'progress'        => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total lead value
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalLeadValue($startDate, $endDate): float
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('lead_pipeline_id', $pipelineId);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('lead_value');
    }

    /**
     * Retrieves average lead value and their progress.
     */
    public function getAverageLeadValueProgress(): array
    {
        return [
            'previous'        => $previous = $this->getAverageLeadValue($this->lastStartDate, $this->lastEndDate),
            'current'         => $current = $this->getAverageLeadValue($this->startDate, $this->endDate),
            'formatted_total' => core()->formatBasePrice($current),
            'progress'        => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves average lead value
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getAverageLeadValue($startDate, $endDate): float
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('lead_pipeline_id', $pipelineId);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->avg('lead_value') ?? 0;
    }

    /**
     * Retrieves total won lead value and their progress.
     */
    public function getTotalWonLeadValueProgress(): array
    {
        return [
            'previous'        => $previous = $this->getTotalWonLeadValue($this->lastStartDate, $this->lastEndDate),
            'current'         => $current = $this->getTotalWonLeadValue($this->startDate, $this->endDate),
            'formatted_total' => core()->formatBasePrice($current),
            'progress'        => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves average won lead value
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @return array
     */
    public function getTotalWonLeadValue($startDate, $endDate): ?float
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('lead_pipeline_id', $pipelineId);
            })
            ->whereIn('lead_pipeline_stage_id', $this->wonStageIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('lead_value');
    }

    /**
     * Retrieves average lost lead value and their progress.
     */
    public function getTotalLostLeadValueProgress(): array
    {
        return [
            'previous'        => $previous = $this->getTotalLostLeadValue($this->lastStartDate, $this->lastEndDate),
            'current'         => $current = $this->getTotalLostLeadValue($this->startDate, $this->endDate),
            'formatted_total' => core()->formatBasePrice($current),
            'progress'        => $this->getPercentageChange($previous, $current),
        ];
    }

    public function getTotalVentasCountProgress(): array
    {
        return [
            'previous' => $previous = $this->getVentasCount($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getVentasCount($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    public function getVentasCount($startDate, $endDate): int
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('lead_pipeline_id', $pipelineId);
            })
            ->whereIn('lead_pipeline_stage_id', $this->wonStageIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count('id');
    }

    /**
     * Retrieves average lost lead value
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @return array
     */
    public function getTotalLostLeadValue($startDate, $endDate): ?float
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('lead_pipeline_id', $pipelineId);
            })
            ->whereIn('lead_pipeline_stage_id', $this->lostStageIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('lead_value');
    }

    /**
     * Retrieves total lead value by sources.
     */
    public function getTotalWonLeadValueBySources()
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, function ($q, $userId) {
                $q->where('leads.user_id', $userId);
            })
            ->when(is_numeric(request('organization_id')) ? request('organization_id') : null, function ($q, $organizationId) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', $organizationId);
            })
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('leads.lead_pipeline_id', $pipelineId);
            })
            ->select(
                'lead_sources.name',
                DB::raw('SUM(lead_value) as total')
            )
            ->leftJoin('lead_sources', 'leads.lead_source_id', '=', 'lead_sources.id')
            ->whereIn('lead_pipeline_stage_id', $this->wonStageIds)
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->groupBy('lead_source_id')
            ->get();
    }

    /**
     * Retrieves total lead value by types.
     */
    public function getTotalWonLeadValueByTypes()
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, function ($q, $userId) {
                $q->where('leads.user_id', $userId);
            })
            ->when(is_numeric(request('organization_id')) ? request('organization_id') : null, function ($q, $organizationId) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', $organizationId);
            })
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('leads.lead_pipeline_id', $pipelineId);
            })
            ->select(
                'lead_types.name',
                DB::raw('SUM(lead_value) as total')
            )
            ->leftJoin('lead_types', 'leads.lead_type_id', '=', 'lead_types.id')
            ->whereIn('lead_pipeline_stage_id', $this->wonStageIds)
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->groupBy('lead_type_id')
            ->get();
    }

    /**
     * Retrieves open leads by states.
     */
    public function getOpenLeadsByStates()
    {
        return $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, function ($q, $userId) {
                $q->where('leads.user_id', $userId);
            })
            ->when(is_numeric(request('organization_id')) ? request('organization_id') : null, function ($q, $organizationId) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', $organizationId);
            })
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('leads.lead_pipeline_id', $pipelineId);
            })
            ->select(
                'lead_pipeline_stages.name',
                DB::raw('COUNT(lead_value) as total')
            )
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->whereNotIn('lead_pipeline_stage_id', $this->wonStageIds)
            ->whereNotIn('lead_pipeline_stage_id', $this->lostStageIds)
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->groupBy('lead_pipeline_stage_id')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Retrieves leads by stages for the default pipeline, keeping stage order fixed
     * and ensuring penultimate is Won and last is Lost.
     */
    public function getOpenLeadsByStatesFixed()
    {
        $tablePrefix = DB::getTablePrefix();
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;

        // 1. Obtener las etapas de referencia para el orden y nombres
        if ($pipelineId) {
            $referencePipeline = $this->pipelineRepository->find($pipelineId);
        } else {
            $referencePipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        if (! $referencePipeline) {
            return collect();
        }

        $referenceStages = $referencePipeline->stages()->orderBy('sort_order')->get();

        // 2. Sumatoria de leads por nombre de etapa dentro del rango de fechas seleccionado
        $query = $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, function ($q, $userId) {
                $q->where('leads.user_id', $userId);
            })
            ->when(is_numeric(request('organization_id')) ? request('organization_id') : null, function ($q, $organizationId) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', $organizationId);
            })
            ->when($pipelineId, function ($q) use ($pipelineId) {
                $q->where('leads.lead_pipeline_id', $pipelineId);
            })
            ->whereRaw('('.$this->stageEventDateExpr().') BETWEEN ? AND ?', [$this->startDate, $this->endDate])
            ->select(
                'lead_pipeline_stages.name as stage_name',
                DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) as total')
            )
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->groupBy('lead_pipeline_stages.name');

        $results = $query->get()->keyBy('stage_name');

        // 3. Mapear los resultados a las etapas de referencia
        return $referenceStages->map(function ($stage) use ($results) {
            $stat = $results->get($stage->name);

            return (object) [
                'name'  => $stage->name,
                'total' => $stat ? (int) $stat->total : 0,
            ];
        });
    }

    /**
     * Returns total leads grouped by stages for default pipeline, ordered by pipeline stages,
     * with Won as penultimate and Lost as last.
     */
    public function getTotalLeadsByStages(): array
    {
        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;
        $pipeline = $pipelineId ? $this->pipelineRepository->find($pipelineId) : $this->pipelineRepository->getDefaultPipeline();
        $tablePrefix = DB::getTablePrefix();

        $stages = $pipeline->stages()->get();

        $wonCodes = ['won', 'pedido-entregado'];
        $lostCodes = ['lost', 'pedidos-cancelado'];

        $normalStages = $stages->filter(fn ($s) => ! in_array($s->code, array_merge($wonCodes, $lostCodes)))->values();
        $wonStage = $stages->first(fn ($s) => in_array($s->code, $wonCodes));
        $lostStage = $stages->first(fn ($s) => in_array($s->code, $lostCodes));

        $orderedStages = $normalStages;
        if ($wonStage) {
            $orderedStages = $orderedStages->push($wonStage);
        }
        // Excluir la etapa Perdido de la vista "Etapas por fecha"

        $counts = $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, function ($q, $userId) {
                $q->where('leads.user_id', $userId);
            })
            ->when(is_numeric(request('organization_id')) ? request('organization_id') : null, function ($q, $organizationId) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', $organizationId);
            })
            ->select(
                'lead_pipeline_stages.id',
                'lead_pipeline_stages.name',
                DB::raw('COUNT('.$tablePrefix.'leads.id) as total')
            )
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->where('leads.lead_pipeline_id', $pipeline->id)
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->whereNotIn('leads.lead_pipeline_stage_id', $this->lostStageIds)
            ->groupBy('lead_pipeline_stages.id', 'lead_pipeline_stages.name')
            ->get()
            ->keyBy('id');

        return $orderedStages->map(function ($stage) use ($counts) {
            $stat = $counts->get($stage->id);

            return [
                'name'  => $stage->name,
                'total' => $stat ? (int) $stat->total : 0,
            ];
        })->toArray();
    }

    /**
     * Returns leads counts by stages over time for default pipeline.
     */
    public function getTotalLeadsByStagesOverTime(): array
    {
        $period = $this->determinePeriod('auto');
        $tablePrefix = DB::getTablePrefix();

        $pipelineId = is_numeric(request('pipeline_id')) ? request('pipeline_id') : null;
        $pipeline = $pipelineId ? $this->pipelineRepository->find($pipelineId) : $this->pipelineRepository->getDefaultPipeline();

        $stages = $pipeline->stages()->get();

        $wonCodes = ['won', 'pedido-entregado'];
        $lostCodes = ['lost', 'pedidos-cancelado'];

        $normalStages = $stages->filter(fn ($s) => ! in_array($s->code, array_merge($wonCodes, $lostCodes)))->values();
        $wonStage = $stages->first(fn ($s) => in_array($s->code, $wonCodes));
        $lostStage = $stages->first(fn ($s) => in_array($s->code, $lostCodes));

        $orderedStages = $normalStages;
        if ($wonStage) {
            $orderedStages = $orderedStages->push($wonStage);
        }
        if ($lostStage) {
            $orderedStages = $orderedStages->push($lostStage);
        }

        $intervals = $this->generateTimeIntervals($this->startDate, $this->endDate, $period);

        // Raw expression: qualify with the real (prefixed) table name, otherwise
        // MySQL cannot resolve `leads.created_at` when a table prefix is set.
        $groupColumn = $this->getGroupColumn($tablePrefix.'leads.created_at', $period);

        $results = $this->leadRepository
            ->resetModel()
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, function ($q, $userId) {
                $q->where('leads.user_id', $userId);
            })
            ->when(is_numeric(request('organization_id')) ? request('organization_id') : null, function ($q, $organizationId) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', $organizationId);
            })
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->where('leads.lead_pipeline_id', $pipeline->id)
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->select(
                DB::raw("$groupColumn AS date"),
                'lead_pipeline_stages.id as stage_id',
                DB::raw('COUNT('.$tablePrefix.'leads.id) as count')
            )
            ->groupBy(DB::raw($groupColumn), 'lead_pipeline_stages.id')
            ->orderBy(DB::raw($groupColumn))
            ->get();

        $byDateStage = [];
        foreach ($results as $row) {
            $dateKey = $row->date;
            $stageId = (int) $row->stage_id;
            $byDateStage[$dateKey][$stageId] = (int) $row->count;
        }

        $labels = array_map(fn ($i) => $i['label'], $intervals);

        $datasets = [];
        foreach ($orderedStages as $stage) {
            $data = [];
            foreach ($intervals as $interval) {
                $dateKey = $interval['key'];
                $data[] = (int) ($byDateStage[$dateKey][$stage->id] ?? 0);
            }

            $datasets[] = [
                'label' => $stage->name,
                'data'  => $data,
            ];
        }

        $total = 0;
        foreach ($datasets as $ds) {
            foreach ($ds['data'] as $v) {
                $total += $v;
            }
        }

        return [
            'labels'   => $labels,
            'datasets' => $datasets,
            'total'    => $total,
        ];
    }

    /**
     * Returns over time stats.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @param  string  $valueColumn
     * @param  string  $dateColumn
     * @param  string  $period
     */
    public function getOverTimeStats($startDate, $endDate, $valueColumn, $dateColumn = 'created_at', $period = 'auto'): array
    {
        $period = $this->determinePeriod($period);

        $intervals = $this->generateTimeIntervals($startDate, $endDate, $period);

        $groupColumn = $this->getGroupColumn($dateColumn, $period);

        $query = $this->leadRepository
            ->resetModel()
            ->select(
                DB::raw("$groupColumn AS date"),
                DB::raw('COUNT(DISTINCT id) AS count'),
                DB::raw('SUM('.\DB::getTablePrefix()."$valueColumn) AS total")
            )
            ->when(is_numeric(request('pipeline_id')) ? request('pipeline_id') : null, function ($q, $pipelineId) {
                $q->where('lead_pipeline_id', $pipelineId);
            })
            ->whereIn('lead_pipeline_stage_id', $this->stageIds)
            // whereRaw (not whereBetween) so $dateColumn may also be a raw SQL
            // expression, e.g. the per-stage event date used for "open" leads.
            ->whereRaw("($dateColumn) BETWEEN ? AND ?", [$startDate, $endDate])
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
                'total' => $result ? (float) $result->total : 0,
            ];
        }

        return $stats;
    }

    /**
     * Generate time intervals based on period
     */
    protected function generateTimeIntervals(Carbon $startDate, Carbon $endDate, string $period): array
    {
        $intervals = [];

        // Anchor the first bucket to the period boundary so the bucket keys align
        // with the SQL grouping AND the bucket containing the end date is never
        // dropped (a mid-period start date used to skip the last week/month).
        $current = match ($period) {
            'week'  => $startDate->copy()->startOfWeek(),
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

    /**
     * Get the SQL group column based on period
     */
    protected function getGroupColumn(string $dateColumn, string $period): string
    {
        switch ($period) {
            case 'day':
                return "DATE($dateColumn)";
            case 'week':
                return "DATE_FORMAT($dateColumn, '%Y-%u')";
            case 'month':
                return "DATE_FORMAT($dateColumn, '%Y-%m')";
            case 'year':
                return "YEAR($dateColumn)";
            default:
                return "DATE($dateColumn)";
        }
    }

    /**
     * Format date for grouping key
     */
    protected function formatDateForGrouping(Carbon $date, string $period): string
    {
        switch ($period) {
            case 'day':
                return $date->format('Y-m-d');
            case 'week':
                return $date->format('Y-W');
            case 'month':
                return $date->format('Y-m');
            case 'year':
                return $date->format('Y');
            default:
                return $date->format('Y-m-d');
        }
    }

    /**
     * Format date for display label
     */
    protected function formatDateForLabel(Carbon $date, string $period): string
    {
        switch ($period) {
            case 'day':
                return $date->format('M d');
            case 'week':
                return 'Semana '.$date->format('W, Y');
            case 'month':
                return $date->format('M Y');
            case 'year':
                return $date->format('Y');
            default:
                return $date->format('M d');
        }
    }

    /**
     * Calculates the average time it takes for a receptionist to move a patient between Kanban workflow stages.
     *
     * @param  array  $filters  Optional filters for the calculation.
     *                          - `start_date` (string): Start date for the analysis (YYYY-MM-DD HH:II:SS).
     *                          - `end_date` (string): End date for the analysis (YYYY-MM-DD HH:II:SS).
     *                          - `user_id` (int): ID of a specific receptionist to filter by.
     *                          - `stage_id` (int): ID of a specific stage to filter by.
     * @return array An array containing the average time in seconds and a formatted time string (HH:MM).
     *               - `average_time` (float): The average time in seconds.
     *               - `formatted_time` (string): The average time formatted as HH:MM.
     *
     * @throws \Exception If there is an error during the calculation.
     *
     * @example
     * // Get the average time for all receptionists in the last 30 days
     * $filters = [
     *     'start_date' => date('Y-m-d H:i:s', strtotime('-30 days')),
     *     'end_date'   => date('Y-m-d H:i:s'),
     * ];
     * $stats = $leadReporter->getAverageStageChangeTime($filters);
     * echo "Average stage change time: " . $stats['formatted_time'];
     */
    public function getAverageStageChangeTime(array $filters = []): array
    {
        $tablePrefix = DB::getTablePrefix();

        $query = DB::table('lead_activities')
            ->join('leads', 'lead_activities.lead_id', '=', 'leads.id')
            ->join('users', 'lead_activities.user_id', '=', 'users.id')
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->where('roles.name', 'Recepcionista')
            ->select(
                'leads.id as lead_id',
                'lead_activities.created_at as stage_change_time'
            )
            ->orderBy('leads.id')
            ->orderBy('lead_activities.created_at');

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('lead_activities.created_at', [$filters['start_date'], $filters['end_date']]);
        }

        if (isset($filters['user_id'])) {
            $query->where('lead_activities.user_id', $filters['user_id']);
        }

        if (isset($filters['stage_id'])) {
            $query->where('leads.lead_pipeline_stage_id', $filters['stage_id']);
        }

        $stageChanges = $query->get();

        $timeDifferences = [];
        $lastLeadId = null;
        $lastStageChangeTime = null;

        foreach ($stageChanges as $change) {
            if ($lastLeadId === $change->lead_id) {
                $timeDifferences[] = strtotime($change->stage_change_time) - strtotime($lastStageChangeTime);
            }

            $lastLeadId = $change->lead_id;
            $lastStageChangeTime = $change->stage_change_time;
        }

        if (empty($timeDifferences)) {
            return [
                'average_time'   => 0,
                'formatted_time' => '00:00',
            ];
        }

        $averageTimeInSeconds = array_sum($timeDifferences) / count($timeDifferences);

        return [
            'average_time'   => $averageTimeInSeconds,
            'formatted_time' => $this->formatTime($averageTimeInSeconds),
        ];
    }

    private function formatTime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
