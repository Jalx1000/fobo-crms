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
                        {{ ($personal ?? false) ? 'Mis productos más solicitados' : 'Productos más solicitados' }}
                    </p>
                </div>

                <div class="flex flex-col" v-if="report.statistics.length">
                    <div class="flex w-full max-w-full flex-col gap-4 px-8 pt-8 max-lg:mx-auto max-lg:w-[240px] max-lg:px-0 max-lg:pt-2">
                        <x-admin::charts.doughnut
                            ::labels="chartLabels"
                            ::datasets="chartDatasets"
                        />
                    </div>

                    <!-- Unidades cotizadas por producto y en cuántas cotizaciones (D3). -->
                    @include('admin::dashboard.index.pie-table', [
                        'rows'        => 'report.statistics',
                        'label'       => "row.name ?? 'Producto eliminado'",
                        'labelHeader' => 'Producto',
                        'value'       => 'Number(row.total_qty_ordered)',
                        'valueHeader' => 'Unid.',
                        'total'       => 'totalUnidades',
                        'extra'       => ['header' => 'Cotiz.', 'value' => 'row.solicitudes', 'total' => 'totalCotizaciones'],
                        'colors'      => 'colors',
                    ])
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
                    colors: [],
                    isLoading: true,
                }
            },

            computed: {
                totalUnidades() {
                    return this.report.statistics.reduce((suma, row) => suma + Number(row.total_qty_ordered), 0);
                },

                totalCotizaciones() {
                    return this.report.statistics.reduce((suma, row) => suma + Number(row.solicitudes), 0);
                },

                chartLabels() {
                    return this.report.statistics.map(({
                        name
                    }) => name);
                },

                chartDatasets() {
                    // this.colors = this.chartLabels.map((l) => this.$admin.getLabelColor(l));
                    // La porción es la cantidad cotizada (D3); la leyenda suma igual que el KPI.
                    return [{
                        data: this.report.statistics.map(({
                            total_qty_ordered
                        }) => Number(total_qty_ordered)),
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
                            this.colors = this.buildColors(this.report.statistics.length);
                            this.isLoading = false;
                        })
                        .catch(error => {});
                },
                /**
                 * Un color fijo por posición, como en kohlberg: los de siempre y,
                 * después, tonos separados por el ángulo áureo. Antes eran al azar
                 * y desde el 4.º producto salían repetidos o casi iguales.
                 */
                buildColors(length) {
                    const base = ['#8979FF', '#FF928A', '#3CC3DF'];

                    return Array.from({ length }, (_, i) => base[i] ?? `hsl(${Math.round((i * 137.508) % 360)}, 65%, 58%)`);
                },
            }
        });
    </script>
@endPushOnce
