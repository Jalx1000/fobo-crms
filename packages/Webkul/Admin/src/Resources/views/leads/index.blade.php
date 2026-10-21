<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.leads.index.title')
    </x-slot>

    <!-- Header -->
    {!! view_render_event('admin.leads.index.header.before') !!}

    {{-- Celular: de borde a borde, sin el gris de alrededor. --}}
    <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
        {!! view_render_event('admin.leads.index.header.left.before') !!}

        <div class="flex flex-col gap-2">
            <!-- Breadcrumb's -->
            <x-admin::breadcrumbs name="leads" />

            <div class="text-xl font-bold dark:text-white">
                @lang('admin::app.leads.index.title')
            </div>
        </div>

        {!! view_render_event('admin.leads.index.header.left.after') !!}

        {!! view_render_event('admin.leads.index.header.right.before') !!}

        <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
            <!-- Quick Date Filters -->
            @php
                $clearDateUrl = request()->fullUrlWithQuery(['date_filter' => 'none', 'date_from' => null, 'date_to' => null]);
            @endphp
            {{-- Celular: Hoy / Esta semana / Este mes están en «Filtros». --}}
            <div class="flex gap-2 max-lg:hidden">
                <a
                    href="{{ request('date_filter') == 'today' ? $clearDateUrl : request()->fullUrlWithQuery(['date_filter' => 'today', 'date_from' => null, 'date_to' => null]) }}"
                    class="secondary-button {{ request('date_filter') == 'today' ? 'active' : '' }}"
                    style="{{ request('date_filter') == 'today' ? 'background: #eee;' : '' }}"
                >
                    @lang('admin::app.components.datagrid.filters.date-options.today')
                </a>
                <a
                    href="{{ request('date_filter') == 'week' ? $clearDateUrl : request()->fullUrlWithQuery(['date_filter' => 'week', 'date_from' => null, 'date_to' => null]) }}"
                    class="secondary-button {{ request('date_filter') == 'week' ? 'active' : '' }}"
                    style="{{ request('date_filter') == 'week' ? 'background: #eee;' : '' }}"
                >
                    @lang('admin::app.components.datagrid.filters.date-options.this-week')
                </a>
                <a
                    href="{{ request('date_filter') == 'month' ? $clearDateUrl : request()->fullUrlWithQuery(['date_filter' => 'month', 'date_from' => null, 'date_to' => null]) }}"
                    class="secondary-button {{ request('date_filter') == 'month' ? 'active' : '' }}"
                    style="{{ request('date_filter') == 'month' ? 'background: #eee;' : '' }}"
                >
                    @lang('admin::app.components.datagrid.filters.date-options.this-month')
                </a>
            </div>

            <!-- Upload File for Lead Creation -->
            @if(core()->getConfigData('general.magic_ai.doc_generation.enabled'))
                @include('admin::leads.index.upload')
            @endif

            @if ((request()->view_type ?? "kanban") == "table")
                <!-- Export Modal -->
                <x-admin::datagrid.export
                    file-name="pedidos"
                    :src="route('admin.leads.index', array_merge(
                        request()->only(['date_filter', 'date_from', 'date_to']),
                        ['pipeline_id' => request('pipeline_id')]
                    ))"
                />
            @endif

            <!-- Create button for Leads -->
            <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                @if (bouncer()->hasPermission('leads.create'))
                    <a
                        href="{{ route('admin.leads.create', request()->query()) }}"
                        class="primary-button"
                    >
                        @lang('admin::app.leads.index.create-btn')
                    </a>
                @endif
            </div>
        </div>

        {!! view_render_event('admin.leads.index.header.right.after') !!}
    </div>

    {!! view_render_event('admin.leads.index.header.after') !!}

    {!! view_render_event('admin.leads.index.content.before') !!}

    <!-- Content -->
    <div class="[&>*>*>*.toolbarRight]:max-lg:w-full [&>*>*>*.toolbarRight]:max-lg:justify-between [&>*>*>*.toolbarRight]:max-md:gap-y-2 [&>*>*>*.toolbarRight]:max-md:flex-wrap mt-3.5 [&>*>*:nth-child(1)]:max-lg:!flex-wrap max-lg:mt-0">
        @if ((request()->view_type ?? "kanban") == "table")
            @include('admin::leads.index.table')
        @else
            @include('admin::leads.index.kanban')
        @endif
    </div>

    {!! view_render_event('admin.leads.index.content.after') !!}
</x-admin::layouts>
