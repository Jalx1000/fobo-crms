{{--
    Celular (debajo de lg), igual que en Pedidos: el buscador queda siempre a
    la vista y el resto de los filtros -ejecutivo y plataforma- vive en este
    panel lateral, detrás del botón «Filtros». Los filtros por columna de la
    tabla (vendedor, cliente, montos, fechas) se abren desde aquí.

    Va en el slot de la barra de la tabla: `$refs.filtrosMovil` es este panel.
--}}
@php
    $parametroEjecutivo = \Webkul\Admin\Helpers\FiltroEjecutivo::PARAMETRO;

    $activos = collect([
        request('platform'),
        \Webkul\Admin\Helpers\FiltroEjecutivo::elegido(),
    ])->filter()->count();
@endphp

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
            <!-- Ejecutivo comercial (solo Administrador y Supervisores) -->
            @if (\Webkul\Admin\Helpers\FiltroEjecutivo::disponible())
                <div class="flex flex-col gap-1.5 [&>select]:h-11 [&>select]:!w-full">
                    <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.ejecutivo')</span>

                    @include('admin::leads.index.ejecutivo-filter', ['rutaEjecutivo' => 'admin.quotes.index'])
                </div>
            @endif

            <!-- Plataforma -->
            <div class="flex flex-col gap-1.5 [&>select]:h-11 [&>select]:!w-full">
                <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.plataforma')</span>

                @include('admin::leads.index.platform-filter', ['rutaPlataforma' => 'admin.quotes.index'])
            </div>

            <!-- Filtros por columna de la tabla: cierra este panel y abre el suyo. -->
            <button
                type="button"
                class="flex h-11 items-center justify-center gap-2 rounded-lg border bg-white text-sm font-medium text-gray-700 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                @click="$refs.filtrosMovil.close(); $event.target.ownerDocument.querySelector('.datagrid-filtro')?.click()"
            >
                <span class="icon-filter text-xl"></span>

                @lang('admin::app.leads.index.filtros-movil.por-columna')
            </button>

            @if ($activos)
                <a
                    href="{{ route('admin.quotes.index', [$parametroEjecutivo => '']) }}"
                    class="flex h-11 items-center justify-center rounded-lg text-sm font-semibold text-rose-600"
                >
                    @lang('admin::app.leads.index.filtros-movil.limpiar')
                </a>
            @endif
        </div>
    </x-slot>
</x-admin::drawer>
