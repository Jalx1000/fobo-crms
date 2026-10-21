<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.leads.index.title')
    </x-slot>

    <!-- Header -->
    {!! view_render_event('admin.leads.index.header.before') !!}

    {{-- Escritorio: título a la izquierda y acciones a la derecha, en una fila.

         Teléfono (< md): el contenedor de acciones pasa a `display: contents`,
         así sus hijos se acomodan directamente en este flex y se reordenan
         con `order`:
           1. título + botón Crear (la acción principal, al alcance del pulgar)
           2. filtros rápidos de fecha, como control segmentado a todo el ancho
           3. acciones secundarias (subir archivo, exportar) --}}
    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 max-md:flex-wrap max-md:py-3">
        {!! view_render_event('admin.leads.index.header.left.before') !!}

        <div class="flex flex-col gap-2 max-md:order-1 max-md:min-w-0 max-md:flex-1">
            <!-- Breadcrumb's -->
            <x-admin::breadcrumbs name="leads" />

            <h1 class="text-xl font-bold dark:text-white max-md:truncate">
                @lang('admin::app.leads.index.title')
            </h1>
        </div>

        {!! view_render_event('admin.leads.index.header.left.after') !!}

        {!! view_render_event('admin.leads.index.header.right.before') !!}

        <div class="flex items-center gap-x-2.5 max-md:contents">
            <!-- Quick Date Filters -->
            @php
                $clearDateUrl = request()->fullUrlWithQuery(['date_filter' => 'none', 'date_from' => null, 'date_to' => null]);

                $quickDateFilters = [
                    'today' => trans('admin::app.components.datagrid.filters.date-options.today'),
                    'week'  => trans('admin::app.components.datagrid.filters.date-options.this-week'),
                    'month' => trans('admin::app.components.datagrid.filters.date-options.this-month'),
                ];
            @endphp

            {{-- El activo se distingue por relleno (antes era un gris #eee casi
                 igual al fondo) y lo anuncia `aria-current`. Tocarlo de nuevo
                 quita el filtro: por eso lleva la ✕ y su propio aria-label. --}}
            <div
                class="flex gap-2 max-md:order-3 max-md:grid max-md:w-full max-md:grid-cols-3"
                role="group"
                aria-label="Filtro rápido por fecha"
            >
                @foreach ($quickDateFilters as $value => $label)
                    @php
                        $isActive = request('date_filter') == $value;
                    @endphp

                    <a
                        href="{{ $isActive ? $clearDateUrl : request()->fullUrlWithQuery(['date_filter' => $value, 'date_from' => null, 'date_to' => null]) }}"
                        class="{{ $isActive ? 'primary-button' : 'secondary-button' }} whitespace-nowrap max-md:min-h-[44px] max-md:px-2"
                        @if ($isActive)
                            aria-current="true"
                            aria-label="{{ $label }} (quitar filtro)"
                        @endif
                    >
                        {{ $label }}

                        @if ($isActive)
                            <span class="icon-cross-large text-base" aria-hidden="true"></span>
                        @endif
                    </a>
                @endforeach
            </div>

            <!-- Secondary actions -->
            @php
                $showUpload = core()->getConfigData('general.magic_ai.doc_generation.enabled');
                $showExport = (request()->view_type ?? 'kanban') == 'table';
            @endphp

            {{-- Solo si hay alguna: en el teléfono es una fila entera y vacía
                 dejaría un hueco. `:empty` no sirve porque los comentarios y
                 espacios cuentan como contenido. --}}
            @if ($showUpload || $showExport)
            <div class="flex items-center gap-x-2.5 max-md:order-4 max-md:w-full max-md:[&>*]:flex-1 max-md:[&_button]:min-h-[44px] max-md:[&_button]:w-full">
                <!-- Upload File for Lead Creation -->
                @if ($showUpload)
                    @include('admin::leads.index.upload')
                @endif

                @if ($showExport)
                    <!-- Export Modal -->
                    <x-admin::datagrid.export
                        file-name="pedidos"
                        :src="route('admin.leads.index', array_merge(
                            request()->only(['date_filter', 'date_from', 'date_to']),
                            ['pipeline_id' => request('pipeline_id')]
                        ))"
                    />
                @endif
            </div>
            @endif

            <!-- Create button for Leads -->
            @if (bouncer()->hasPermission('leads.create'))
                <a
                    href="{{ route('admin.leads.create', request()->query()) }}"
                    class="primary-button shrink-0 max-md:order-2 max-md:min-h-[44px]"
                >
                    @lang('admin::app.leads.index.create-btn')
                </a>
            @endif
        </div>

        {!! view_render_event('admin.leads.index.header.right.after') !!}
    </div>

    {!! view_render_event('admin.leads.index.header.after') !!}

    {!! view_render_event('admin.leads.index.content.before') !!}

    <!-- Content -->
    <div class="[&>*>*>*.toolbarRight]:max-lg:w-full [&>*>*>*.toolbarRight]:max-lg:justify-between [&>*>*>*.toolbarRight]:max-md:gap-y-2 [&>*>*>*.toolbarRight]:max-md:flex-wrap mt-3.5 [&>*>*:nth-child(1)]:max-lg:!flex-wrap">
        @if ((request()->view_type ?? "kanban") == "table")
            @include('admin::leads.index.table')
        @else
            @include('admin::leads.index.kanban')
        @endif
    </div>

    {!! view_render_event('admin.leads.index.content.after') !!}
</x-admin::layouts>
