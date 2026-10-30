{{--
    Celular (debajo de lg): el buscador queda siempre a la vista y el resto
    -embudo, rango de fechas, filtros por columna y vista- vive en este panel
    lateral, detrás del botón «Filtros».

    Un período rápido o «Limpiar» cierran el panel para mostrar el resultado;
    Desde/Hasta no, porque se eligen de a dos.

    Se incluye dentro del template de v-leads-kanban: los controles de fecha
    son los mismos del escritorio (applyQuick / applyCustomRange sobre
    applied.dateFrom y applied.dateTo), así que el rango se comparte con el
    Tablero y Pacientes igual que siempre.
--}}
<x-admin::drawer
    ref="filtrosMovil"
    position="right"
    width="340px"
    class="lg:hidden [&>:nth-child(3)]:max-sm:!w-[88%]"
>
    <x-slot:toggle>
        <button
            type="button"
            class="relative flex h-10 shrink-0 items-center gap-1.5 rounded-lg border bg-white px-3 text-sm font-medium text-gray-700 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
        >
            <span class="icon-filter text-xl" aria-hidden="true"></span>

            @lang('admin::app.leads.index.filtros-movil.titulo')

            <span
                class="flex h-5 min-w-[20px] items-center justify-center rounded-full bg-brandColor px-1 text-[11px] font-bold text-white"
                v-if="filtrosMovilActivos"
                v-text="filtrosMovilActivos"
            ></span>
        </button>
    </x-slot>

    <x-slot:header class="p-3.5">
        <p class="text-lg font-semibold dark:text-white">
            @lang('admin::app.leads.index.filtros-movil.titulo')
        </p>
    </x-slot>

    <x-slot:content class="!p-4">
        <div class="flex flex-col gap-5 text-sm text-gray-700 dark:text-gray-300">
            <!-- Embudo (pipeline) -->
            <label class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.embudo')</span>

                <select
                    class="h-11 w-full rounded-lg border bg-white px-3 text-base dark:border-gray-800 dark:bg-gray-900"
                    @change="$event.target.ownerDocument.location = $event.target.value"
                >
                    @foreach (app('Webkul\Lead\Repositories\PipelineRepository')->all() as $opcion)
                        <option
                            value="{{ route('admin.leads.index', ['pipeline_id' => $opcion->id]) }}"
                            @selected($pipeline->id == $opcion->id)
                        >
                            {{ $opcion->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <!-- Período: los mismos rápidos del escritorio -->
            <div class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.periodo')</span>

                <div class="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        class="flex h-11 items-center justify-center rounded-lg border text-center text-sm font-medium transition-all"
                        :class="applied.quick === range.value
                            ? 'border-brandColor bg-brandColor text-white'
                            : 'bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300'"
                        v-for="range in [
                            { value: '7', label: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.last-7-days') }}' },
                            { value: '30', label: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.last-30-days') }}' },
                            { value: '90', label: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.last-90-days') }}' },
                            { value: 'month', label: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.this-month') }}' },
                        ]"
                        @click="applyQuick(range.value); $refs.filtrosMovil.close()"
                        v-text="range.label"
                    ></button>
                </div>
            </div>

            <!-- Rango personalizado -->
            <div class="grid grid-cols-2 gap-2">
                <label class="flex min-w-0 flex-col gap-1.5">
                    <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.desde')</span>

                    <input
                        type="date"
                        v-model="applied.dateFrom"
                        @change="applyCustomRange"
                        class="h-11 w-full min-w-0 rounded-lg border bg-white px-2 text-base dark:border-gray-800 dark:bg-gray-900"
                    >
                </label>

                <label class="flex min-w-0 flex-col gap-1.5">
                    <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.hasta')</span>

                    <input
                        type="date"
                        v-model="applied.dateTo"
                        @change="applyCustomRange"
                        class="h-11 w-full min-w-0 rounded-lg border bg-white px-2 text-base dark:border-gray-800 dark:bg-gray-900"
                    >
                </label>
            </div>

            <!-- Filtros por columna: el panel de siempre (v-kanban-filter) -->
            <button
                type="button"
                class="flex h-11 items-center justify-between rounded-lg border bg-white px-3 text-sm font-medium dark:border-gray-800 dark:bg-gray-900"
                @click="abrirFiltrosPorColumna"
            >
                @lang('admin::app.leads.index.filtros-movil.por-columna')

                <span class="flex items-center gap-2">
                    <span
                        class="flex h-5 min-w-[20px] items-center justify-center rounded-full bg-brandColor px-1 text-[11px] font-bold text-white"
                        v-if="columnasFiltradas"
                        v-text="columnasFiltradas"
                    ></span>

                    <span class="icon-right-arrow text-lg" aria-hidden="true"></span>
                </span>
            </button>

            <!-- Vista -->
            <div class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.vista')</span>

                <div class="grid grid-cols-2 gap-2">
                    <span class="flex h-11 items-center justify-center gap-2 rounded-lg border border-brandColor bg-brandColor text-sm font-medium text-white">
                        <span class="icon-kanban text-xl" aria-hidden="true"></span>

                        @lang('admin::app.leads.index.filtros-movil.kanban')
                    </span>

                    <a
                        href="{{ route('admin.leads.index', ['view_type' => 'table', 'pipeline_id' => request('pipeline_id')]) }}"
                        class="flex h-11 items-center justify-center gap-2 rounded-lg border bg-white text-sm font-medium text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <span class="icon-list text-xl" aria-hidden="true"></span>

                        @lang('admin::app.leads.index.filtros-movil.lista')
                    </a>
                </div>
            </div>

            <button
                type="button"
                class="flex h-11 items-center justify-center rounded-lg text-sm font-semibold text-rose-600"
                v-if="applied.quick || applied.dateFrom || applied.dateTo"
                @click="clearDateFilters(); $refs.filtrosMovil.close()"
            >
                @lang('admin::app.leads.index.filtros-movil.limpiar')
            </button>
        </div>
    </x-slot>
</x-admin::drawer>
