{!! view_render_event('admin.dashboard.index.quantity_products_sold_pie.before') !!}

<v-dashboard-quantity-products-sold-pie>
    <x-admin::shimmer.dashboard.index.top-selling-products />
</v-dashboard-quantity-products-sold-pie>

{!! view_render_event('admin.dashboard.index.quantity_products_sold_pie.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-quantity-products-sold-pie-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.top-selling-products />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        {{ ($personal ?? false) ? 'Mis inmuebles cerrados' : 'Inmuebles cerrados' }}
                    </p>
                </div>

                <div class="flex flex-col" v-if="products.length">
                    <div class="flex w-full max-w-full flex-col gap-4 px-8 pt-8">
                        <x-admin::charts.doughnut
                            ::labels="chartLabels"
                            ::datasets="chartDatasets"
                        />
                    </div>

                    <!-- Todos los inmuebles. El total es el de la tarjeta «Inmuebles cerrados». -->
                    @include('admin::dashboard.index.pie-table', [
                        'rows'        => 'products',
                        'label'       => "row.name ?? 'Inmueble eliminado'",
                        'labelHeader' => 'Inmueble',
                        'value'       => 'row.total_qty_ordered',
                        'valueHeader' => 'Leads',
                        'total'       => 'report.statistics.total_qty',
                        'extra'       => ['header' => 'Precio', 'value' => 'row.formatted_price', 'total' => "''"],
                        'colors'      => 'colors',
                    ])
                </div>

                <div class="flex flex-col gap-8 p-4" v-else>
                    <div class="grid justify-center justify-items-center gap-3.5 py-2.5">
                        <img src="{{ vite()->asset('images/empty-placeholders/products.svg') }}" class="dark:mix-blend-exclusion dark:invert">
                        <div class="flex flex-col items-center">
                            <p class="text-base font-semibold text-gray-400">Sin cierres en el período</p>
                            <p class="text-gray-400">@lang('admin::app.dashboard.index.top-selling-products.empty-info')</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-quantity-products-sold-pie', {
            template: '#v-dashboard-quantity-products-sold-pie-template',

            data() {
                return {
                    report: [],
                    colors: [],
                    isLoading: true,
                }
            },

            computed: {
                products() {
                    return this.report.statistics?.products ?? [];
                },

                chartLabels() {
                    return this.products.map(({ name }) => name);
                },

                chartDatasets() {
                    return [{
                        data: this.products.map(({ total_qty_ordered }) => total_qty_ordered),
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
                    filters.type = 'inmuebles-cerrados';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters
                        })
                        .then(response => {
                            this.report = response.data;
                            this.colors = this.buildColors(this.products.length);
                            this.isLoading = false;
                        })
                        .catch(error => {});
                },

                /**
                 * Un color fijo por posición: los 3 de siempre y, desde el 4.º,
                 * tonos separados por el ángulo áureo. Antes eran aleatorios y
                 * cambiaban en cada recarga.
                 */
                buildColors(length) {
                    const base = ['#8979FF', '#FF928A', '#3CC3DF'];

                    return Array.from({ length }, (_, i) => base[i] ?? `hsl(${Math.round((i * 137.508) % 360)}, 65%, 58%)`);
                },
            }
        });
    </script>
@endPushOnce
