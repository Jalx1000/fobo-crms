{!! view_render_event('admin.leads.index.table.before') !!}

{{-- Celular: la tabla de borde a borde, sin el gris de los costados. --}}
{{-- La barra (buscador + «Filtros») igual que la del kanban: banda blanca compacta, sin doble línea arriba. --}}
<div class="max-lg:-mx-3 max-lg:[&_.datagrid-toolbar]:gap-2 max-lg:[&_.datagrid-toolbar]:border-t-0 max-lg:[&_.datagrid-toolbar]:px-3 max-lg:[&_.datagrid-toolbar]:py-2 max-lg:[&_.toolbarLeft]:w-full max-lg:[&_.toolbarLeft]:gap-x-2 max-lg:[&_.rounded-t-lg]:rounded-none max-lg:[&_.table-responsive]:rounded-none max-lg:[&_.table-responsive]:border-x-0 max-lg:[&_.rounded-t-lg]:border-x-0">
<x-admin::datagrid :src="route('admin.leads.index', ['pipeline_id' => request('pipeline_id')])">
    <!-- DataGrid Shimmer -->
    <x-admin::shimmer.datagrid />

    <x-slot:toolbar-left-after>
        <!-- Celular: el resto de los filtros, en el panel lateral -->
        @include('admin::leads.index.filtros-movil')

        <div class="max-lg:hidden lg:contents">
            @include('admin::leads.index.date-filters')
        </div>
    </x-slot:toolbar-left-after>

    <x-slot:toolbar-right-after>
        <div class="max-lg:hidden lg:contents">
            @include('admin::leads.index.view-switcher')
        </div>
    </x-slot>
</x-admin::datagrid>
</div>

@push('styles')
    <style>
        .toolbarLeft > div > div > div > .relative.flex.cursor-pointer.items-center {
            display: none !important;
        }
    </style>
@endpush

{!! view_render_event('admin.leads.index.table.after') !!}