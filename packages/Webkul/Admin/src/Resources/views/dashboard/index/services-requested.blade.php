{!! view_render_event('admin.dashboard.index.services_requested.before') !!}

<v-dashboard-services-requested>
    <x-admin::shimmer.dashboard.index.top-selling-products />
</v-dashboard-services-requested>

{!! view_render_event('admin.dashboard.index.services_requested.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-services-requested-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.top-selling-products />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.services-requested.title')
                    </p>

                    <span class="rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-semibold text-violet-700 dark:bg-violet-900 dark:text-violet-200">
                        @{{ report.total }}
                    </span>
                </div>

                <div class="flex flex-col" v-if="report.statistics.length">
                    <div class="flex w-full max-w-full flex-col gap-4 px-8 pt-4 pb-8">
                        <x-admin::charts.doughnut
                            ::labels="chartLabels"
                            ::datasets="chartDatasets"
                        />

                        <div class="flex flex-wrap justify-center gap-5">
                            <div class="flex items-start gap-2 max-w-[260px]" v-for="(stat, index) in report.statistics" :key="stat.id">
                                <span class="mt-0.5 h-3.5 w-3.5 shrink-0 rounded-sm" :style="{ backgroundColor: colors[index] }"></span>
                                <div class="flex min-w-0 flex-col">
                                    <p class="text-xs font-medium text-gray-800 dark:text-gray-200 break-words" :title="stat.name">
                                        @{{ stat.name }} - @{{ stat.total_qty_ordered }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        @{{ stat.leads_count }} @lang('admin::app.dashboard.index.services-requested.leads')
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-8 p-4" v-else>
                    <div class="grid justify-center justify-items-center gap-3.5 py-2.5">
                        <img src="{{ vite()->asset('images/empty-placeholders/products.svg') }}" class="dark:mix-blend-exclusion dark:invert">
                        <div class="flex flex-col items-center">
                            <p class="text-base font-semibold text-gray-400">@lang('admin::app.dashboard.index.services-requested.empty-title')</p>
                            <p class="text-gray-400">@lang('admin::app.dashboard.index.services-requested.empty-info')</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-services-requested', {
            template: '#v-dashboard-services-requested-template',

            data() {
                return {
                    report: { statistics: [], total: 0 },
                    colors: [
                        '#8979FF',
                        '#FF928A',
                        '#3CC3DF',
                    ],
                    isLoading: true,
                }
            },

            computed: {
                chartLabels() {
                    return this.report.statistics.map(({ name }) => name);
                },

                chartDatasets() {
                    return [{
                        data: this.report.statistics.map(({ total_qty_ordered }) => total_qty_ordered),
                        backgroundColor: this.colors,
                    }];
                },
            },

            mounted() {
                this.getStats({});

                // Recargar cuando cambie el rango de fechas del tablero.
                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;

                    var filters = Object.assign({}, filters);
                    filters.type = 'services-requested';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters
                        })
                        .then(response => {
                            this.report = response.data.statistics;
                            this.extendColors(this.report.statistics.length);
                            this.isLoading = false;
                        })
                        .catch(error => { this.isLoading = false; console.error(error); });
                },

                extendColors(length) {
                    while (this.colors.length < length) {
                        const hue = Math.floor(Math.random() * 360);
                        this.colors.push(`hsl(${hue}, 70%, 60%)`);
                    }
                },
            }
        });
    </script>
@endPushOnce
