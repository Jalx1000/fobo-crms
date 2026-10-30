{!! view_render_event('admin.dashboard.index.total_leads_by_stages_over_time.before') !!}

<v-dashboard-total-leads-by-stages-over-time>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-total-leads-by-stages-over-time>

{!! view_render_event('admin.dashboard.index.total_leads_by_stages_over_time.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-total-leads-by-stages-over-time-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold dark:text-gray-300">Comportamiento de etapas</p>
                        <p class="text-xs text-gray-400">
                            @{{ report.current_range }} <span class="text-gray-300">·</span> vs @{{ report.previous_range }}
                        </p>
                    </div>

                    <div class="flex flex-col items-end">
                        <p class="text-2xl font-bold text-gray-500 dark:text-gray-400">@{{ report.total }}</p>
                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold"
                                :class="[report.progress < 0 ? 'icon-stats-down text-red-500' : 'icon-stats-up text-green-500']"
                            ></span>
                            <p class="text-xs font-semibold" :class="[report.progress < 0 ? 'text-red-500' : 'text-green-500']">
                                @{{ Math.round(Math.abs(report.progress)) }}%
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex w-full max-w-full flex-col gap-4">
                    <canvas :id="$.uid + '_stages_chart'" class="flex w-full max-w-full items-end" style="aspect-ratio: 2.7/1"></canvas>

                    <div class="flex flex-wrap justify-center gap-x-5 gap-y-2">
                        <div class="flex items-center gap-2" v-for="(ds, index) in stageDatasets" :key="ds.label">
                            <span class="h-3.5 w-3.5 rounded-sm" :style="{ backgroundColor: stageColor(index) }"></span>
                            <p class="text-xs dark:text-gray-300">@{{ ds.label }}</p>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="w-5 rounded border-t-[2.5px] border-solid" :style="{ borderColor: inkColor() }"></span>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Total del período</p>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="w-5 rounded border-t-2 border-dashed" :style="{ borderColor: prevColor() }"></span>
                            <p class="text-xs dark:text-gray-300">Total período anterior</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-total-leads-by-stages-over-time', {
            template: '#v-dashboard-total-leads-by-stages-over-time-template',

            data() {
                return {
                    report: {
                        labels: [],
                        datasets: [],
                        totals: [],
                        previous_totals: [],
                        total: 0,
                        previous_total: 0,
                        current_range: '',
                        previous_range: '',
                        progress: 0,
                    },

                    // Paleta pastel con separación validada para daltonismo (ΔE ≥ 23),
                    // asignada en orden fijo de etapas del pipeline.
                    stagePalette: ['#A99DF5', '#85C8DF', '#F0B078', '#8BD1B2', '#E8A7C9', '#A4B8F0'],

                    isLoading: true,

                    chart: undefined,
                }
            },

            computed: {
                stageDatasets() {
                    return this.report.datasets || [];
                },

                atendidoDataset() {
                    return this.stageDatasets.find((ds) => /atendido/i.test(ds.label || '')) || null;
                },
            },

            mounted() {
                this.getStats({});
                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            beforeUnmount() {
                // Destruir la instancia al desmontar evita que Chart.js siga su loop de
                // dibujado sobre un canvas ya removido del DOM (getContext sobre null).
                if (this.chart) {
                    this.chart.destroy();
                    this.chart = undefined;
                }
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;

                    var filters = Object.assign({}, filters);
                    filters.type = 'total-leads-by-stages-over-time';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
                        .then(response => {
                            this.report = response.data.statistics;
                            this.isLoading = false;
                            this.$nextTick(() => this.prepare());
                        })
                        .catch(error => { this.isLoading = false; console.error(error); });
                },

                isDark() {
                    return document.documentElement.classList.contains('dark');
                },

                stageColor(index) {
                    return this.stagePalette[index % this.stagePalette.length];
                },

                // Línea "Total del período": violeta del tablero (igual que la línea
                // actual de Evolución). Período anterior: gris claro.
                inkColor() {
                    return '#bdbfc1';
                },

                prevColor() {
                    return this.isDark() ? '#4B5563' : '#D1D5DB';
                },

                surfaceColor() {
                    return this.isDark() ? '#111827' : '#FFFFFF';
                },

                prepare() {
                    if (this.chart) {
                        this.chart.destroy();
                    }

                    const canvas = document.getElementById(this.$.uid + '_stages_chart');

                    if (! canvas) {
                        return;
                    }

                    // Mata cualquier instancia "zombie" aún ligada a este canvas antes
                    // de crear una nueva (evita getContext sobre null en el loop de dibujado).
                    Chart.getChart(canvas)?.destroy();

                    const totals = this.report.totals || [];
                    const previousTotals = this.report.previous_totals || [];

                    const barDatasets = this.stageDatasets.map((ds, index) => ({
                        type: 'bar',
                        label: ds.label,
                        data: ds.data,
                        backgroundColor: this.stageColor(index),
                        borderRadius: 3,
                        barPercentage: 0.9,
                        categoryPercentage: 0.72,
                        order: 3,
                    }));

                    const lineDatasets = [
                        {
                            type: 'line',
                            label: 'Total del período',
                            data: totals,
                            borderColor: this.inkColor(),
                            backgroundColor: this.inkColor(),
                            borderWidth: 2.5,
                            pointRadius: 3.5,
                            pointHoverRadius: 5,
                            pointBorderColor: this.surfaceColor(),
                            pointBorderWidth: 2,
                            tension: 0.2,
                            order: 1,
                        },
                        {
                            type: 'line',
                            label: 'Total período anterior',
                            data: previousTotals,
                            borderColor: this.prevColor(),
                            backgroundColor: this.prevColor(),
                            borderWidth: 2,
                            borderDash: [6, 5],
                            pointRadius: 3,
                            pointHoverRadius: 4.5,
                            pointBorderColor: this.surfaceColor(),
                            pointBorderWidth: 2,
                            tension: 0.2,
                            order: 2,
                        },
                    ];

                    const component = this;

                    this.chart = new Chart(canvas, {
                        data: {
                            labels: this.report.labels,
                            datasets: [...barDatasets, ...lineDatasets],
                        },

                        options: {
                            aspectRatio: 2.7,

                            // Sin animación: el chart no entra al Animator de Chart.js, evitando
                            // que su loop dibuje sobre un canvas ya destruido (getContext de null).
                            animation: false,

                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },

                            plugins: {
                                legend: {
                                    display: false,
                                },

                                tooltip: {
                                    callbacks: {
                                        label(context) {
                                            const value = context.parsed.y ?? 0;

                                            if (context.dataset.type === 'line') {
                                                if (context.datasetIndex === barDatasets.length) {
                                                    const prev = previousTotals[context.dataIndex] ?? 0;
                                                    const diff = value - prev;
                                                    const pct = prev ? Math.round((diff / prev) * 100) : (value ? 100 : 0);

                                                    return ` Total: ${value} (${diff >= 0 ? '▲ +' : '▼ '}${pct}% vs anterior)`;
                                                }

                                                return ` Período anterior: ${value}`;
                                            }

                                            const total = totals[context.dataIndex] || 0;
                                            const share = total ? Math.round((value / total) * 100) : 0;

                                            return ` ${context.dataset.label}: ${value} (${share}%)`;
                                        },

                                        footer(items) {
                                            if (! items.length || ! component.atendidoDataset) {
                                                return '';
                                            }

                                            const index = items[0].dataIndex;
                                            const total = totals[index] || 0;
                                            const atendidos = component.atendidoDataset.data[index] || 0;
                                            const rate = total ? Math.round((atendidos / total) * 100) : 0;

                                            return `Conversión a Atendido: ${rate}%`;
                                        },
                                    },
                                },
                            },

                            scales: {
                                x: {
                                    border: {
                                        dash: [8, 4],
                                    },
                                },

                                y: {
                                    beginAtZero: true,
                                    border: {
                                        dash: [8, 4],
                                    },
                                    ticks: {
                                        callback: (value) => Number.isInteger(value) ? value : '',
                                    },
                                },
                            },
                        },
                    });
                },
            }
        });
    </script>
@endPushOnce
