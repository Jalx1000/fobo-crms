{!! view_render_event('admin.dashboard.index.evolution.before') !!}

<!-- Evolution Vue Component -->
<v-dashboard-evolution>
    <!-- Shimmer -->
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-evolution>

{!! view_render_event('admin.dashboard.index.evolution.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-evolution-template"
    >
        <!-- Shimmer -->
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <!-- Header: title + context subtitle -->
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-0.5">
                        <p class="text-base font-semibold dark:text-gray-300">
                            {{ ($personal ?? false) ? 'Mis ventas' : 'Ventas' }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Período actual (@{{ active.current_range }}) vs. anterior (@{{ active.previous_range }})
                        </p>
                    </div>

                    <!-- Metric tabs -->
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            type="button"
                            class="rounded-md border px-3 py-1 text-xs transition-all duration-300 ease-out focus:outline-none"
                            :class="activeMetric === tab.key
                                ? 'border-violet-500 bg-violet-50 text-violet-700 opacity-100 blur-0 scale-105 dark:border-violet-500 dark:bg-violet-950 dark:text-violet-200'
                                : 'border-gray-200 text-gray-600 opacity-50 blur-[1.5px] hover:opacity-100 hover:blur-0 hover:border-gray-400 dark:border-gray-800 dark:text-gray-300 dark:hover:border-gray-400'"
                            @click="activeMetric = tab.key"
                        >
                            @{{ tab.label }}
                        </button>
                    </div>
                </div>

                <!-- Headline: total + progress vs previous + average -->
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-xs text-gray-500 dark:text-gray-400">Total actual</span>

                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold dark:text-gray-200">@{{ active.current_total_formatted }}</span>

                            <span
                                class="flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="active.progress >= 0
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                                    : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'"
                            >
                                <span v-text="active.progress >= 0 ? '▲' : '▼'"></span>
                                @{{ Math.abs(active.progress).toFixed(1) }}%
                            </span>
                        </div>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Promedio por @{{ unitLabel }}: @{{ active.current_average_formatted }}
                        </span>
                    </div>

                    <div class="flex flex-col text-right">
                        <span class="text-xs text-gray-500 dark:text-gray-400">Total anterior</span>
                        <span class="text-base font-medium text-gray-400">@{{ active.previous_total_formatted }}</span>
                    </div>
                </div>

                <!-- Line Chart -->
                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.line
                        ::key="activeMetric"
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex justify-center gap-5">
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm" style="background-color: #8979FF;"></span>
                            <p class="text-xs dark:text-gray-300">Período actual</p>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="h-1 w-3.5 rounded-sm" style="background-color: #9CA3AF;"></span>
                            <p class="text-xs dark:text-gray-300">Período anterior</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-evolution', {
            template: '#v-dashboard-evolution-template',

            data() {
                return {
                    report: null,

                    activeMetric: 'ventas',

                    isLoading: true,

                    tabs: [
                        { key: 'ventas',             label: 'Pedidos entregados' },
                        // { key: 'pedidos-creados',    label: 'Prospectos' },
                        { key: 'valor-ventas',       label: 'Valor de ventas' },
                        { key: 'productos-vendidos', label: 'Productos vendidos' },
                    ],
                }
            },

            computed: {
                active() {
                    if (! this.report || ! this.report[this.activeMetric]) {
                        return {
                            labels: [],
                            data: [],
                            previous_data: [],
                            period: null,
                            current_total_formatted: '0',
                            previous_total_formatted: '0',
                            current_average: 0,
                            previous_average: 0,
                            current_average_formatted: '0',
                            previous_average_formatted: '0',
                            current_range: '',
                            previous_range: '',
                            progress: 0,
                        };
                    }

                    return this.report[this.activeMetric];
                },

                unitLabel() {
                    return {
                        day:   'día',
                        week:  'semana',
                        month: 'mes',
                        year:  'año',
                    }[this.active.period] || 'período';
                },

                chartLabels() {
                    return this.active.labels;
                },

                chartDatasets() {
                    return [
                        {
                            label: 'Período actual',
                            data: this.active.data,
                            borderColor: '#8979FF',
                            backgroundColor: 'rgba(137, 121, 255, 0.15)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 3,
                            pointBackgroundColor: '#8979FF',
                            order: 1,
                        },
                        {
                            label: 'Período anterior',
                            data: this.active.previous_data,
                            borderColor: '#9CA3AF',
                            backgroundColor: 'transparent',
                            borderDash: [6, 6],
                            fill: false,
                            tension: 0.35,
                            pointRadius: 3,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: '#9CA3AF',
                            spanGaps: true,
                            order: 2,
                        },
                    ];
                },
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;

                    var params = Object.assign({}, filters);
                    params.type = 'evolution';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params })
                        .then(response => {
                            this.report = response.data.statistics;
                            this.isLoading = false;
                        })
                        .catch(error => {});
                },
            },
        });
    </script>
@endPushOnce
