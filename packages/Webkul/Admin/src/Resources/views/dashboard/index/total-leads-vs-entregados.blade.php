{!! view_render_event('admin.dashboard.index.total_leads_vs_entregados.before') !!}

<!-- Prospectos vs. Pedidos entregados Vue Component -->
<v-dashboard-leads-vs-entregados>
    <!-- Shimmer -->
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-leads-vs-entregados>

{!! view_render_event('admin.dashboard.index.total_leads_vs_entregados.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-leads-vs-entregados-template"
    >
        <!-- Shimmer -->
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <!-- Prospectos vs. Pedidos entregados Section -->
        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">
                        @lang('admin::app.dashboard.index.leads-vs-entregados.title')
                    </p>
                </div>

                <!-- Combo Chart (stacked bars + line) -->
                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.bar
                        ::stacked="true"
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex flex-wrap justify-center gap-5">
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm" style="background-color: #8979FF;"></span>
                            <p class="text-xs dark:text-gray-300">
                                @lang('admin::app.dashboard.index.leads-vs-entregados.prospects')
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm" style="background-color: #F59E0B;"></span>
                            <p class="text-xs dark:text-gray-300">
                                @lang('admin::app.dashboard.index.leads-vs-entregados.confirmed')
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-1 w-3.5 rounded-sm" style="background-color: #10B981;"></span>
                            <p class="text-xs dark:text-gray-300">
                                @lang('admin::app.dashboard.index.leads-vs-entregados.delivered')
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-leads-vs-entregados', {
            template: '#v-dashboard-leads-vs-entregados-template',

            data() {
                return {
                    report: [],

                    isLoading: true,
                }
            },

            computed: {
                chartLabels() {
                    return this.report.statistics.prospecto.over_time.map(({ label }) => label);
                },

                chartDatasets() {
                    return [{
                        // No atendidos -> segmento inferior de la barra apilada
                        type: 'bar',
                        order: 3,
                        stack: 'pipeline',
                        data: this.report.statistics.prospecto.over_time.map(({ count }) => count),
                        backgroundColor: '#8979FF',
                    },{
                        // Cotización enviada -> segmento superior de la barra apilada
                        type: 'bar',
                        order: 2,
                        stack: 'pipeline',
                        data: this.report.statistics.confirmed.over_time.map(({ count }) => count),
                        backgroundColor: '#F59E0B',
                    },{
                        // Pedidos entregados -> linea superpuesta (sin apilar)
                        type: 'line',
                        order: 1,
                        data: this.report.statistics.won.over_time.map(({ count }) => count),
                        borderColor: '#10B981',
                        backgroundColor: '#10B981',
                        borderWidth: 3,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: '#10B981',
                        fill: false,
                    }];
                }
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;

                    var filters = Object.assign({}, filters);

                    filters.type = 'total-leads';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters
                        })
                        .then(response => {
                            this.report = response.data;

                            this.isLoading = false;
                        })
                        .catch(error => {});
                }
            }
        });
    </script>
@endPushOnce
