{!! view_render_event('admin.dashboard.index.total_services.before') !!}

<!-- Total Services Vue Component -->
<v-dashboard-total-services>
    <!-- Shimmer -->
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-total-services>

{!! view_render_event('admin.dashboard.index.total_services.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-total-services-template"
    >
        <!-- Shimmer -->
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <!-- Total Services Section -->
        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">
                        Total servicios solicitados
                    </p>
                </div>

                <!-- Bar Chart -->
                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.bar
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex justify-center gap-5">
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm bg-[#8979FF]"></span>
                            <p class="text-xs dark:text-gray-300">
                                Total
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-total-services', {
            template: '#v-dashboard-total-services-template',

            data() {
                return {
                    report: [],

                    isLoading: true,
                }
            },

            computed: {
                chartLabels() {
                    if (!this.report.statistics || !this.report.statistics.over_time) return [];
                    return this.report.statistics.over_time.map(({ label }) => label);
                },

                chartDatasets() {
                    if (!this.report.statistics || !this.report.statistics.over_time) return [];
                    return [{
                        data: this.report.statistics.over_time.map(({ count }) => count),
                        barThickness: 24,
                        backgroundColor: '#8979FF',
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

                    filters.type = 'total-services';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters
                        })
                        .then(response => {
                            this.report = response.data;

                            this.isLoading = false;
                        })
                        .catch(error => { this.isLoading = false; console.error(error); });
                }
            }
        });
    </script>
@endPushOnce
