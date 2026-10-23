<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.leads.index.title')
    </x-slot>

    <!-- Header -->
    {!! view_render_event('admin.leads.index.header.before') !!}

    <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:flex-wrap max-lg:gap-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
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
            <!-- Control de sincronización con ShareMeData -->
            @if (bouncer()->hasPermission('activities'))
                <v-smd-sync-control></v-smd-sync-control>
            @endif

            <!-- Upload File for Lead Creation -->
            @if(core()->getConfigData('general.magic_ai.doc_generation.enabled'))
                @include('admin::leads.index.upload')
            @endif

            @if ((request()->view_type ?? "kanban") == "table")
                <!-- Export Modal -->
                <x-admin::datagrid.export :src="route('admin.leads.index')" />
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
    <div class="[&>*>*>*.toolbarRight]:max-lg:w-full [&>*>*>*.toolbarRight]:max-lg:justify-between [&>*>*>*.toolbarRight]:max-md:gap-y-2 [&>*>*>*.toolbarRight]:max-md:flex-wrap mt-3.5 [&>*>*:nth-child(1)]:max-lg:!flex-wrap">
        @if ((request()->view_type ?? "kanban") == "table")
            @include('admin::leads.index.table')
        @else
            @include('admin::leads.index.kanban')
        @endif
    </div>

    {!! view_render_event('admin.leads.index.content.after') !!}

    {{-- Helper compartido para sincronizar el rango de fechas con Tablero y Pacientes --}}
    @include('admin::components.global-date-range')

    @if (bouncer()->hasPermission('activities'))
        @pushOnce('scripts')
            @include('admin::components.smd-sync-control')
        @endPushOnce
    @endif
</x-admin::layouts>
