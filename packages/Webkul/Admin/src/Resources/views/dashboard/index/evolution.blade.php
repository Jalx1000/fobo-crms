{!! view_render_event('admin.dashboard.index.evolution.before') !!}

<v-dashboard-evolution>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-evolution>

{!! view_render_event('admin.dashboard.index.evolution.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-evolution-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold dark:text-gray-300">
                            @lang('admin::app.dashboard.index.evolution.title')
                        </p>
                        <p class="text-xs text-gray-400">
                            @{{ report.current_range }} <span class="text-gray-300">·</span> @lang('admin::app.dashboard.index.evolution.vs') @{{ report.previous_range }}
                        </p>
                    </div>

                    <div class="flex flex-col items-end">
                        <p class="text-2xl font-bold dark:text-gray-200">@{{ report.total }}</p>
                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold"
                                :class="[report.progress < 0 ? 'icon-stats-down text-red-500' : 'icon-stats-up text-green-500']"
                            ></span>
                            <p class="text-xs font-semibold" :class="[report.progress < 0 ? 'text-red-500' : 'text-green-500']">
                                @{{ Math.round(Math.abs(report.progress)) }}%
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex w-full max-w-full flex-col gap-4">
                    <x-admin::charts.line
                        ::labels="report.labels"
                        ::datasets="chartDatasets"
                    />

                    <div class="flex justify-center gap-5">
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm" style="background-color: #8979FF;"></span>
                            <p class="text-xs dark:text-gray-300">@lang('admin::app.dashboard.index.evolution.current')</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3.5 w-3.5 rounded-sm" style="background-color: #9CA3AF;"></span>
                            <p class="text-xs dark:text-gray-300">@lang('admin::app.dashboard.index.evolution.previous')</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-evolution', {
            template: '#v-dashboard-evolution-template',

            data() {
                return {
                    report: {
                        labels: [],
                        current: [],
                        previous: [],
                        total: 0,
                        previous_total: 0,
                        current_range: '',
                        previous_range: '',
                        progress: 0,
                    },
                    isLoading: true,
                }
            },

            computed: {
                chartDatasets() {
                    return [
                        {
                            label: "@lang('admin::app.dashboard.index.evolution.current')",
                            data: this.report.current,
                            borderColor: '#8979FF',
                            backgroundColor: 'rgba(137, 121, 255, 0.12)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointRadius: 0,
                        },
                        {
                            label: "@lang('admin::app.dashboard.index.evolution.previous')",
                            data: this.report.previous,
                            borderColor: '#9CA3AF',
                            backgroundColor: 'rgba(0, 0, 0, 0)',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            tension: 0.3,
                            fill: false,
                            pointRadius: 0,
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
                    filters.type = 'evolution';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
                        .then(response => {
                            this.report = response.data.statistics;
                            this.isLoading = false;
                        })
                        .catch(error => { this.isLoading = false; console.error(error); });
                },
            }
        });
    </script>
@endPushOnce
