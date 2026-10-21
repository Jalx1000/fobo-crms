{!! view_render_event('admin.dashboard.index.quantity_products_pie.before') !!}

<v-dashboard-quantity-products-pie>
    <x-admin::shimmer.dashboard.index.top-selling-products />
</v-dashboard-quantity-products-pie>

{!! view_render_event('admin.dashboard.index.quantity_products_pie.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-quantity-products-pie-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.top-selling-products />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        Productos más solicitados
                    </p>
                </div>

                <div class="flex flex-col" v-if="report.statistics.length">
                    <div class="flex w-full max-w-full flex-col gap-4 px-8 pt-8 max-lg:mx-auto max-lg:w-[240px] max-lg:px-0 max-lg:pt-2">
                        <x-admin::charts.doughnut
                            ::labels="chartLabels"
                            ::datasets="chartDatasets"
                        />

                        <div class="flex flex-wrap justify-center gap-5">
                            <div class="flex items-start gap-2 max-w-[260px]" v-for="(stat, index) in report.statistics" :key="stat.id">
                                <span class="h-3.5 w-3.5 rounded-sm" :style="{ backgroundColor: colors[index] }"></span>
                                <p class="text-xs dark:text-gray-300 break-words">@{{ stat.name }} - @{{ stat.total_qty_ordered }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-8 p-4" v-else>
                    <div class="grid justify-center justify-items-center gap-3.5 py-2.5">
                        <img src="{{ vite()->asset('images/empty-placeholders/products.svg') }}" class="dark:mix-blend-exclusion dark:invert">
                        <div class="flex flex-col items-center">
                            <p class="text-base font-semibold text-gray-400">@lang('admin::app.dashboard.index.top-selling-products.empty-title')</p>
                            <p class="text-gray-400">@lang('admin::app.dashboard.index.top-selling-products.empty-info')</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-quantity-products-pie', {
            template: '#v-dashboard-quantity-products-pie-template',

            data() {
                return {
                    report: [],
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
                    return this.report.statistics.map(({
                        name
                    }) => name);
                },

                chartDatasets() {
                    // this.colors = this.chartLabels.map((l) => this.$admin.getLabelColor(l));
                    return [{
                        data: this.report.statistics.map(({
                            total_qty_ordered
                        }) => total_qty_ordered),
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
                    filters.type = 'quantity-products';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters
                        })
                        .then(response => {
                            this.report = response.data;
                            this.extendColors(this.report.statistics.length);
                            this.isLoading = false;
                        })
                        .catch(error => {});
                },
                extendColors(length) {
                    while (this.colors.length < length) {
                        const hue = Math.floor(Math.random() * 360);
                        const newColor = `hsl(${hue}, 70%, 60%)`;
                        this.colors.push(newColor);
                    }
                },
            }
        });
    </script>
@endPushOnce
