{!! view_render_event('admin.dashboard.index.over_all.before') !!}

<!-- Over Details Vue Component -->
<v-dashboard-over-all-stats>
    <!-- Shimmer -->
    <x-admin::shimmer.dashboard.index.over-all />
</v-dashboard-over-all-stats>

{!! view_render_event('admin.dashboard.index.over_all.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dashboard-over-all-stats-template"
    >
        <!-- Shimmer -->
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.over-all />
        </template>

        <!-- Total Sales Section -->
        <template v-else>
            <!-- Stats Cards -->
            {{-- Celular: de a dos, con aire a los costados (la columna va de borde a borde). --}}
            <div class="grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-lg:gap-2 max-lg:px-3">
                <!-- Average Revenue Card (Oculto) -->
                <!--
                <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.over-all.average-lead-value')
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ $admin.formatPrice(Math.round(report.statistics.average_lead_value.current)) }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.average_lead_value.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.average_lead_value.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.round(Math.abs(report.statistics.average_lead_value.progress)) }}%
                            </p>
                        </div>
                    </div>
                </div>
                -->
                
                <!-- Total Contactos Card (personas creadas en el rango) -->
                <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        Total contactos
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ Math.round(report.statistics.total_contacts.current) }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.total_contacts.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.total_contacts.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.round(Math.abs(report.statistics.total_contacts.progress)) }}%
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Total Citas (leads) Card -->
                <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.over-all.total-citas')
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ Math.round(report.statistics.total_leads.current) }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.total_leads.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.total_leads.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.round(Math.abs(report.statistics.total_leads.progress)) }}%
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Average Lead Per Day -->
                <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.over-all.average-leads-per-day')
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ Math.round(report.statistics.average_leads_per_day.current) }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.average_leads_per_day.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.average_leads_per_day.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.round(Math.abs(report.statistics.average_leads_per_day.progress)) }}%
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Total Services -->
                <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        Total servicios solicitados
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ Math.round(report.statistics.total_services.current) }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.total_services.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.total_services.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.round(Math.abs(report.statistics.total_services.progress)) }}%
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Total Quotes -->
                <!-- <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.over-all.total-quotations')
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ report.statistics.total_quotations.current }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.total_quotations.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.total_quotations.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.abs(report.statistics.total_quotations.progress.toFixed(2)) }}%
                            </p>
                        </div>
                    </div>
                </div> -->
                
                <!-- Total Persons -->
                <!-- <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.over-all.total-persons')
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ report.statistics.total_persons.current }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.total_persons.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.total_persons.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.abs(report.statistics.total_persons.progress.toFixed(2)) }}%
                            </p>
                        </div>
                    </div>
                </div> -->
                
                <!-- Total Organizations -->
                <!-- <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white px-4 py-5 max-lg:gap-1 max-lg:px-3 max-lg:py-3 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        @lang('admin::app.dashboard.index.over-all.total-organizations')
                    </p>

                    <div class="flex gap-2">
                        <p class="text-xl font-bold dark:text-gray-300">
                            @{{ report.statistics.total_organizations.current }}
                        </p>

                        <div class="flex items-center gap-0.5">
                            <span
                                class="text-base !font-semibold text-green-500"
                                :class="[report.statistics.total_organizations.progress < 0 ? 'icon-stats-down text-red-500 dark:!text-red-500' : 'icon-stats-up text-green-500 dark:!text-green-500']"
                            ></span>

                            <p
                                class="text-xs font-semibold text-green-500"
                                :class="[report.statistics.total_organizations.progress < 0 ?  'text-red-500' : 'text-green-500']"
                            >
                                @{{ Math.abs(report.statistics.total_organizations.progress.toFixed(2)) }}%
                            </p>
                        </div>
                    </div>
                </div> -->
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-over-all-stats', {
            template: '#v-dashboard-over-all-stats-template',

            data() {
                return {
                    report: [],

                    isLoading: true,
                    
                    chart: undefined,
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

                    filters.type = 'over-all';

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: filters
                        })
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
