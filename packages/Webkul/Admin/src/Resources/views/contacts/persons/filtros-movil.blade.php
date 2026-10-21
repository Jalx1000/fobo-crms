{{--
    Celular (debajo de lg), igual que en Pedidos y Cotizaciones: el buscador
    queda siempre a la vista y el resto de los filtros -ciudad, período y rango
    de fechas- vive en este panel lateral, detrás del botón «Filtros».

    Todo navega por URL (pipeline_id, start, end), como
    los filtros de escritorio: PersonController::syncGlobalFilters guarda la
    ciudad y el rango en las cookies compartidas con el tablero y Pedidos.
--}}
@php
    $ruta = fn (array $cambios) => route('admin.contacts.persons.index', array_merge(request()->except(array_merge(array_keys($cambios), ['page'])), $cambios));

    $hoy = now()->toDateString();

    $periodos = [
        '7 días'   => now()->subDays(6)->toDateString(),
        '30 días'  => now()->subDays(29)->toDateString(),
        '90 días'  => now()->subDays(89)->toDateString(),
        'Este mes' => now()->startOfMonth()->toDateString(),
    ];

    $activos = collect([
        is_numeric(request('pipeline_id')),
        request('start') || request('end'),
    ])->filter()->count();
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
            <!-- Ciudad -->
            <label class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.ciudad')</span>

                <select
                    class="h-11 w-full rounded-lg border bg-white px-3 dark:border-gray-800 dark:bg-gray-900"
                    onchange="window.location = this.value"
                >
                    {{-- Vacío y no ausente: «Todas» tiene que pisar la ciudad recordada. --}}
                    <option value="{{ $ruta(['pipeline_id' => '']) }}">Todas las ciudades</option>

                    @foreach ($pipelines as $opcion)
                        <option
                            value="{{ $ruta(['pipeline_id' => $opcion->id]) }}"
                            @selected((string) request('pipeline_id') === (string) $opcion->id)
                        >
                            {{ $opcion->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <!-- Período -->
            <div class="flex flex-col gap-1.5">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.periodo')</span>

                <div class="grid grid-cols-2 gap-2">
                    @foreach ($periodos as $etiqueta => $desde)
                        @php($elegido = request('start') === $desde && request('end') === $hoy)

                        <a
                            href="{{ $ruta(['start' => $desde, 'end' => $hoy]) }}"
                            @class([
                                'flex h-11 items-center justify-center rounded-lg border text-center text-sm font-medium transition-all',
                                'border-brandColor bg-brandColor text-white' => $elegido,
                                'bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => ! $elegido,
                            ])
                        >
                            {{ $etiqueta }}
                        </a>
                    @endforeach
                </div>
            </div>

            <!-- Rango de fechas: los selectores de fecha del propio celular. -->
            <form
                method="GET"
                action="{{ route('admin.contacts.persons.index') }}"
                class="flex flex-col gap-1.5"
            >
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.rango')</span>

                @foreach (request()->except(['start', 'end', 'page', 'clear_date']) as $nombre => $valor)
                    @if (is_scalar($valor))
                        <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                    @endif
                @endforeach

                <div class="flex gap-2">
                    <input
                        type="date"
                        name="start"
                        value="{{ request('start') }}"
                        required
                        aria-label="Fecha inicio"
                        class="h-11 w-1/2 min-w-0 rounded-lg border bg-white px-2 dark:border-gray-800 dark:bg-gray-900"
                    >

                    <input
                        type="date"
                        name="end"
                        value="{{ request('end') }}"
                        required
                        aria-label="Fecha fin"
                        class="h-11 w-1/2 min-w-0 rounded-lg border bg-white px-2 dark:border-gray-800 dark:bg-gray-900"
                    >
                </div>

                <button type="submit" class="primary-button h-11 w-full justify-center">Aplicar</button>
            </form>

            @if ($activos)
                <a
                    href="{{ route('admin.contacts.persons.index', ['pipeline_id' => '', 'clear_date' => 1]) }}"
                    class="flex h-11 items-center justify-center rounded-lg text-sm font-semibold text-rose-600"
                >
                    @lang('admin::app.leads.index.filtros-movil.limpiar')
                </a>
            @endif
        </div>
    </x-slot>
</x-admin::drawer>
