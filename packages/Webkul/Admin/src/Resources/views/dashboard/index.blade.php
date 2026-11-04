<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.dashboard.index.title')
    </x-slot>
    <!-- Head Details Section -->
    {!! view_render_event('admin.dashboard.index.header.before') !!}

    {{-- Celular: banda blanca de borde a borde, como en Citas: «Tablero», el período y el botón «Filtros». --}}
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

    {{-- Celular: tarjetas de borde a borde (sin el gris de los costados), separadas por una franja fina. --}}
    <div class="mt-3.5 flex gap-4 max-xl:flex-wrap max-lg:-mx-3 max-lg:mt-2 max-lg:gap-2 max-lg:[&>div>*]:rounded-none max-lg:[&>div>*]:border-x-0">
        <!-- Left Section -->
        {!! view_render_event('admin.dashboard.index.content.left.before') !!}

        {{-- min-w-0: sin esto la columna no se encoge bajo el ancho de sus gráficos y se sale de la pantalla. --}}
        <div class="flex min-w-0 flex-1 flex-col gap-4 max-xl:flex-auto max-xl:basis-full max-lg:gap-2">
            {{-- @include('admin::dashboard.index.revenue') --}}
            @include('admin::dashboard.index.over-all')
            @include('admin::dashboard.index.total-leads-by-stages-over-time')

            @include('admin::dashboard.index.leads-by-users')
            @include('admin::dashboard.index.tiempo-por-vendedor')
        </div>

        {!! view_render_event('admin.dashboard.index.content.left.after') !!}

        <!-- Right Section -->
        {!! view_render_event('admin.dashboard.index.content.right.before') !!}

        <div class="flex w-[378px] min-w-0 max-w-full flex-col gap-4 max-xl:w-full max-lg:gap-2">
            {{-- Funel anterior --}}
            {{-- @include('admin::dashboard.index.open-leads-by-states') --}}

            {{-- Funel actual --}}
            @include('admin::dashboard.index.open-leads-by-states-fixed')

            {{-- @include('admin::dashboard.index.revenue-by-sources') --}}

            {{-- @include('admin::dashboard.index.revenue-by-types') --}}

            @include('admin::dashboard.index.services-requested')
        </div>

        {!! view_render_event('admin.dashboard.index.content.right.after') !!}
    </div>

    {!! view_render_event('admin.dashboard.index.content.after') !!}

    {{-- Helper compartido para sincronizar el rango de fechas con Leads y Pacientes --}}
    @include('admin::components.global-date-range')

    @pushOnce('scripts')
        <script type="module" src="{{ vite()->asset('js/chart.js') }}"></script>

        <script type="module" src="https://cdn.jsdelivr.net/npm/chartjs-chart-funnel@4.2.1/build/index.umd.min.js"></script>

        <script
            type="text/x-template"
            id="v-dashboard-filters-template"
        >
            {!! view_render_event('admin.dashboard.index.date_filters.before') !!}

            {{--
                Celular (debajo de lg), como en Citas: en la banda queda el período
                elegido y el botón «Filtros»; los controles van en un panel lateral.
                En la computadora son los mismos controles en línea (todo lo
                «max-lg:» se ignora y el panel no existe).
            --}}
            <div class="flex items-center gap-2 lg:hidden">
                <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" v-text="resumenPeriodo"></span>

                <button
                    type="button"
                    class="flex h-10 shrink-0 items-center gap-1.5 rounded-lg border bg-white px-3 text-sm font-medium text-gray-700 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    @click="filtrosAbiertos = true"
                >
                    <span class="icon-filter text-xl"></span>

                    {{ trans('admin::app.leads.index.filtros-movil.titulo') }}
                </button>
            </div>

            <div
                v-show="filtrosAbiertos"
                class="fixed inset-0 z-[10002] bg-gray-900/40 lg:hidden"
                @click="filtrosAbiertos = false"
            ></div>

            <div
                class="flex gap-1.5 max-lg:fixed max-lg:inset-y-0 max-lg:right-0 max-lg:z-[10003] max-lg:w-[88%] max-lg:max-w-[340px] max-lg:flex-col max-lg:gap-5 max-lg:overflow-y-auto max-lg:bg-white max-lg:p-4 max-lg:text-sm max-lg:shadow-xl max-lg:transition-transform max-lg:duration-200 dark:max-lg:bg-gray-900"
                :class="filtrosAbiertos ? 'max-lg:translate-x-0' : 'max-lg:translate-x-full'"
                role="dialog"
                aria-label="{{ trans('admin::app.leads.index.filtros-movil.titulo') }}"
            >
                <div class="flex items-center justify-between lg:hidden">
                    <p class="text-lg font-semibold dark:text-white">{{ trans('admin::app.leads.index.filtros-movil.titulo') }}</p>

                    <button
                        type="button"
                        class="icon-cross-large flex h-10 w-10 items-center justify-center text-2xl text-gray-600 dark:text-gray-300"
                        aria-label="{{ trans('admin::app.leads.index.filtros-movil.cancelar') }}"
                        @click="filtrosAbiertos = false"
                    ></button>
                </div>

            <div class="contents max-lg:flex max-lg:flex-col max-lg:gap-1.5">
                <span class="font-semibold text-gray-700 dark:text-gray-300 lg:hidden">{{ trans('admin::app.leads.index.filtros-movil.periodo') }}</span>

            <div class="flex gap-1 max-lg:grid max-lg:grid-cols-2 max-lg:gap-2">
                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        :style="quickRangeStyle(7)"
                        :aria-pressed="activeQuick === 7"
                        @click="setQuickRange(7)"
                    >
                        7 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        :style="quickRangeStyle(30)"
                        :aria-pressed="activeQuick === 30"
                        @click="setQuickRange(30)"
                    >
                        30 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        :style="quickRangeStyle(90)"
                        :aria-pressed="activeQuick === 90"
                        @click="setQuickRange(90)"
                    >
                        90 días
                    </button>

                    <button
                        type="button"
                        class="flex min-h-[39px] items-center rounded-md border px-3 text-sm text-gray-600 transition-all hover:border-gray-400 max-lg:h-11 max-lg:justify-center max-lg:rounded-lg max-lg:font-medium dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        :style="quickRangeStyle('month')"
                        :aria-pressed="activeQuick === 'month'"
                        @click="setCurrentMonth()"
                    >
                        Este mes
                    </button>
                </div>
            </div>

            <div class="contents max-lg:flex max-lg:flex-col max-lg:gap-1.5">
                <span class="font-semibold text-gray-700 dark:text-gray-300 lg:hidden">{{ trans('admin::app.leads.index.filtros-movil.desde') }} – {{ trans('admin::app.leads.index.filtros-movil.hasta') }}</span>

                <div class="contents max-lg:flex max-lg:gap-2">
                <x-admin::flat-picker.date
                    class="!w-[140px] max-lg:!w-[calc(50%-4px)]"
                    ::allow-input="false"
                    ::max-date="filters.end"
                >
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 max-lg:h-11 max-lg:rounded-lg transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        :style="customRangeStyle()"
                        v-model="filters.start"
                        @change="onManualDate"
                        placeholder="@lang('admin::app.dashboard.index.start-date')"
                    />
                </x-admin::flat-picker.date>

                <x-admin::flat-picker.date
                    class="!w-[140px] max-lg:!w-[calc(50%-4px)]"
                    ::allow-input="false"
                    ::min-date="filters.start"
                >
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 max-lg:h-11 max-lg:rounded-lg transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        :style="customRangeStyle()"
                        v-model="filters.end"
                        @change="onManualDate"
                        placeholder="@lang('admin::app.dashboard.index.end-date')"
                    />
                </x-admin::flat-picker.date>
                </div>
            </div>

                <button
                    type="button"
                    class="primary-button h-11 justify-center lg:hidden"
                    @click="filtrosAbiertos = false"
                >
                    Ver tablero
                </button>
            </div>

            {!! view_render_event('admin.dashboard.index.date_filters.after') !!}
        </script>

        <script type="module">
            app.component('v-dashboard-filters', {
                template: '#v-dashboard-filters-template',

                data() {
                    // Rango sincronizado (cookie compartida global_date_range) con Leads
                    // y Pacientes. 'quick' guarda explícitamente el botón activo para
                    // evitar ambigüedad cuando dos botones dan el mismo rango.
                    const saved = window.OdontoDateRange.read();

                    return {
                        filters: {
                            start: saved.from || "{{ $startDate->format('Y-m-d') }}",

                            end: saved.to || "{{ $endDate->format('Y-m-d') }}",
                        },

                        // Botón rápido activo: 7 | 30 | 90 | 'month' | '' (personalizado).
                        activeQuick: window.OdontoDateRange.normalizeQuick(saved.quick),

                        // Celular: panel lateral de filtros.
                        filtrosAbiertos: false,
                    };
                },

                computed: {
                    // Celular: el período elegido, a la vista en la banda («20 oct – 27 oct»).
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

                        deep: true,
                    },
                },

                mounted() {
                    // Apply the initial (possibly cookie-restored) range once the cards have
                    // registered their listeners, so the dashboard loads already filtered
                    // instead of falling back to the backend default range.
                    this.$nextTick(() => {
                        this.$emitter.emit('reporting-filter-updated', this.filters);
                    });
                },

                methods: {
                    // Estilos inline (no clases Tailwind nuevas) para no depender de
                    // recompilar el CSS del paquete Admin.
                    quickRangeStyle(key) {
                        if (this.activeQuick !== key) {
                            return {};
                        }

                        return {
                            borderColor: '#2AA8B3',
                            backgroundColor: 'rgba(42, 168, 179, 0.12)',
                            color: '#2AA8B3',
                            fontWeight: '600',
                        };
                    },

                    // Cuando el rango no coincide con ningún botón rápido, el filtro
                    // vigente son los date pickers: se resaltan ellos.
                    customRangeStyle() {
                        if (this.activeQuick !== '') {
                            return {};
                        }

                        return {
                            borderColor: '#2AA8B3',
                        };
                    },

                    persist() {
                        window.OdontoDateRange.write(this.filters.start, this.filters.end, this.activeQuick);
                    },

                    // Edición manual de los date pickers: rango personalizado.
                    onManualDate() {
                        this.activeQuick = '';
                        this.persist();
                    },

                    formatDate(date) {
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');

                        return `${year}-${month}-${day}`;
                    },

                    setQuickRange(days) {
                        const r = window.OdontoDateRange.resolve(days);

                        this.activeQuick = days;
                        this.filters.start = r.from;
                        this.filters.end = r.to;
                        this.persist();
                    },

                    setCurrentMonth() {
                        const r = window.OdontoDateRange.resolve('month');

                        this.activeQuick = 'month';
                        this.filters.start = r.from;
                        this.filters.end = r.to;
                        this.persist();
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
