<?php

namespace Webkul\Admin\Helpers\Reporting;

use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

abstract class AbstractReporting
{
    /**
     * The starting date for a given period.
     */
    protected Carbon $startDate;

    /**
     * The ending date for a given period.
     */
    protected Carbon $endDate;

    /**
     * The starting date for the previous period.
     */
    protected Carbon $lastStartDate;

    /**
     * The ending date for the previous period.
     */
    protected Carbon $lastEndDate;

    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setStartDate(request()->date('start'));

        $this->setEndDate(request()->date('end'));
    }

    /**
     * Set the start date or default to 30 days ago if not provided.
     *
     * @param  \Carbon\Carbon|null  $startDate
     * @return void
     */
    public function setStartDate(?Carbon $startDate = null): self
    {
        $this->startDate = $startDate ? $startDate->startOfDay() : now()->subDays(1)->startOfDay();

        $this->setLastStartDate();

        return $this;
    }

    /**
     * Sets the end date to the provided date's end of day, or to the current
     * date if not provided or if the provided date is in the future.
     *
     * @param  \Carbon\Carbon|null  $endDate
     * @return void
     */
    public function setEndDate(?Carbon $endDate = null): self
    {
        $this->endDate = ($endDate && $endDate->endOfDay() <= now()) ? $endDate->endOfDay() : now();

        $this->setLastEndDate();

        return $this;
    }

    /**
     * Get the start date.
     *
     * @return \Carbon\Carbon
     */
    public function getStartDate(): Carbon
    {
        return $this->startDate;
    }

    /**
     * Get the end date.
     *
     * @return \Carbon\Carbon
     */
    public function getEndDate(): Carbon
    {
        return $this->endDate;
    }

    /**
     * Sets the start date for the last period.
     */
    private function setLastStartDate(): void
    {
        if (! isset($this->startDate)) {
            $this->setStartDate(request()->date('start'));
        }

        if (! isset($this->endDate)) {
            $this->setEndDate(request()->date('end'));
        }

        /**
         * El período anterior debe tener EXACTAMENTE la misma longitud que el actual
         * y ser contiguo (termina un segundo antes de que empiece el actual). Así
         * actual(período N) == anterior(período N+1) cuando son consecutivos.
         * El cálculo viejo usaba subDays(diffInDays) (= longitud−1 día) y lastEnd =
         * inicio actual, lo que dejaba la ventana anterior corrida y un día más corta.
         */
        $this->setLastEndDate();

        $this->lastStartDate = $this->lastEndDate->clone()
            ->subSeconds($this->startDate->diffInSeconds($this->endDate));
    }

    /**
     * Sets the end date for the last period (un segundo antes del inicio actual).
     */
    private function setLastEndDate(): void
    {
        $this->lastEndDate = $this->startDate->clone()->subSecond();
    }

    /**
     * Get the last start date.
     *
     * @return \Carbon\Carbon
     */
    public function getLastStartDate(): Carbon
    {
        return $this->lastStartDate;
    }

    /**
     * Get the last end date.
     *
     * @return \Carbon\Carbon
     */
    public function getLastEndDate(): Carbon
    {
        return $this->lastEndDate;
    }

    /**
     * Calculate the percentage change between previous and current values.
     *
     * @param  float|int  $previous
     * @param  float|int  $current
     */
    public function getPercentageChange($previous, $current): float|int
    {
        if (! $previous) {
            return $current ? 100 : 0;
        }

        return ($current - $previous) / $previous * 100;
    }

    /**
     * Returns time intervals.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @param  string  $period
     * @return array
     */
    public function getTimeInterval($startDate, $endDate, $dateColumn, $period)
    {
        if ($period == 'auto') {
            $totalMonths = $startDate->diffInMonths($endDate) + 1;

            /**
             * If the difference between the start and end date is more than 5 months
             */
            $intervals = $this->getMonthsInterval($startDate, $endDate);

            if (! empty($intervals)) {
                return [
                    'group_column' => "MONTH($dateColumn)",
                    'intervals'    => $intervals,
                ];
            }

            /**
             * If the difference between the start and end date is more than 6 weeks
             */
            $intervals = $this->getWeeksInterval($startDate, $endDate);

            if (! empty($intervals)) {
                return [
                    'group_column' => "WEEK($dateColumn)",
                    'intervals'    => $intervals,
                ];
            }

            /**
             * If the difference between the start and end date is less than 6 weeks
             */
            return [
                'group_column' => "DAYOFYEAR($dateColumn)",
                'intervals'    => $this->getDaysInterval($startDate, $endDate),
            ];
        } else {
            $datePeriod = CarbonPeriod::create($this->startDate, "1 $period", $this->endDate);

            if ($period == 'year') {
                $formatter = '?';
            } elseif ($period == 'month') {
                $formatter = '?-?';
            } else {
                $formatter = '?-?-?';
            }

            $groupColumn = 'DATE_FORMAT('.$dateColumn.', "'.Str::replaceArray('?', ['%Y', '%m', '%d'], $formatter).'")';

            $intervals = [];

            foreach ($datePeriod as $date) {
                $formattedDate = $date->format(Str::replaceArray('?', ['Y', 'm', 'd'], $formatter));

                $intervals[] = [
                    'filter' => $formattedDate,
                    'start'  => $formattedDate,
                ];
            }

            return [
                'group_column' => $groupColumn,
                'intervals'    => $intervals,
            ];
        }
    }

    /**
     * Returns time intervals.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @return array
     */
    public function getMonthsInterval($startDate, $endDate)
    {
        $intervals = [];

        $totalMonths = $startDate->diffInMonths($endDate) + 1;

        /**
         * If the difference between the start and end date is less than 5 months
         */
        if ($totalMonths <= 5) {
            return $intervals;
        }

        for ($i = 0; $i < $totalMonths; $i++) {
            $intervalStartDate = clone $startDate;

            $intervalStartDate->addMonths($i);

            $start = $intervalStartDate->startOfDay();

            $end = ($totalMonths - 1 == $i)
                ? $endDate
                : $intervalStartDate->addMonth()->subDay()->endOfDay();

            $intervals[] = [
                'filter' => $start->month,
                'start'  => $start->format('d M'),
                'end'    => $end->format('d M'),
            ];
        }

        return $intervals;
    }

    /**
     * Returns time intervals.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @return array
     */
    public function getWeeksInterval($startDate, $endDate)
    {
        $intervals = [];

        $startWeekDay = Carbon::createFromTimeString(core()->xWeekRange($startDate, 0).' 00:00:01');

        $endWeekDay = Carbon::createFromTimeString(core()->xWeekRange($endDate, 1).' 23:59:59');

        $totalWeeks = $startWeekDay->diffInWeeks($endWeekDay);

        /**
         * If the difference between the start and end date is less than 6 weeks
         */
        if ($totalWeeks <= 6) {
            return $intervals;
        }

        for ($i = 0; $i < $totalWeeks; $i++) {
            $intervalStartDate = clone $startDate;

            $intervalStartDate->addWeeks($i);

            $start = $i == 0
                ? $startDate
                : Carbon::createFromTimeString(core()->xWeekRange($intervalStartDate, 0).' 00:00:01');

            $end = ($totalWeeks - 1 == $i)
                ? $endDate
                : Carbon::createFromTimeString(core()->xWeekRange($intervalStartDate->subDay(), 1).' 23:59:59');

            $intervals[] = [
                'filter' => $start->week,
                'start'  => $start->format('d M'),
                'end'    => $end->format('d M'),
            ];
        }

        return $intervals;
    }

    /**
     * Returns time intervals.
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     * @return array
     */
    public function getDaysInterval($startDate, $endDate)
    {
        $intervals = [];

        $totalDays = $startDate->diffInDays($endDate) + 1;

        for ($i = 0; $i < $totalDays; $i++) {
            $intervalStartDate = clone $startDate;

            $intervalStartDate->addDays($i);

            $intervals[] = [
                'filter' => $intervalStartDate->dayOfYear,
                'start'  => $intervalStartDate->startOfDay()->format('d M'),
                'end'    => $intervalStartDate->endOfDay()->format('d M'),
            ];
        }

        return $intervals;
    }

    /**
     * Pipelines «Sin ciudad»: pedidos que todavía no tienen ciudad. Algunas
     * tarjetas del tablero no los cuentan (decisión del 13/10/2026).
     *
     * @return array<int, int>
     */
    protected function sinCiudadPipelineIds(): array
    {
        return $this->sinCiudadIds ??= \Illuminate\Support\Facades\DB::table('lead_pipelines')
            ->whereRaw("LOWER(TRIM(name)) = 'sin ciudad'")
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Universo de pedidos del tablero
    |--------------------------------------------------------------------------
    |
    | Las reglas que todas las tarjetas comparten viven acá y solo acá, para
    | que los totales cuadren con sus desgloses (spec
    | docs/specs/tablero-consistente.md). Un método de Reporting no arma su
    | propio filtro de ciudad, permisos, ejecutivos ni «Sin ciudad».
    |
    */

    /**
     * Cache de los pipelines «Sin ciudad» (se consultan varias veces por request).
     */
    private ?array $sinCiudadIds = null;

    /**
     * Cache de los ids de los ejecutivos comerciales.
     */
    private ?array $ejecutivos = null;

    /**
     * Ciudad elegida en el filtro del tablero, o null para «Todas».
     */
    protected function pipelineFiltro(): ?int
    {
        return is_numeric(request('pipeline_id')) ? (int) request('pipeline_id') : null;
    }

    /**
     * Asesores cuyos números puede ver el usuario logueado, o null si ve el
     * total. Lo decide Alcance por ROL (spec tablero-por-asesor, D1–D3): un
     * ejecutivo ve solo lo suyo; Administrador y Supervisores, todo.
     *
     * @return array<int, int>|null
     */
    protected function asesoresVisibles(): ?array
    {
        $userId = app(\Webkul\Admin\Helpers\Alcance::class)->userId();

        return $userId === null ? null : [$userId];
    }

    /**
     * Usuarios que aparecen en las tarjetas por asesor: solo el rol
     * «Ejecutivo Comercial» (decisión D7 del 15/10/2026). Es por inclusión: un
     * rol nuevo no aparece hasta que se decida.
     *
     * @return array<int, int>
     */
    protected function ejecutivosIds(): array
    {
        return $this->ejecutivos ??= \Illuminate\Support\Facades\DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'Ejecutivo Comercial')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Aplica a una query ya unida a `leads` el filtro de ciudad del tablero y
     * el ACL del usuario. Con ACL, un pedido sin asesor no se ve: no es de
     * nadie que el usuario pueda ver.
     */
    protected function pedidosDelTablero($query, string $leads = 'leads')
    {
        $query->when($this->pipelineFiltro(), fn ($q, $id) => $q->where("{$leads}.lead_pipeline_id", $id));

        // null = sin restricción. Un array vacío SÍ se aplica (no ve a nadie):
        // con when() se saltaría el filtro y mostraría toda la empresa.
        if (! is_null($ids = $this->asesoresVisibles())) {
            $query->whereIn("{$leads}.user_id", $ids);
        }

        return $query;
    }

    /**
     * Saca los pedidos «Sin ciudad». Es el único interruptor de esa regla
     * mientras la pregunta P2 del spec siga abierta.
     */
    protected function excluirSinCiudad($query, string $pipelineColumn = 'leads.lead_pipeline_id')
    {
        return $query->when($this->sinCiudadPipelineIds(), fn ($q, $ids) => $q->whereNotIn($pipelineColumn, $ids));
    }

    /**
     * Lo contrario de excluirSinCiudad(): se queda solo con los pedidos «Sin
     * ciudad». Sirve para armar subconsultas (p. ej. cotizaciones a descartar).
     */
    protected function soloSinCiudad($query, string $pipelineColumn = 'leads.lead_pipeline_id')
    {
        return $query->whereIn($pipelineColumn, $this->sinCiudadPipelineIds());
    }
}
