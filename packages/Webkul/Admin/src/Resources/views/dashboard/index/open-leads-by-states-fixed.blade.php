{!! view_render_event('admin.dashboard.index.open_leads_by_states_fixed.before') !!}

<v-dashboard-open-leads-by-states-fixed>
    <x-admin::shimmer.dashboard.index.open-leads-by-states />
</v-dashboard-open-leads-by-states-fixed>

{!! view_render_event('admin.dashboard.index.open_leads_by_states_fixed.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-open-leads--by-states-fixed-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.open-leads-by-states />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">
                        {{ ($personal ?? false) ? 'Mis leads por etapa' : 'Leads por etapa' }}
                    </p>

                    <!-- Mismo total que «Atendidos vs no atendidos». -->
                    <p class="text-sm font-semibold text-gray-600 dark:text-gray-300">
                        Total: <span v-text="(report.statistics || []).reduce((suma, etapa) => suma + etapa.total, 0)"></span>
                    </p>
                </div>

                <div class="relative flex w-full max-w-full flex-col gap-4" v-if="report.statistics.length">
                    <canvas :id="$.uid + '_chart'" class="w-full max-w-full items-end px-16" :style="{ height: report.statistics.length * 50 + 'px' }"></canvas>

                    <ul class="absolute flex w-full flex-col">
                        <li class="flex w-full flex-col border-b border-gray-200 pb-[2px] pt-2 last:border-none dark:border-gray-800" v-for="(stat, index) in report.statistics">
                            <span class="text-[13px] font-semibold dark:text-gray-100">@{{ stat.name }}</span>
                            <span class="text-[13px] font-semibold dark:text-gray-100">@{{ stat.total }}</span>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col gap-8 p-4" v-else>
                    <div class="grid justify-center justify-items-center gap-3.5 py-2.5">
                        <img src="{{ vite()->asset('images/empty-placeholders/default.svg') }}" class="dark:mix-blend-exclusion dark:invert">
                        <div class="flex flex-col items-center">
                            <p class="text-base font-semibold text-gray-400">No hay datos disponibles</p>
                            <p class="text-gray-400">No hay datos para el intervalo seleccionado</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-open-leads-by-states-fixed', {
            template: '#v-dashboard-open-leads--by-states-fixed-template',

            data() {
                return {
                    report: [],
                    isLoading: true,
                    chart: undefined,
                    filters: {
                        pipeline_id: '',
                    }
                }
            },

            mounted() {
                this.getStats({});
                this.$emitter.on('reporting-filter-updated', (globalFilters) => {
                    this.filters = Object.assign(this.filters, globalFilters);
                    this.getStats(this.filters);
                });
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;
                    var params = Object.assign({}, filters);
                    params.type = 'open-leads-by-states-fixed';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: params })
                        .then(response => {
                            this.report = response.data;
                            this.isLoading = false;
                            setTimeout(() => { this.prepare(); }, 0);
                        })
                        .catch(error => {
                            this.isLoading = false;
                            this.report = { statistics: [] };
                        });
                },

                prepare() {
                    if (this.chart) {
                        this.chart.destroy();
                    }
                    if (this.report.statistics.length === 0) {
                        return;
                    }
                    const ctx = document.getElementById(this.$.uid + '_chart')?.getContext('2d');
                    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(144, 247, 236, 0.8)');
                    gradient.addColorStop(1, 'rgba(50, 204, 188, 1)');
                    this.chart = new Chart(ctx, {
                        type: 'funnel',
                        data: {
                            labels: this.report.statistics.map(stat => stat.name),
                            datasets: [{
                                data: this.report.statistics.map(stat => stat.total),
                                backgroundColor: gradient,
                                borderColor: 'rgba(0, 0, 0, 0)',
                                borderWidth: 0,
                            }],
                        },
                        options: { indexAxis: 'y' },
                    });
                }
            }
        });
    </script>
@endPushOnce
