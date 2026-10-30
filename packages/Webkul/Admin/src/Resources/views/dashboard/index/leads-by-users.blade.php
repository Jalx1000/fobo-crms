{!! view_render_event('admin.dashboard.index.leads_by_users.before') !!}

<v-dashboard-leads-by-users>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-leads-by-users>

{!! view_render_event('admin.dashboard.index.leads_by_users.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-leads-by-users-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">Pacientes por recepcionista</p>
                </div>

                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.bar
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex flex-wrap justify-center gap-5">
                        <div class="flex items-center gap-2" v-for="(color, index) in colors" :key="index">
                            <span class="h-3.5 w-3.5 rounded-sm" :style="{ backgroundColor: color }"></span>
                            <p class="text-xs dark:text-gray-300">@{{ legendLabels[index] }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-leads-by-users', {
            template: '#v-dashboard-leads-by-users-template',

            data() {
                return {
                    report: [],
                    colors: [],
                    isLoading: true,
                    legendLabels: [],
                }
            },

            computed: {
                chartLabels() {
                    return this.report.statistics?.labels ?? [];
                },

                chartDatasets() {
                    const labels = this.report.statistics?.labels ?? [];
                    const data = this.report.statistics?.data ?? [];
                    this.colors = labels.map((l) => this.getColorForLabel(l));
                    this.legendLabels = labels;
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
                    filters.type = 'leads-by-users';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
                        .then(response => {
                            this.report = response.data;
                            this.isLoading = false;
                        })
                        .catch(error => { this.isLoading = false; console.error(error); });
                },

            }
        });
    </script>
@endPushOnce
