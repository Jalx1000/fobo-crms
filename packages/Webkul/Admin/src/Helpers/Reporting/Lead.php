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
     * The consulta stage ids.
     */
    protected array $consultaStageIds;

    /**
     * Ids de usuarios con rol Administrador, excluidos de todo el tablero.
     */
    protected array $excludedUserIds;

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

        // "Ganado" en esta clínica = etapa "Paciente (concretado)" (code paciente-concretado).
        $this->wonStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'won')
            ->orWhere('code', 'paciente-concretado')
            ->orWhereRaw("LOWER(name) LIKE '%won%'")
            ->orWhereRaw("LOWER(name) LIKE '%ganado%'")
            ->orWhereRaw("LOWER(name) LIKE '%concretad%'")
            ->pluck('id')
            ->toArray();

        // "Perdido" en esta clínica = etapa "Cancelado" (code cancelado).
        $this->lostStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'lost')
            ->orWhere('code', 'cancelado')
            ->orWhereRaw("LOWER(name) LIKE '%lost%'")
            ->orWhereRaw("LOWER(name) LIKE '%perdido%'")
            ->orWhereRaw("LOWER(name) LIKE '%cancelad%'")
            ->pluck('id')
            ->toArray();

        // "Consultas" = primera etapa del pipeline (code consultas).
        $this->consultaStageIds = $this->stageRepository
            ->resetModel()
            ->where('code', 'consultas')
            ->orWhereRaw("LOWER(name) LIKE '%consulta%'")
            ->pluck('id')
            ->toArray();

        // Excluir del tablero a TODOS los usuarios con rol Administrador
        // (cubre "Administrator"/"Administrador"). Antes solo se excluía un email fijo.
        $this->excludedUserIds = $this->userRepository
            ->resetModel()
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->where('roles.name', 'like', 'Admin%')
            ->pluck('users.id')
            ->toArray();

        parent::__construct();
    }

    /**
     * Builds the data for the "Evolución" card: the current period vs the real
     * previous period, point-to-point comparable.
     *
     * Reuses getOverTimeStats() (same counting rule as the bar chart) for both
     * ranges and aligns the previous series to the current one by index, so the
     * frontend can draw two overlapping lines (solid = actual, dotted = anterior).
     *
     * @param  string  $period
     */
    public function getLeadsEvolution($period = 'auto'): array
    {
        // Same universe as "Total de Pacientes": every stage except the lost/cancelled ones.
        $this->stageIds = array_values(array_diff($this->allStageIds, $this->lostStageIds));

        $period = $this->determinePeriod($period);

        $current = $this->getOverTimeStats($this->startDate, $this->endDate, 'leads.id', 'created_at', $period);
        $previous = $this->getOverTimeStats($this->lastStartDate, $this->lastEndDate, 'leads.id', 'created_at', $period);

        $currentCounts = array_column($current, 'count');
        $previousCounts = array_column($previous, 'count');

        // Align the previous series to the current length (by index) so both lines
        // share the same X axis; pad with 0 / truncate the surplus.
        $size = count($currentCounts);
        $previousCounts = array_slice(array_pad($previousCounts, $size, 0), 0, $size);

        return [
            'labels'         => array_column($current, 'label'),
            'current'        => $currentCounts,
            'previous'       => $previousCounts,
            'total'          => array_sum($currentCounts),
            'previous_total' => array_sum($previousCounts),
            'current_range'  => $this->startDate->format('d M Y').' - '.$this->endDate->format('d M Y'),
            'previous_range' => $this->lastStartDate->format('d M Y').' - '.$this->lastEndDate->format('d M Y'),
            'period'         => $period,
            'progress'       => $this->getPercentageChange(array_sum($previousCounts), array_sum($currentCounts)),
        ];
    }

    public function getVendedoresStats($limit = null)
    {
        $tablePrefix = DB::getTablePrefix();

        $items = $this->leadRepository
            ->resetModel()
            ->when(request('user_id'), function ($q) {
                $q->where('leads.user_id', request('user_id'));
            })
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
            })
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->whereNotIn('users.id', $this->excludedUserIds)
            ->leftJoin('lead_activities', 'leads.id', '=', 'lead_activities.lead_id')
            ->leftJoin('activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->select(
                'users.id as user_id',
                'users.name as vendedor_name'
            )
            ->addSelect(DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) as total_leads'))
            ->addSelect(DB::raw('SUM(CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.(implode(',', $this->wonStageIds) ?: '0').') THEN 1 ELSE 0 END) as sales_count'))
            ->addSelect(DB::raw('SUM(CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.(implode(',', $this->wonStageIds) ?: '0').') THEN '.$tablePrefix.'leads.lead_value ELSE 0 END) as total_sales_amount'))
            ->addSelect(DB::raw('AVG(CASE WHEN '.$tablePrefix.'activities.created_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, '.$tablePrefix.'leads.created_at, '.$tablePrefix.'activities.created_at) END) as average_response_time_seconds'))
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->groupBy('users.id', 'users.name')
            ->orderBy('total_sales_amount', 'DESC')
            ->limit($limit)
            ->get();

        $items = $items->map(function ($item) {
            return [
                'user_id'                       => $item->user_id,
                'name'                          => $item->vendedor_name,
                'total_leads'                   => (int) $item->total_leads,
                'sales_count'                   => (int) $item->sales_count,
                'total_sales_amount'            => (float) $item->total_sales_amount,
                'formatted_total_sales_amount'  => core()->formatBasePrice($item->total_sales_amount),
                'average_response_time_seconds' => $item->average_response_time_seconds ? (int) $item->average_response_time_seconds : null,
            ];
        });

        return $items;
    }

    public function getLeadsCountByUsers(): array
    {
        $tablePrefix = DB::getTablePrefix();

        $query = $this->leadRepository
            ->resetModel()
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(DISTINCT '.$tablePrefix.'leads.id) AS count')
            )
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->whereNotIn('leads.lead_pipeline_stage_id', $this->lostStageIds)
            ->whereNotIn('users.id', $this->excludedUserIds)
            ->groupBy('users.id', 'users.name')
            ->orderBy('user_name');

        $results = $query->get();

        $countsByUser = [];

        foreach ($results as $row) {
            $countsByUser[$row->user_name ?? '-'] = (int) $row->count;
        }

        $usersQuery = $this->userRepository->resetModel()->select('name')->whereNotIn('id', $this->excludedUserIds);

        if (function_exists('bouncer') && ($userIds = bouncer()->getAuthorizedUserIds())) {
            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->pluck('name')->toArray();

        $labels = $users;
        $data = [];
        foreach ($users as $u) {
            $data[] = $countsByUser[$u] ?? 0;
        }

        return [
            'labels' => $labels,
            'data'   => $data,
            'users'  => $users,
        ];
    }

    public function getLeadsCountByBranches(): array
    {
        $tablePrefix = DB::getTablePrefix();

        $attribute = $this->attributeRepository->findWhere([
            'code'        => 'sucursal',
            'entity_type' => 'persons',
        ])->first();

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
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->whereNotIn('leads.lead_pipeline_stage_id', $this->lostStageIds)
            ->groupBy('branch_id', 'branch_text');

        if (function_exists('bouncer') && ($userIds = bouncer()->getAuthorizedUserIds())) {
            $query->whereIn('users.id', $userIds);
        }

        $results = $query->get();

        $countsByName = [];

        foreach ($results as $row) {
            $name = null;

            if ($attribute && $attribute->lookup_type && $row->branch_id) {
                $entity = $this->attributeRepository->getLookUpEntity($attribute->lookup_type, $row->branch_id, ['id', 'name']);
                $name = $entity?->name;
            }

            $name = $name ?? ($row->branch_text ?: 'Sin sucursal');

            $countsByName[$name] = ($countsByName[$name] ?? 0) + (int) $row->count;
        }

        $labels = array_keys($countsByName);
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        $data = [];
        foreach ($labels as $label) {
            $data[] = $countsByName[$label];
        }

        return [
            'labels' => $labels,
            'data'   => $data,
        ];
    }

    public function getAverageResponseTimeByUsers(): array
    {
        $tablePrefix = DB::getTablePrefix();

        $query = $this->leadRepository
            ->resetModel()
            ->leftJoin('users', 'leads.user_id', '=', 'users.id')
            ->leftJoin('lead_activities', 'leads.id', '=', 'lead_activities.lead_id')
            ->leftJoin('activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('AVG(CASE WHEN '.$tablePrefix.'activities.created_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, '.$tablePrefix.'leads.created_at, '.$tablePrefix.'activities.created_at) END) as avg_response_seconds')
            )
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->whereNotIn('users.id', $this->excludedUserIds)
            ->groupBy('users.id', 'users.name')
            ->orderBy('user_name');

        $results = $query->get();

        $avgByUser = [];

        foreach ($results as $row) {
            $avgByUser[$row->user_name ?? '-'] = $row->avg_response_seconds ? (int) $row->avg_response_seconds : 0;
        }

        $usersQuery = $this->userRepository->resetModel()->select('name')->whereNotIn('id', $this->excludedUserIds);

        if (function_exists('bouncer') && ($userIds = bouncer()->getAuthorizedUserIds())) {
            $usersQuery->whereIn('id', $userIds);
        }

        $users = $usersQuery->pluck('name')->toArray();

        $labels = $users;
        $data = [];
        foreach ($users as $u) {
            $data[] = $avgByUser[$u] ?? 0;
        }

        return [
            'labels' => $labels,
            'data'   => $data,
            'users'  => $users,
        ];
    }

    public function getVentasCountByBranches(): array
    {
        $tablePrefix = DB::getTablePrefix();

        $attribute = $this->attributeRepository->findWhere([
            'code'        => 'sucursal',
            'entity_type' => 'persons',
        ])->first();

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
                DB::raw('COUNT(DISTINCT CASE WHEN '.$tablePrefix.'leads.lead_pipeline_stage_id IN ('.(implode(',', $this->wonStageIds) ?: '0').') THEN '.$tablePrefix.'leads.id END) AS count')
            )
            // Se cuenta por created_at (no closed_at): los leads concretados no
            // siempre tienen closed_at poblado.
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->groupBy('branch_id', 'branch_text');

        if (function_exists('bouncer') && ($userIds = bouncer()->getAuthorizedUserIds())) {
            $query->whereIn('users.id', $userIds);
        }

        $results = $query->get();

        $countsByName = [];

        foreach ($results as $row) {
            $name = null;

            if ($attribute && $attribute->lookup_type && $row->branch_id) {
                $entity = $this->attributeRepository->getLookUpEntity($attribute->lookup_type, $row->branch_id, ['id', 'name']);
                $name = $entity?->name;
            }

            $name = $name ?? ($row->branch_text ?: 'Sin sucursal');

            $countsByName[$name] = ($countsByName[$name] ?? 0) + (int) $row->count;
        }

        $labels = array_keys($countsByName);
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        $data = [];
        foreach ($labels as $label) {
            $data[] = $countsByName[$label];
        }

        return [
            'labels' => $labels,
            'data'   => $data,
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
     * Bucket rule specific to "Comportamiento de etapas": with 4 barras por punto
     * el chart se satura rápido, así que agrupa más agresivo que determinePeriod()
     * (30 días → semana en vez de día; 2 años → trimestre en vez de año).
     */
    protected function determineStagesPeriod(): string
    {
        $days = $this->startDate->diffInDays($this->endDate);

        if ($days <= 14) {
            return 'day';
        }

        if ($days <= 180) {
            return 'week';
        }

        if ($days <= 540) {
            return 'month';
        }

        return 'quarter';
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
        return $this->leadRepository
            ->resetModel()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
    }

    /**
     * Retrieves total consultas (leads en etapa Consultas) and their progress.
     */
    public function getTotalConsultasProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalConsultas($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalConsultas($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total consultas by date
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalConsultas($startDate, $endDate): int
    {
        return $this->leadRepository
            ->resetModel()
            ->whereIn('lead_pipeline_stage_id', $this->consultaStageIds ?: [0])
            ->whereBetween('created_at', [$startDate, $endDate])
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
        $days = $startDate->diffInDays($endDate);

        if ($days == 0) {
            return 0;
        }

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
            ->whereIn('lead_pipeline_stage_id', $this->wonStageIds)
            ->whereBetween('closed_at', [$startDate, $endDate])
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
            ->when(request('user_id'), function ($q) {
                $q->where('leads.user_id', request('user_id'));
            })
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
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
            ->when(request('user_id'), function ($q) {
                $q->where('leads.user_id', request('user_id'));
            })
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
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
            ->when(request('user_id'), function ($q) {
                $q->where('leads.user_id', request('user_id'));
            })
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
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
        $pipeline = $this->pipelineRepository->getDefaultPipeline();
        $tablePrefix = DB::getTablePrefix();

        $stages = $pipeline->stages()->get();

        $wonCodes = ['won'];
        $lostCodes = ['lost'];

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

        $counts = $this->leadRepository
            ->resetModel()
            ->when(request('user_id'), function ($q) {
                $q->where('leads.user_id', request('user_id'));
            })
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
            })
            ->select(
                'lead_pipeline_stages.id',
                'lead_pipeline_stages.name',
                DB::raw('COUNT('.$tablePrefix.'leads.id) as total')
            )
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
            ->where('leads.lead_pipeline_id', $pipeline->id)
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->groupBy('lead_pipeline_stages.id', 'lead_pipeline_stages.name')
            ->get()
            ->keyBy('id');

        return $orderedStages->map(function ($stage) use ($counts) {
            $stat = $counts->get($stage->id);

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
        $pipeline = $this->pipelineRepository->getDefaultPipeline();
        $tablePrefix = DB::getTablePrefix();

        $stages = $pipeline->stages()->get();

        $wonCodes = ['won'];
        $lostCodes = ['lost'];

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
            ->when(request('user_id'), function ($q) {
                $q->where('leads.user_id', request('user_id'));
            })
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
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
     * "Comportamiento de etapas": leads por etapa a lo largo del tiempo para el
     * pipeline por defecto, más los totales del período anterior (misma duración,
     * inmediatamente antes) alineados punto a punto para dibujar el versus.
     *
     * Excluye las etapas perdidas/canceladas y agrupa con determineStagesPeriod().
     */
    public function getTotalLeadsByStagesOverTime(): array
    {
        $period = $this->determineStagesPeriod();
        $tablePrefix = DB::getTablePrefix();

        $pipeline = $this->pipelineRepository->getDefaultPipeline();

        // Este chart de "Comportamiento de etapas" oculta "En proceso" y
        // "Paciente (Atendido)" pero, a diferencia del resto del tablero, SÍ
        // incluye "Cancelado" para visualizar la fuga de leads por etapa.
        $hiddenStageIds = $this->stageRepository
            ->resetModel()
            ->whereIn('code', ['en-proceso', 'paciente-atendido'])
            ->orWhereRaw("LOWER(name) LIKE '%en proceso%'")
            ->orWhereRaw("LOWER(name) LIKE '%atendido%'")
            ->pluck('id')
            ->toArray();

        $orderedStages = $pipeline->stages()
            ->get()
            ->reject(fn ($stage) => in_array($stage->id, $hiddenStageIds))
            ->values();

        $intervals = $this->generateTimeIntervals($this->startDate, $this->endDate, $period);
        // Raw expression: NOT auto-prefixed by the query builder, so qualify with the
        // table prefix to avoid "Unknown column 'leads.created_at'" (real table is od_leads).
        $groupColumn = $this->getGroupColumn($tablePrefix.'leads.created_at', $period);

        $baseQuery = fn () => $this->leadRepository
            ->resetModel()
            ->when(request('user_id'), function ($q) {
                $q->where('leads.user_id', request('user_id'));
            })
            ->when(request('organization_id'), function ($q) {
                $q->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                    ->where('persons.organization_id', request('organization_id'));
            })
            ->where('leads.lead_pipeline_id', $pipeline->id)
            ->whereNotIn('leads.lead_pipeline_stage_id', $hiddenStageIds ?: [0]);

        $results = $baseQuery()
            ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
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
            $byDateStage[$row->date][(int) $row->stage_id] = (int) $row->count;
        }

        $labels = array_map(fn ($i) => $i['label'], $intervals);

        $datasets = [];
        foreach ($orderedStages as $stage) {
            $data = [];
            foreach ($intervals as $interval) {
                $data[] = (int) ($byDateStage[$interval['key']][$stage->id] ?? 0);
            }

            $datasets[] = [
                'label' => $stage->name,
                'data'  => $data,
            ];
        }

        $totals = [];
        foreach ($intervals as $index => $interval) {
            $totals[] = array_sum(array_column(array_column($datasets, 'data'), $index));
        }

        // Período anterior: mismos buckets sobre el rango previo, alineados por índice
        // con la serie actual (índice 0 actual vs índice 0 anterior).
        $previousIntervals = $this->generateTimeIntervals($this->lastStartDate, $this->lastEndDate, $period);

        $previousResults = $baseQuery()
            ->whereBetween('leads.created_at', [$this->lastStartDate, $this->lastEndDate])
            ->select(
                DB::raw("$groupColumn AS date"),
                DB::raw('COUNT('.$tablePrefix.'leads.id) as count')
            )
            ->groupBy(DB::raw($groupColumn))
            ->get()
            ->pluck('count', 'date');

        $previousTotals = [];
        foreach ($intervals as $index => $interval) {
            $previousKey = $previousIntervals[$index]['key'] ?? null;
            $previousTotals[] = $previousKey !== null ? (int) ($previousResults[$previousKey] ?? 0) : 0;
        }

        $total = array_sum($totals);
        $previousTotal = array_sum($previousTotals);

        return [
            'labels'          => $labels,
            'datasets'        => $datasets,
            'totals'          => $totals,
            'previous_totals' => $previousTotals,
            'total'           => $total,
            'previous_total'  => $previousTotal,
            'current_range'   => $this->startDate->format('d M Y').' - '.$this->endDate->format('d M Y'),
            'previous_range'  => $this->lastStartDate->format('d M Y').' - '.$this->lastEndDate->format('d M Y'),
            'progress'        => $this->getPercentageChange($previousTotal, $total),
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
            ->whereIn('lead_pipeline_stage_id', $this->stageIds)
            ->whereBetween($dateColumn, [$startDate, $endDate])
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
        $current = $startDate->copy();

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
                case 'quarter':
                    $current->addQuarter();

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
            case 'quarter':
                return "CONCAT(YEAR($dateColumn), '-Q', QUARTER($dateColumn))";
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
            case 'quarter':
                return $date->year.'-Q'.$date->quarter;
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
                return 'Sem '.$date->format('W, Y');
            case 'month':
                return $date->format('M Y');
            case 'quarter':
                return 'T'.$date->quarter.' '.$date->year;
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
