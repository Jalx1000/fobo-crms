<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.dashboard.index.title')
    </x-slot>
    <!-- Head Details Section -->
    {!! view_render_event('admin.dashboard.index.header.before') !!}

    <div class="mb-5 flex items-center justify-between gap-4 max-sm:flex-wrap">
        {!! view_render_event('admin.dashboard.index.header.left.before') !!}

        <div class="grid gap-1.5">
            <p class="text-2xl font-semibold dark:text-white">
                @lang('admin::app.dashboard.index.title')
            </p>
        </div>

        {!! view_render_event('admin.dashboard.index.header.left.after') !!}

        <!-- Actions -->
        {!! view_render_event('admin.dashboard.index.header.right.before') !!}

        <v-dashboard-filters>
            <!-- Shimmer -->
            <div class="flex gap-1.5">
                <div class="light-shimmer-bg dark:shimmer h-[39px] w-[140px] rounded-md"></div>
                <div class="light-shimmer-bg dark:shimmer h-[39px] w-[140px] rounded-md"></div>
            </div>
        </v-dashboard-filters>

        {!! view_render_event('admin.dashboard.index.header.right.after') !!}
    </div>

    {!! view_render_event('admin.dashboard.index.header.after') !!}

    <!-- Body Component -->
    {!! view_render_event('admin.dashboard.index.content.before') !!}

    @php
        $user = auth()->guard('user')->user();
        $isAdminRole = strtolower(optional($user->role)->name) === 'admin';
    @endphp

    <div class="mt-3.5 flex gap-4 max-xl:flex-wrap">
        <!-- Left Section -->
        {!! view_render_event('admin.dashboard.index.content.left.before') !!}

        {{-- min-w-0: sin esto el ítem flex no se encoge bajo el ancho mínimo de su
             contenido y las tarjetas con tabla ancha desbordan el viewport. --}}
        <div class="flex min-w-0 flex-1 flex-col gap-4 max-xl:flex-auto">
            {{-- @include('admin::dashboard.index.revenue') --}}
            @include('admin::dashboard.index.over-all')
            @include('admin::dashboard.index.leads-by-users')
            @include('admin::dashboard.index.evolution')

            @if ($isAdminRole)
                <div class="grid grid-cols-3 gap-4 w-full">
                    <div class="col-span-1 flex flex-col gap-4">
                        @include('admin::dashboard.index.ventas')
                    </div>
                    <div class="col-span-1 flex flex-col gap-4">
                        @include('admin::dashboard.index.leads-by-users')
                    </div>
                    <div class="col-span-1 flex flex-col gap-4">
                        @include('admin::dashboard.index.leads-por-ciudad')
                    </div>
                    <div class="col-span-1 flex flex-col gap-4">
                        @include('admin::dashboard.index.ventas-por-ciudad')
                    </div>
                </div>
            @else
                @include('admin::dashboard.index.tiempo-por-vendedor')
            @endif

            @include('admin::dashboard.index.tiempo-por-etapa')
        </div>
        {!! view_render_event('admin.dashboard.index.content.left.after') !!}


        <!-- Right Section -->
        {!! view_render_event('admin.dashboard.index.content.right.before') !!}

        <div class="flex w-[378px] max-w-full flex-col gap-4 max-sm:w-full">
            {{-- Funel anterior --}}
            {{-- @include('admin::dashboard.index.open-leads-by-states') --}}

            {{-- Funel actual --}}
            @include('admin::dashboard.index.open-leads-by-states-fixed')

            <!-- Ingresos por fuentes -->
            {{-- @include('admin::dashboard.index.revenue-by-sources') --}}
            
            <!-- Ingresos por tipo -->
            {{-- @include('admin::dashboard.index.revenue-by-types') --}}

            @include('admin::dashboard.index.quantity-products-pie')

            @include('admin::dashboard.index.quantity-products-sold-pie')

            @include('admin::dashboard.index.leads-por-ciudad-pie')
        </div>

        {!! view_render_event('admin.dashboard.index.content.right.after') !!}
    </div>

    {!! view_render_event('admin.dashboard.index.content.after') !!}

    @pushOnce('scripts')
        <script type="module" src="{{ vite()->asset('js/chart.js') }}"></script>

        <script type="module" src="https://cdn.jsdelivr.net/npm/chartjs-chart-funnel@4.2.1/build/index.umd.min.js"></script>

        <script
            type="text/x-template"
            id="v-dashboard-filters-template"
        >
            {!! view_render_event('admin.dashboard.index.date_filters.before') !!}

            <div class="flex flex-wrap items-center gap-1.5">
                <select
                    class="flex min-h-[39px] w-[160px] rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                    v-model="filters.pipeline_id"
                >
                    <option value="">Todas las ciudades</option>
                    <option
                        v-for="pipeline in pipelines"
                        :key="pipeline.id"
                        :value="pipeline.id"
                    >
                        @{{ pipeline.name }}
                    </option>
                </select>

                <div class="flex gap-1">
                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setQuickRange(7)"
                    >
                        7 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setQuickRange(30)"
                    >
                        30 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setQuickRange(90)"
                    >
                        90 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setCurrentMonth()"
                    >
                        Este mes
                    </button>
                </div>

                <x-admin::flat-picker.date
                    class="!w-[140px]"
                    ::allow-input="false"
                >
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="filters.start"
                        placeholder="@lang('admin::app.dashboard.index.start-date')"
                    />
                </x-admin::flat-picker.date>

                <x-admin::flat-picker.date
                    class="!w-[140px]"
                    ::allow-input="false"
                >
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="filters.end"
                        placeholder="@lang('admin::app.dashboard.index.end-date')"
                    />
                </x-admin::flat-picker.date>

                <!-- Export (respects the active city/date filter): Resumen + Detalle -->
                <div class="flex gap-1">
                    <select
                        class="flex min-h-[39px] w-[90px] rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="exportFormat"
                    >
                        <option value="xlsx">XLSX</option>
                        <option value="xls">XLS</option>
                        <option value="csv">CSV</option>
                    </select>

                    <button
                        type="button"
                        class="primary-button flex min-h-[39px] items-center gap-x-1.5"
                        @click="exportData"
                    >
                        <span class="icon-export text-xl"></span>

                        @lang('admin::app.export.export')
                    </button>
                </div>
            </div>

            {!! view_render_event('admin.dashboard.index.date_filters.after') !!}
        </script>

        <script type="module">
            app.component('v-dashboard-filters', {
                template: '#v-dashboard-filters-template',

                data() {
                    // Read the shared global filter cookies (kept in sync with the
                    // Pedidos module). City: 'all'/empty => "Todas".
                    // Date: "from|to|savedAt" - see Webkul\Admin\Helpers\GlobalDateFilter.
                    const savedCity = (document.cookie.match(/(?:^|;\s*)global_pipeline_id=([^;]*)/) || [])[1];
                    const savedDate = (document.cookie.match(/(?:^|;\s*)global_date_range=([^;]*)/) || [])[1];

                    let savedFrom = '';
                    let savedTo = '';

                    if (savedDate) {
                        const parts = decodeURIComponent(savedDate).split('|');
                        savedFrom = parts[0] || '';
                        savedTo = parts[1] || '';

                        /**
                         * El "to" guardado solo vale hasta la medianoche del día en
                         * que se eligió: al día siguiente vuelve a hoy, para que el
                         * tablero nunca arranque anclado en el pasado. Misma regla que
                         * GlobalDateFilter::resolve() en PHP, porque el servidor
                         * resuelve la primera carga de las tarjetas y el input tiene
                         * que mostrar lo mismo.
                         */
                        const savedAt = Number(parts[2]);

                        if (savedFrom && savedTo) {
                            const asDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

                            const todayStr = asDate(new Date());

                            if (! savedAt || asDate(new Date(savedAt * 1000)) !== todayStr) {
                                savedTo = todayStr;
                            }

                            if (savedFrom > savedTo) {
                                savedFrom = savedTo;
                            }
                        }
                    }

                    return {
                        pipelines: @json($pipelines->map(fn ($pipeline) => ['id' => $pipeline->id, 'name' => $pipeline->name])),

                        exportFormat: 'xlsx',

                        filters: {
                            channel: '',

                            pipeline_id: (! savedCity || savedCity === 'all') ? '' : decodeURIComponent(savedCity),

                            start: savedFrom || "{{ $startDate->format('Y-m-d') }}",

                            end: savedTo || "{{ $endDate->format('Y-m-d') }}"
                        }
                    }
                },

                watch: {
                    // Emit on any filter change so the cards reload.
                    filters: {
                        handler() {
                            this.$emitter.emit('reporting-filter-updated', this.filters);
                        },

                        deep: true
                    },

                    // Persist each part to its own cookie ONLY when it actually changes,
                    // so e.g. changing the city doesn't accidentally seed a date range.
                    'filters.pipeline_id'(value) {
                        this.setCityCookie(value);
                    },

                    'filters.start'() {
                        this.setDateCookie(this.filters.start, this.filters.end);
                    },

                    'filters.end'() {
                        this.setDateCookie(this.filters.start, this.filters.end);
                    },
                },

                methods: {
                    setCityCookie(pipelineId) {
                        const value = pipelineId === '' ? 'all' : pipelineId;

                        const maxAge = 60 * 60 * 24 * 365;

                        document.cookie = `global_pipeline_id=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
                    },

                    setDateCookie(from, to) {
                        if (! from || ! to) {
                            return;
                        }

                        const maxAge = 60 * 60 * 24 * 365;

                        // El savedAt marca en qué día el usuario eligió el rango; el
                        // "to" vence al cambiar de día calendario.
                        const savedAt = Math.floor(Date.now() / 1000);

                        document.cookie = `global_date_range=${encodeURIComponent(from + '|' + to + '|' + savedAt)}; path=/; max-age=${maxAge}; SameSite=Lax`;
                    },

                    formatDate(date) {
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');

                        return `${year}-${month}-${day}`;
                    },

                    setQuickRange(days) {
                        const end = new Date();
                        const start = new Date();

                        start.setDate(end.getDate() - (days - 1));

                        this.filters.start = this.formatDate(start);
                        this.filters.end = this.formatDate(end);
                    },

                    setCurrentMonth() {
                        const end = new Date();
                        const start = new Date(end.getFullYear(), end.getMonth(), 1);

                        this.filters.start = this.formatDate(start);
                        this.filters.end = this.formatDate(end);
                    },

                    // Download the dashboard export carrying the live filters, so
                    // the file matches exactly what's on screen.
                    exportData() {
                        const params = new URLSearchParams();

                        params.set('format', this.exportFormat);

                        if (this.filters.pipeline_id) {
                            params.set('pipeline_id', this.filters.pipeline_id);
                        }

                        if (this.filters.start && this.filters.end) {
                            params.set('start', this.filters.start);
                            params.set('end', this.filters.end);
                        }

                        window.location.href = `{{ route('admin.dashboard.export') }}?${params.toString()}`;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
