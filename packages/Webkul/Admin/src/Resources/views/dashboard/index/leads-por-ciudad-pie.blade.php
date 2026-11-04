{!! view_render_event('admin.dashboard.index.leads_por_ciudad_pie.before') !!}

<v-dashboard-leads-por-ciudad-pie>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-leads-por-ciudad-pie>

{!! view_render_event('admin.dashboard.index.leads_por_ciudad_pie.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-leads-por-ciudad-pie-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">{{ ($personal ?? false) ? 'Mis pedidos por ciudad' : 'Pedidos por ciudad' }}</p>
                </div>

                <div class="flex w-full max-w-full flex-col gap-4 max-lg:mx-auto max-lg:w-[240px]">
                    <x-admin::charts.doughnut
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />
                </div>

                <!-- Pedidos por ciudad, «Sin ciudad» incluida. -->
                @include('admin::dashboard.index.pie-table', [
                    'rows'        => 'filas',
                    'label'       => 'row.name',
                    'labelHeader' => 'Ciudad',
                    'value'       => 'row.count',
                    'valueHeader' => 'Pedidos',
                    'total'       => 'report.statistics?.total ?? 0',
                    'colors'      => 'colors',
                ])
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-leads-por-ciudad-pie', {
            template: '#v-dashboard-leads-por-ciudad-pie-template',

            data() {
                return {
                    report: [],
                    colors: [],
                    isLoading: true,
                }
            },

            computed: {
                filas() {
                    const data = this.report.statistics?.data ?? [];

                    return (this.report.statistics?.labels ?? []).map((name, i) => ({ name, count: data[i] }));
                },

                chartLabels() {
                    return this.report.statistics?.labels ?? [];
                },

                chartDatasets() {
                    const labels = this.report.statistics?.labels ?? [];
                    const data = this.report.statistics?.data ?? [];
                    this.colors = labels.map((l) => this.getColorForLabel(l));
                    return [{
                        data: data,
                        backgroundColor: this.colors,
                    }];
                },
            },

            mounted() {
                this.getStats({});
                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getColorForLabel(label) {
                    if (this.$admin && typeof this.$admin.getLabelColor === 'function') {
                        return this.$admin.getLabelColor(label);
                    }

                    if (! window.__labelColorMap) {
                        window.__labelColorMap = {};
                    }

                    const palette = [
                        '#BA2831',
                        '#8979FF',
                        '#63CFE5',
                        '#F59E0B',
                        '#10B981',
                        '#EF4444',
                        '#3B82F6',
                        '#8B5CF6',
                        '#F472B6',
                        '#14B8A6',
                    ];

                    if (! window.__labelColorMap[label]) {
                        const index = Object.keys(window.__labelColorMap).length % palette.length;
                        window.__labelColorMap[label] = palette[index];
                    }

                    return window.__labelColorMap[label];
                },
                getStats(filters) {
                    this.isLoading = true;
                    var filters = Object.assign({}, filters);
                    filters.type = 'pedidos-por-ciudad';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
                        .then(response => {
                            this.report = response.data;
                            this.isLoading = false;
                        })
                        .catch(error => {});
                },

            }
        });
    </script>
@endPushOnce
