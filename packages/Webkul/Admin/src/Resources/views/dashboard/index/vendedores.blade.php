{!! view_render_event('admin.dashboard.index.vendedores.before') !!}

<v-dashboard-vendedores>
    <x-admin::shimmer.dashboard.index.top-selling-products />
</v-dashboard-vendedores>

{!! view_render_event('admin.dashboard.index.vendedores.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-vendedores-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.top-selling-products />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.vendedores.title')
                    </p>
                </div>

                <div class="flex flex-col" v-if="report.statistics.length">
                    <div class="flex gap-2.5 border-b p-4 transition-all last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950" v-for="item in report.statistics" :key="item.user_id">
                        <div class="flex w-full flex-col gap-1.5">
                            <p class="text-gray-600 dark:text-gray-300" v-text="item.name"></p>
                            <div class="flex justify-between">
                                <p class="font-normal text-gray-800 dark:text-white">
                                    @{{ formatDuration(item.average_response_time_seconds) }}
                                </p>
                                <p class="font-normal text-gray-800 dark:text-white">
                                    @{{ item.sales_count }} / @{{ item.formatted_total_sales_amount }}
                                </p>
                                <p class="font-normal text-gray-800 dark:text-white">
                                    @{{ item.total_leads }}
                                </p>
                                <div class="flex items-center gap-0.5" v-if="typeof item.progress === 'number'">
                                    <span
                                        class="text-sm !font-semibold"
                                        :class="[item.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                                    ></span>
                                    <p
                                        class="text-xs font-semibold"
                                        :class="[item.progress < 0 ? 'text-red-500' : 'text-green-500']"
                                    >
                                        @{{ Math.round(Math.abs(item.progress)) }}%
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-8 p-4" v-else>
                    <div class="grid justify-center justify-items-center gap-3.5 py-2.5">
                        <img src="{{ vite()->asset('images/empty-placeholders/users.svg') }}" class="dark:mix-blend-exclusion dark:invert">
                        <div class="flex flex-col items-center">
                            <p class="text-base font-semibold text-gray-400">@lang('admin::app.dashboard.index.vendedores.empty-title')</p>
                            <p class="text-gray-400">@lang('admin::app.dashboard.index.vendedores.empty-info')</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-vendedores', {
            template: '#v-dashboard-vendedores-template',

            data() {
                return {
                    report: [],
                    isLoading: true,
                }
            },

            mounted() {
                this.getStats({});
                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                formatDuration(seconds) {
                    if (seconds === null || seconds === undefined) return '-';
                    const h = Math.floor(seconds / 3600);
                    const m = Math.floor((seconds % 3600) / 60);
                    const s = Math.floor(seconds % 60);
                    const parts = [];
                    if (h) parts.push(`${h}h`);
                    if (m) parts.push(`${m}m`);
                    if (!h && !m) parts.push(`${s}s`);
                    return parts.join(' ');
                },
                getStats(filters) {
                    this.isLoading = true;
                    var filters = Object.assign({}, filters);
                    filters.type = 'vendedores';
                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params: filters })
                        .then(response => {
                            this.report = response.data;
                            this.isLoading = false;
                        })
                        .catch(error => {});
                }
            }
        });
    </script>
@endPushOnce
