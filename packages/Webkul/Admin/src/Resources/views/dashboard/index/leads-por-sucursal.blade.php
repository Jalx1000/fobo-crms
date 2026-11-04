{!! view_render_event('admin.dashboard.index.leads_por_sucursal.before') !!}

<v-dashboard-leads-por-sucursal>
    <x-admin::shimmer.dashboard.index.top-selling-products />
</v-dashboard-leads-por-sucursal>

{!! view_render_event('admin.dashboard.index.leads_por_sucursal.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-leads-por-sucursal-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.top-selling-products />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        Leads por sucursal
                    </p>
                </div>

                <div class="flex flex-col" v-if="chartLabels.length">
                    <div class="flex w-full max-w-full flex-col gap-4 px-8 pt-8">
                        <x-admin::charts.doughnut
                            ::labels="chartLabels"
                            ::datasets="chartDatasets"
                        />

                        <div class="flex flex-wrap justify-center gap-5">
                            <div class="flex items-center gap-2 whitespace-nowrap" v-for="(label, index) in chartLabels" :key="label">
                                <span class="h-3.5 w-3.5 rounded-sm" :style="{ backgroundColor: colors[index] }"></span>
                                <p class="text-xs dark:text-gray-300">@{{ label }} - @{{ report.statistics?.data?.[index] ?? 0 }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-8 p-4" v-else>
                    <div class="grid justify-center justify-items-center gap-3.5 py-2.5">
                        <img src="{{ vite()->asset('images/empty-placeholders/products.svg') }}" class="dark:mix-blend-exclusion dark:invert">
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
        app.component('v-dashboard-leads-por-sucursal', {
            template: '#v-dashboard-leads-por-sucursal-template',

            data() {
                return {
                    report: [],
                    colors: [
                        '#A7F3D0',
                        '#BFDBFE',
                        '#FECACA',
                        '#E9D5FF',
                        '#FED7AA',
                        '#CFFAFE',
                        '#C7D2FE',
                        '#FBCFE8',
                    ],
                    isLoading: true,
                }
            },

            computed: {
                chartLabels() {
                    return this.report.statistics?.labels ?? [];
                },

                chartDatasets() {
                    return [{
                        data: this.report.statistics?.data ?? [],
                        backgroundColor: this.colors,
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
                    filters.type = 'leads-por-sucursal';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
                        .then(response => {
                            this.report = response.data;
                            this.extendColors(this.chartLabels.length);
                            this.isLoading = false;
                        })
                        .catch(error => {});
                },

                extendColors(length) {
                    while (this.colors.length < length) {
                        const palette = [
                            '#A7F3D0',
                            '#BFDBFE',
                            '#FECACA',
                            '#E9D5FF',
                            '#FED7AA',
                            '#CFFAFE',
                            '#C7D2FE',
                            '#FBCFE8',
                        ];
                        const next = palette[this.colors.length % palette.length];
                        this.colors.push(next);
                    }
                },
            }
        });
    </script>
@endPushOnce
