{{--
    Celular (debajo de lg): el buscador queda siempre a la vista y el resto de
    los filtros -ciudad, período, rango de fechas y vista- vive en este panel
    lateral, detrás del botón «Filtros».

    Son los mismos selectores de escritorio: navegan por URL, así que la
    elección sobrevive a una recarga y a un enlace compartido.
--}}
@php
    $conservar = request()->only(['date_filter', 'date_from', 'date_to', 'view_type']);

    $periodos = [
        'today' => __('admin::app.components.datagrid.filters.date-options.today'),
        'week'  => __('admin::app.components.datagrid.filters.date-options.this-week'),
        'month' => __('admin::app.components.datagrid.filters.date-options.this-month'),
    ];

    $periodoElegido = request('date_filter');

    $activos = collect([
        $periodoElegido && $periodoElegido !== 'none',
        request('date_from') || request('date_to'),
    ])->filter()->count();

    $pipelineElegido = request('pipeline_id') === 'all' ? 'all' : ($pipeline->id ?? null);
@endphp

<x-admin::drawer
    position="right"
    width="340px"
    class="lg:hidden [&>:nth-child(3)]:max-sm:!w-[88%]"
>
    <x-slot:toggle>
        <button
            type="button"
            class="relative flex h-10 shrink-0 items-center gap-1.5 rounded-lg border bg-white px-3 text-sm font-medium text-gray-700 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            aria-label="@lang('admin::app.leads.index.filtros-movil.titulo')"
        >
            <span class="icon-filter text-xl"></span>

            @lang('admin::app.leads.index.filtros-movil.titulo')

            @if ($activos)
                <span class="flex h-5 min-w-[20px] items-center justify-center rounded-full bg-brandColor px-1 text-[11px] font-bold text-white">{{ $activos }}</span>
            @endif
        </button>
    </x-slot>

    <x-slot:header class="p-3.5">
        <p class="text-lg font-semibold dark:text-white">
            @lang('admin::app.leads.index.filtros-movil.titulo')
        </p>
    </x-slot>

    <x-slot:content class="!p-4">
        <div class="flex flex-col gap-5 text-sm text-gray-700 dark:text-gray-300">
            <!-- Ciudad (pipeline) -->
            <label class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.ciudad')</span>

                <select
                    class="h-11 w-full rounded-lg border bg-white px-3 dark:border-gray-800 dark:bg-gray-900"
                    onchange="window.location = this.value"
                >
                    <option
                        value="{{ route('admin.leads.index', array_merge($conservar, ['pipeline_id' => 'all'])) }}"
                        @selected($pipelineElegido === 'all')
                    >
                        @lang('admin::app.leads.index.view-switcher.all-prospects')
                    </option>

                    @foreach (app('Webkul\Lead\Repositories\PipelineRepository')->all() as $opcion)
                        <option
                            value="{{ route('admin.leads.index', array_merge($conservar, ['pipeline_id' => $opcion->id])) }}"
                            @selected($pipelineElegido == $opcion->id)
                        >
                            {{ $opcion->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <!-- Período -->
            <div class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.periodo')</span>

                <div class="grid grid-cols-3 gap-2">
                    @foreach ($periodos as $valor => $etiqueta)
                        <a
                            href="{{ request()->fullUrlWithQuery(['date_filter' => $periodoElegido === $valor ? 'none' : $valor, 'date_from' => null, 'date_to' => null]) }}"
                            @class([
                                'flex h-11 items-center justify-center rounded-lg border text-center text-sm font-medium transition-all',
                                'border-brandColor bg-brandColor text-white' => $periodoElegido === $valor,
                                'bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => $periodoElegido !== $valor,
                            ])
                        >
                            {{ $etiqueta }}
                        </a>
                    @endforeach
                </div>
            </div>

            <!-- Rango de fechas: las dos fechas en una fila y «Aplicar» a lo ancho debajo. -->
            <div class="flex flex-col gap-1.5 [&_.primary-button]:h-11 [&_.primary-button]:w-full [&_.primary-button]:justify-center [&_.w-\[120px\]]:!w-[calc(50%-12px)] [&_div.flex]:flex-wrap">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.rango')</span>

                <v-custom-date-filter
                    date-from="{{ request('date_from') }}"
                    date-to="{{ request('date_to') }}"
                    date-filter="{{ request('date_filter') }}"
                ></v-custom-date-filter>
            </div>

            <!-- Vista -->
            <div class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.vista')</span>

                <div class="grid grid-cols-2 gap-2">
                    @foreach (['' => 'kanban', 'table' => 'lista'] as $vista => $clave)
                        <a
                            href="{{ route('admin.leads.index', array_merge($conservar, ['view_type' => $vista ?: null, 'pipeline_id' => request('pipeline_id')])) }}"
                            @class([
                                'flex h-11 items-center justify-center gap-2 rounded-lg border text-sm font-medium transition-all',
                                'border-brandColor bg-brandColor text-white' => (string) request('view_type') === $vista,
                                'bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => (string) request('view_type') !== $vista,
                            ])
                        >
                            <span class="{{ $vista ? 'icon-list' : 'icon-kanban' }} text-xl"></span>

                            @lang('admin::app.leads.index.filtros-movil.'.$clave)
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($activos)
                <a
                    href="{{ route('admin.leads.index', ['pipeline_id' => request('pipeline_id'), 'view_type' => request('view_type'), 'date_filter' => 'none']) }}"
                    class="flex h-11 items-center justify-center rounded-lg text-sm font-semibold text-rose-600"
                >
                    @lang('admin::app.leads.index.filtros-movil.limpiar')
                </a>
            @endif
        </div>
    </x-slot>
</x-admin::drawer>
