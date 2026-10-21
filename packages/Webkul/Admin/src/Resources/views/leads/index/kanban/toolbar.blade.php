{!! view_render_event('admin.leads.index.kanban.toolbar.before') !!}

{{-- Celular: banda blanca de borde a borde con el buscador y el botón «Filtros». --}}
<div class="flex justify-between gap-2 max-lg:-mx-3 max-lg:border-b max-lg:border-gray-200 max-lg:bg-white max-lg:px-3 max-lg:py-2 max-md:flex-wrap dark:max-lg:border-gray-800 dark:max-lg:bg-gray-900">
    <div class="flex w-full items-center gap-x-1.5 max-lg:flex-nowrap max-lg:gap-x-2">
        {!! view_render_event('admin.leads.index.kanban.toolbar.search.before') !!}

        <!-- Search Panel -->
        @include('admin::leads.index.kanban.search')

        {!! view_render_event('admin.leads.index.kanban.toolbar.search.after') !!}

        <!-- Celular: el resto de los filtros, en el panel lateral -->
        @include('admin::leads.index.filtros-movil')

        <!-- Date Filters -->
        <div class="max-lg:hidden lg:contents">
            @include('admin::leads.index.date-filters')
        </div>

        {!! view_render_event('admin.leads.index.kanban.toolbar.filter.before') !!}

        <!-- Filter -->
        @include('admin::leads.index.kanban.filter')

        {!! view_render_event('admin.leads.index.kanban.toolbar.filter.after') !!}

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
