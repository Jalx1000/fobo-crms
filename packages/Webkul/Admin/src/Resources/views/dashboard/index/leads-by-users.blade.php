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
                    <p class="text-base font-semibold dark:text-gray-300">Atendidos vs no atendidos por encargado</p>
                </div>

                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.bar
                    ::stacked="true"
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex flex-wrap justify-center gap-4 text-xs dark:text-gray-300">
                        <div class="flex items-center gap-1.5">
                            <span class="h-3 w-3 rounded-sm" :style="{ backgroundColor: attendedColor }"></span>
                            <span>Atendidos</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="h-3 w-3 rounded-sm" :style="{ backgroundColor: notAttendedColor }"></span>
                            <span>No atendidos</span>
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
                    isLoading: true,
                    attendedColor: '#10B981',
                    notAttendedColor: '#EF4444',
                }
            },

            computed: {
                chartLabels() {
                    return this.report.statistics?.labels ?? [];
                },

                chartDatasets() {
                    const attended = this.report.statistics?.attended ?? [];
                    const notAttended = this.report.statistics?.not_attended ?? [];
                    return [
                        {
                            label: 'Atendidos',
                            data: attended,
                            backgroundColor: this.attendedColor,
                        },
                        {
                            label: 'No atendidos',
                            data: notAttended,
                            backgroundColor: this.notAttendedColor,
                        },
                    ];
                },
            },

            mounted() {
                this.getStats({});
                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;
                    var filters = Object.assign({}, filters);
                    filters.type = 'leads-attention-by-users';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
    .then(response => {
        // El usuario del agente ya no viene del servidor (antes se mostraba
        // renombrado a «Sin ciudad», que no es un encargado).
        this.report = response.data;
        this.isLoading = false;
    })
                        .catch(error => {});
                },
            }
        });
    </script>
@endPushOnce
