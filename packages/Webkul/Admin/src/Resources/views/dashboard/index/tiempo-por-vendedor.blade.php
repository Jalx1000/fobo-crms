{!! view_render_event('admin.dashboard.index.tiempo_por_vendedor.before') !!}

<v-dashboard-tiempo-por-vendedor>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-tiempo-por-vendedor>

{!! view_render_event('admin.dashboard.index.tiempo_por_vendedor.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-tiempo-por-vendedor-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">Tiempo en responder</p>
                    <p class="text-sm font-semibold text-gray-600 dark:text-gray-300">Promedio total: @{{ averageMinutesPerLead }} hrs</p>
                </div>

                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.bar
                        ::labels="chartLabels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex flex-wrap justify-center gap-4 text-xs dark:text-gray-300">
                        <div class="flex items-center gap-1.5">
                            <span class="h-3 w-3 rounded-sm bg-gray-700 dark:bg-gray-300"></span>
                            <span>Periodo actual</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="h-3 w-3 rounded-sm bg-gray-700/40 dark:bg-gray-300/40"></span>
                            <span>Periodo anterior</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-center gap-5">
                        <div class="flex items-center gap-2" v-for="(color, index) in colors" :key="index">
                            <span class="h-3.5 w-3.5 rounded-sm" :style="{ backgroundColor: color }"></span>
                            <p class="text-xs dark:text-gray-300">@{{ legendLabels[index] }} - @{{ dataMinutesPerLead[index] }} hrs</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-tiempo-por-vendedor', {
            template: '#v-dashboard-tiempo-por-vendedor-template',

            data() {
                return {
                    report: [],
                    colors: [],
                    isLoading: true,
                    legendLabels: [],
                    leadCountsByUser: {},
                }
            },

            computed: {
                chartLabels() {
                    return this.report.statistics?.labels ?? [];
                },

                chartDatasets() {
                    let labels = this.report.statistics?.labels ?? [];
                    console.log("Datasets\n" + labels)
                    // Asesores
                    // labels[0] = 'Claudia Camacho - TRJ';
                    // labels[1] = 'Daniel Escalante - TRJ'
                    // labels[2] = 'Gabriel Muñoz - CBBA'
                    // labels[2] = 'Jorge Bares - CBBA'
                    // labels[3] = 'Liliana Alarcon - SCZ'
                    // labels[4] = 'María Rene Solano Ruiz - PTS'
                    // labels[5] = 'Nancy Delgado - OR'
                    // labels[6] = 'Silvia Taboada - CH'
                    // labels[7] = 'Stefani Mendieta - LPZ'
                    
                    const data = this.dataMinutesPerLead;
                    const previousData = this.previousMinutesPerLead;
                    this.colors = labels.map((l) => this.getColorForLabel(l));
                    this.legendLabels = labels;
                    return [
                        {
                            label: 'Periodo actual',
                            data: data,
                            backgroundColor: this.colors,
                        },
                        {
                            label: 'Periodo anterior',
                            data: previousData,
                            backgroundColor: this.colors.map((c) => c + '66'),
                        },
                    ];
                },
                dataMinutesPerLead() {
                    const labels = this.report.statistics?.labels ?? [];
                    const dataSeconds = this.report.statistics?.data ?? [];
                    return labels.map((label, i) => {
                        const seconds = typeof dataSeconds[i] === 'number' ? dataSeconds[i] : 0;
                        if (!seconds) return 0;
                        // El controlador ya devuelve el promedio en segundos, solo convertimos a minutos
                        return Number((seconds / 3600).toFixed(1));
                    });
                },
                previousMinutesPerLead() {
                    const labels = this.report.statistics?.labels ?? [];
                    const dataSeconds = this.report.statistics?.previous_data ?? [];
                    return labels.map((label, i) => {
                        const seconds = typeof dataSeconds[i] === 'number' ? dataSeconds[i] : 0;
                        if (!seconds) return 0;
                        return Number((seconds / 3600).toFixed(1));
                    });
                },
                averageMinutesPerLead() {
                    const values = this.dataMinutesPerLead;
                    const valid = values.filter((v) => typeof v === 'number' && v > 0);
                    if (!valid.length) return 0;
                    return Number((valid.reduce((a, b) => a + b, 0) / valid.length).toFixed(1));
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
                    const params1 = Object.assign({}, filters, { type: 'tiempo-por-vendedor' });
                    const params2 = Object.assign({}, filters, { type: 'leads-by-users' });
                    Promise.all([
                        this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: params1 }),
                        this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: params2 }),
                    ])
                    .then(([r1, r2]) => {
                        this.report = r1.data;
                        let labels = r2.data?.statistics?.labels ?? [];
                        console.log("getStats\n" + labels)
                        // labels[0] = 'Claudia Camacho - TRJ';
                        // labels[1] = 'Daniel Escalante - TRJ'
                        // labels[2] = 'Jorge Bares - CBBA'
                        // labels[3] = 'Liliana Alarcon - SCZ'
                        // labels[4] = 'María Rene Solano Ruiz - PTS'
                        // labels[5] = 'Nancy Delgado - OR'
                        // labels[6] = 'Silvia Taboada - CH'
                        // labels[7] = 'Stefani Mendieta - LPZ'
                        
                        const counts = r2.data?.statistics?.data ?? [];
                        this.leadCountsByUser = {};
                        labels.forEach((label, i) => {
                            this.leadCountsByUser[label] = typeof counts[i] === 'number' ? counts[i] : 0;
                        });
                        this.isLoading = false;
                    })
                    .catch(error => {
                        this.isLoading = false;
                        this.report = { statistics: { labels: [], data: [] } };
                        this.leadCountsByUser = {};
                    });
                },

            }
        });
    </script>
@endPushOnce
