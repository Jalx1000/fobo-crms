{!! view_render_event('admin.leads.index.table.before') !!}

{{-- Celular: la tabla de borde a borde, sin el gris de los costados. --}}
{{-- La barra (buscador + filtros) igual que la del kanban: banda blanca compacta, sin doble línea arriba. --}}
<div class="max-lg:-mx-3 max-lg:[&_.datagrid-toolbar]:gap-2 max-lg:[&_.datagrid-toolbar]:border-t-0 max-lg:[&_.datagrid-toolbar]:px-3 max-lg:[&_.datagrid-toolbar]:py-2 max-lg:[&_.toolbarLeft]:w-full max-lg:[&_.toolbarLeft]:gap-x-2 max-lg:[&_.rounded-t-lg]:rounded-none max-lg:[&_.table-responsive]:rounded-none max-lg:[&_.table-responsive]:border-x-0 max-lg:[&_.rounded-t-lg]:border-x-0 max-lg:[&_.toolbarRight]:w-full max-lg:[&_.toolbarRight]:flex-wrap max-lg:[&_.toolbarRight]:justify-between max-lg:[&_.toolbarRight]:gap-y-2">
<x-admin::datagrid :src="route('admin.leads.index')">
    <!-- DataGrid Shimmer -->
    <x-admin::shimmer.datagrid />

    <x-slot:toolbar-right-after>
        @include('admin::leads.index.view-switcher')
    </x-slot>
</x-admin::datagrid>
</div>

{!! view_render_event('admin.leads.index.table.after') !!}
