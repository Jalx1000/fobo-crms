{!! view_render_event('admin.dashboard.index.total_leads_by_stages.before') !!}

<v-dashboard-total-leads-by-stages>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-total-leads-by-stages>

{!! view_render_event('admin.dashboard.index.total_leads_by_stages.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-total-leads-by-stages-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">Leads por etapas (pipeline)</p>
                </div>

                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.bar ::labels="chartLabels" ::datasets="chartDatasets" />

                    <div class="flex flex-wrap justify-center gap-5">
                        <div class="flex items-center gap-2" v-for="(color, index) in colors" :key="index">
                            <span class="h-3.5 w-3.5 rounded-sm" :style="{ backgroundColor: color }"></span>
                            <p class="text-xs dark:text-gray-300">@{{ legendLabels[index] }}</p>
                        </div>
                    </div>

                    <div class="flex justify-center gap-5">
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm bg-[#8979FF]"></span>
                            <p class="text-xs dark:text-gray-300">Total de Leads: @{{ totalLeads }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-total-leads-by-stages', {
            template: '#v-dashboard-total-leads-by-stages-template',

            data() {
                return {
                    report: [],
                    isLoading: true,
                    colors: [],
                    legendLabels: [],
                }
            },

            computed: {
                chartLabels() {
                    return (this.report.statistics || []).map(({ name }) => name);
                },
                chartDatasets() {
                    const labels = this.chartLabels;
                    this.colors = labels.map((l) => this.getColorForLabel(l));
                    this.legendLabels = labels;
                    return [{
                        data: (this.report.statistics || []).map(({ total }) => total),
                        barThickness: 24,
                        backgroundColor: this.colors,
                    }];
                },
                totalLeads() {
                    return (this.report.statistics || []).reduce((acc, s) => acc + (s.total || 0), 0);
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
                    filters.type = 'total-leads-by-stages';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
                        .then(response => {
                            this.report = response.data;
                            this.isLoading = false;
                        })
                        .catch(error => { this.isLoading = false; console.error(error); });
                },

                getColorForLabel(label) {
                    if (this.$admin && typeof this.$admin.getLabelColor === 'function') {
                        return this.$admin.getLabelColor(label);
                    }

                    if (! window.__labelColorMap) {
                        window.__labelColorMap = {};
                    }

                    if (! window.__labelColorPalette) {
                        window.__labelColorPalette = [
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
                    }

                    const map = window.__labelColorMap;
                    const palette = window.__labelColorPalette;

                    const norm = (label || '').toString().trim().toLowerCase();

                    if (norm === 'ganado' || norm === 'won') {
                        map[label] = '#10B981';
                        return map[label];
                    }

                    if (norm === 'perdido' || norm === 'lost') {
                        map[label] = '#EF4444';
                        return map[label];
                    }

                    if (! map[label]) {
                        const filtered = palette.filter((c) => c !== '#10B981' && c !== '#EF4444');
                        const index = Object.keys(map).length % filtered.length;
                        map[label] = filtered[index];
                    }

                    return map[label];
                }
            }
        });
    </script>
@endPushOnce
