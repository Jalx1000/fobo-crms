{!! view_render_event('admin.leads.index.kanban.toolbar.before') !!}

{{-- Celular: banda blanca de borde a borde con el buscador y el botón «Filtros». --}}
<div class="flex justify-between gap-2 max-lg:-mx-3 max-lg:border-b max-lg:border-gray-200 max-lg:bg-white max-lg:px-3 max-lg:py-2 max-md:flex-wrap dark:max-lg:border-gray-800 dark:max-lg:bg-gray-900">
    <div class="flex w-full items-center gap-x-1.5 max-lg:flex-nowrap max-lg:gap-x-2">
        {!! view_render_event('admin.leads.index.kanban.toolbar.search.before') !!}

        <!-- Search Panel -->
        @include('admin::leads.index.kanban.search')

        {!! view_render_event('admin.leads.index.kanban.toolbar.search.after') !!}

        {!! view_render_event('admin.leads.index.kanban.toolbar.filter.before') !!}

        <!-- Filter (en el celular su botón se esconde: se abre desde «Filtros») -->
        @include('admin::leads.index.kanban.filter')

        <!-- Celular: embudo, fechas, filtros por columna y vista, en un panel lateral -->
        @include('admin::leads.index.filtros-movil')

        {!! view_render_event('admin.leads.index.kanban.toolbar.filter.after') !!}

        {!! view_render_event('admin.leads.index.kanban.toolbar.date_range.before') !!}

        <!-- Date Range Filter (rápidos + rango personalizado Desde/Hasta).
             Celular: está en «Filtros». -->
        <div class="flex flex-wrap items-center gap-1.5 max-lg:hidden">
            <template v-for="range in [
                { value: '7', label: '@lang('admin::app.leads.index.kanban.toolbar.date-range.last-7-days')' },
                { value: '30', label: '@lang('admin::app.leads.index.kanban.toolbar.date-range.last-30-days')' },
                { value: '90', label: '@lang('admin::app.leads.index.kanban.toolbar.date-range.last-90-days')' },
                { value: 'month', label: '@lang('admin::app.leads.index.kanban.toolbar.date-range.this-month')' },
            ]">
                <button
                    type="button"
                    class="whitespace-nowrap rounded-md border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300"
                    :style="quickRangeStyle(range.value)"
                    @click="applyQuick(range.value)"
                    v-text="range.label"
                >
                </button>
            </template>

            <label class="flex items-center gap-1 whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">
                Desde
                <input
                    type="date"
                    v-model="applied.dateFrom"
                    @change="applyCustomRange"
                    class="rounded-md border border-gray-200 px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                >
            </label>

            <label class="flex items-center gap-1 whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">
                Hasta
                <input
                    type="date"
                    v-model="applied.dateTo"
                    @change="applyCustomRange"
                    class="rounded-md border border-gray-200 px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                >
            </label>

            <button
                type="button"
                v-if="applied.quick || applied.dateFrom || applied.dateTo"
                @click="clearDateFilters"
                class="whitespace-nowrap text-xs text-gray-500 underline dark:text-gray-400"
            >
                Limpiar
            </button>
        </div>

        {!! view_render_event('admin.leads.index.kanban.toolbar.date_range.after') !!}

        <div class="z-10 hidden w-full divide-y divide-gray-100 rounded bg-white shadow dark:bg-gray-900"></div>
    </div>

    {!! view_render_event('admin.leads.index.kanban.toolbar.switcher.before') !!}

    <!-- View Switcher (en el celular, dentro de «Filtros») -->
    <div class="max-lg:hidden lg:contents">
        @include('admin::leads.index.view-switcher')
    </div>

    {!! view_render_event('admin.leads.index.kanban.toolbar.switcher.after') !!}
</div>

{!! view_render_event('admin.leads.index.kanban.toolbar.after') !!}
