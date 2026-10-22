<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.dashboard.index.title')
    </x-slot>
    <!-- Head Details Section -->
    {!! view_render_event('admin.dashboard.index.header.before') !!}

    {{-- Celular: banda blanca de borde a borde, como en Pedidos: «Tablero» y el botón «Filtros». --}}
    <div class="mb-5 flex items-center justify-between gap-4 max-lg:-mx-3 max-lg:-mt-3 max-lg:mb-0 max-lg:border-b max-lg:border-gray-200 max-lg:bg-white max-lg:px-3 max-lg:py-2 dark:max-lg:border-gray-800 dark:max-lg:bg-gray-900">
        {!! view_render_event('admin.dashboard.index.header.left.before') !!}

        <div class="grid gap-1.5">
            <p class="text-2xl font-semibold max-lg:text-xl max-lg:font-bold dark:text-white">
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

    {{-- Celular: tarjetas de borde a borde (sin el gris de los costados), separadas por una franja fina. --}}
    <div class="mt-3.5 flex gap-4 max-xl:flex-wrap max-lg:-mx-3 max-lg:mt-2 max-lg:gap-2 max-lg:[&>div>*]:rounded-none max-lg:[&>div>*]:border-x-0 max-lg:[&>div>.grid>*>*]:rounded-none max-lg:[&>div>.grid>*>*]:border-x-0">
        <!-- Left Section -->
        {!! view_render_event('admin.dashboard.index.content.left.before') !!}

        <div class="flex min-w-0 flex-1 flex-col gap-4 max-xl:flex-auto max-xl:basis-full max-lg:gap-2">
            {{-- @include('admin::dashboard.index.revenue') --}}
            @include('admin::dashboard.index.over-all')
            @include('admin::dashboard.index.leads-by-users')
            @include('admin::dashboard.index.evolution')

            @if ($isAdminRole)
                <div class="grid w-full grid-cols-3 gap-4 max-lg:grid-cols-1 max-lg:gap-2">
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

        <div class="flex w-[378px] min-w-0 max-w-full flex-col gap-4 max-xl:w-full max-lg:gap-2">
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

            {{-- Lo que se le paga a Meta por los mensajes automáticos. Solo administradores y encargados. --}}
            @if (\Webkul\Admin\Helpers\Dashboard::vePlantillas())
                @include('admin::dashboard.index.plantillas-enviadas')
            @endif
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

            {{--
                Celular (debajo de lg), como en Pedidos: en la banda queda el
                período elegido y el botón «Filtros»; los controles van en un
                panel lateral. En la computadora son los mismos controles en
                línea (el panel no existe: todo lo «max-lg:» se ignora).
            --}}
            <div class="flex items-center gap-2 lg:hidden">
                <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">@{{ resumenPeriodo }}</span>

                <button
                    type="button"
                    class="relative flex h-10 shrink-0 items-center gap-1.5 rounded-lg border bg-white px-3 text-sm font-medium text-gray-700 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    aria-label="@lang('admin::app.leads.index.filtros-movil.titulo')"
                    @click="filtrosAbiertos = true"
                >
                    <span class="icon-filter text-xl"></span>

                    @lang('admin::app.leads.index.filtros-movil.titulo')

                    <span
                        v-if="filtrosActivos"
                        class="flex h-5 min-w-[20px] items-center justify-center rounded-full bg-brandColor px-1 text-[11px] font-bold text-white"
                        v-text="filtrosActivos"
                    ></span>
                </button>
            </div>

            <div
                v-show="filtrosAbiertos"
                class="fixed inset-0 z-[10002] bg-gray-900/40 lg:hidden"
                @click="filtrosAbiertos = false"
            ></div>

            <div
                class="flex flex-wrap items-center gap-1.5 max-lg:fixed max-lg:inset-y-0 max-lg:right-0 max-lg:z-[10003] max-lg:w-[88%] max-lg:max-w-[340px] max-lg:flex-col max-lg:flex-nowrap max-lg:items-stretch max-lg:gap-5 max-lg:overflow-y-auto max-lg:bg-white max-lg:p-4 max-lg:text-sm max-lg:shadow-xl max-lg:transition-transform max-lg:duration-200 dark:max-lg:bg-gray-900"
                :class="filtrosAbiertos ? 'max-lg:translate-x-0' : 'max-lg:translate-x-full'"
            >
                <div class="flex items-center justify-between lg:hidden">
                    <p class="text-lg font-semibold dark:text-white">@lang('admin::app.leads.index.filtros-movil.titulo')</p>

                    <button
                        type="button"
                        class="icon-cross-large flex h-10 w-10 items-center justify-center text-2xl text-gray-600 dark:text-gray-300"
                        aria-label="@lang('admin::app.leads.index.filtros-movil.cancelar')"
                        @click="filtrosAbiertos = false"
                    ></button>
                </div>

                <label class="contents max-lg:flex max-lg:flex-col max-lg:gap-1.5">
                    <span class="font-semibold text-gray-700 dark:text-gray-300 lg:hidden">@lang('admin::app.leads.index.filtros-movil.ciudad')</span>

                <select
                    class="flex min-h-[39px] w-[160px] rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:w-full max-lg:rounded-lg max-lg:bg-white dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
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
                </label>

                <!-- Distribuidor: solo administradores y encargados (la lista viene vacía para el resto). -->
                <label v-if="distribuidores.length" class="contents max-lg:flex max-lg:flex-col max-lg:gap-1.5">
                    <span class="font-semibold text-gray-700 dark:text-gray-300 lg:hidden">Distribuidor</span>

                <select
                    aria-label="Distribuidor"
                    class="flex min-h-[39px] w-[190px] rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:w-full max-lg:rounded-lg max-lg:bg-white dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                    v-model="filters.distribuidor_id"
                >
                    <option value="">Todos los distribuidores</option>
                    <option
                        v-for="distribuidor in distribuidoresDeLaCiudad"
                        :key="distribuidor.id"
                        :value="String(distribuidor.id)"
                    >
                        @{{ distribuidor.name }} · @{{ distribuidor.ciudades }}
                    </option>
                </select>
                </label>

                <div class="contents max-lg:flex max-lg:flex-col max-lg:gap-1.5">
                    <span class="font-semibold text-gray-700 dark:text-gray-300 lg:hidden">@lang('admin::app.leads.index.filtros-movil.periodo')</span>

                <div class="flex gap-1 max-lg:grid max-lg:grid-cols-2 max-lg:gap-2">
                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setQuickRange(7)"
                    >
                        7 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setQuickRange(30)"
                    >
                        30 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setQuickRange(90)"
                    >
                        90 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        @click="setCurrentMonth()"
                    >
                        Este mes
                    </button>
                </div>
                </div>

                <div class="contents max-lg:flex max-lg:flex-col max-lg:gap-1.5">
                    <span class="font-semibold text-gray-700 dark:text-gray-300 lg:hidden">@lang('admin::app.leads.index.filtros-movil.rango')</span>

                    <div class="contents max-lg:flex max-lg:gap-2">
                <x-admin::flat-picker.date
                    class="!w-[140px] max-lg:!w-[calc(50%-4px)]"
                    ::allow-input="false"
                >
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm max-lg:h-11 max-lg:rounded-lg text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="filters.start"
                        placeholder="@lang('admin::app.dashboard.index.start-date')"
                    />
                </x-admin::flat-picker.date>

                <x-admin::flat-picker.date
                    class="!w-[140px] max-lg:!w-[calc(50%-4px)]"
                    ::allow-input="false"
                >
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm max-lg:h-11 max-lg:rounded-lg text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="filters.end"
                        placeholder="@lang('admin::app.dashboard.index.end-date')"
                    />
                </x-admin::flat-picker.date>
                    </div>
                </div>

                <!-- Export (respects the active city/date filter): Resumen + Detalle -->
                <div class="flex gap-1 max-lg:mt-auto max-lg:gap-2 max-lg:border-t max-lg:border-gray-200 max-lg:pt-4 dark:max-lg:border-gray-800">
                    <select
                        class="flex min-h-[39px] w-[90px] rounded-md border max-lg:h-11 max-lg:rounded-lg max-lg:bg-white px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="exportFormat"
                    >
                        <option value="xlsx">XLSX</option>
                        <option value="xls">XLS</option>
                        <option value="csv">CSV</option>
                    </select>

                    <button
                        type="button"
                        class="primary-button flex min-h-[39px] items-center gap-x-1.5 max-lg:h-11 max-lg:flex-1 max-lg:justify-center"
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
                    const savedDistribuidor = (document.cookie.match(/(?:^|;\s*)global_distribuidor_id=([^;]*)/) || [])[1];
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

                        distribuidores: @json($distribuidores ?? []),

                        exportFormat: 'xlsx',

                        // Celular: panel lateral de filtros.
                        filtrosAbiertos: false,

                        filters: {
                            channel: '',

                            pipeline_id: (! savedCity || savedCity === 'all') ? '' : decodeURIComponent(savedCity),

                            distribuidor_id: savedDistribuidor && savedDistribuidor !== 'all' ? decodeURIComponent(savedDistribuidor) : '',

                            start: savedFrom || "{{ $startDate->format('Y-m-d') }}",

                            end: savedTo || "{{ $endDate->format('Y-m-d') }}"
                        }
                    }
                },

                computed: {
                    // Celular: cuántos filtros además del período (badge de «Filtros»).
                    filtrosActivos() {
                        return [this.filters.pipeline_id, this.filters.distribuidor_id].filter(Boolean).length;
                    },

                    /*
                     * Con una ciudad elegida, solo los distribuidores de esa ciudad. Las
                     * ciudades vienen como texto, así que se compara por nombre.
                     */
                    distribuidoresDeLaCiudad() {
                        const ciudad = this.pipelines.find((p) => String(p.id) === String(this.filters.pipeline_id));

                        return ciudad
                            ? this.distribuidores.filter((d) => d.ciudades.split(', ').includes(ciudad.name))
                            : this.distribuidores;
                    },

                    // Celular: el período elegido, a la vista en la banda («20 oct – 21 oct»).
                    resumenPeriodo() {
                        const corta = (fecha) => fecha
                            ? new Date(fecha + 'T00:00:00').toLocaleDateString('es', { day: 'numeric', month: 'short' }).replace('.', '')
                            : '';

                        return [corta(this.filters.start), corta(this.filters.end)].filter(Boolean).join(' – ');
                    },
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

                    /*
                     * Si la ciudad nueva no tiene al distribuidor elegido, se suelta el
                     * filtro: si no, el desplegable queda en blanco y el tablero sigue
                     * filtrado por alguien que ya no se ve en la lista.
                     */
                    distribuidoresDeLaCiudad(lista) {
                        if (this.filters.distribuidor_id && ! lista.some((d) => String(d.id) === String(this.filters.distribuidor_id))) {
                            this.filters.distribuidor_id = '';
                        }
                    },

                    'filters.distribuidor_id'(value) {
                        const maxAge = 60 * 60 * 24 * 365;

                        document.cookie = `global_distribuidor_id=${encodeURIComponent(value || 'all')}; path=/; max-age=${maxAge}; SameSite=Lax`;
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

                        if (this.filters.distribuidor_id) {
                            params.set('distribuidor_id', this.filters.distribuidor_id);
                        }

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
