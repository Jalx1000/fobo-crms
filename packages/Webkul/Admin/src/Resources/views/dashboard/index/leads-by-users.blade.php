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
                    <p class="text-base font-semibold dark:text-gray-300">{{ ($personal ?? false) ? 'Mis atendidos vs no atendidos' : 'Atendidos vs no atendidos por encargado' }}</p>

                    <!-- Total = no atendidos (Prospectos) + atendidos (el resto). Cuadra con el embudo. -->
                    <p class="text-xs text-gray-600 dark:text-gray-300">
                        Total: <span class="font-semibold" v-text="report.statistics?.total ?? 0"></span>
                        · No atendidos (Prospectos): <span class="font-semibold" :style="{ color: notAttendedColor }" v-text="report.statistics?.total_not_attended ?? 0"></span>
                        · Atendidos: <span class="font-semibold" :style="{ color: attendedColor }" v-text="report.statistics?.total_attended ?? 0"></span>
                    </p>
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
        const data = response.data;

        // Reemplazar "Agente" por "Sin ciudad" en labels y users
        if (data.statistics) {
            data.statistics.labels = data.statistics.labels.map(l => l === "Agente" ? "Sin ciudad" : l);
            data.statistics.users = data.statistics.users.map(u => u === "Agente" ? "Sin ciudad" : u);
        }

        this.report = data;
        this.isLoading = false;
    })
                        .catch(error => {});
                },
            }
        });
    </script>
@endPushOnce
