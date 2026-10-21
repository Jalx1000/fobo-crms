<x-admin::layouts>
    <x-slot:title>Metas de venta</x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-1">
                <div class="text-xl font-bold dark:text-white">Metas de venta</div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    El objetivo se fija acá; el avance lo calcula el tablero contra los leads cerrados.
                    Una meta sin asesor es la del equipo.
                </p>
            </div>

            @if (bouncer()->hasPermission('settings.lead.metas.create'))
                <a href="{{ route('admin.settings.metas.create') }}" class="primary-button shrink-0">
                    Nueva meta
                </a>
            @endif
        </div>

        <x-admin::datagrid :src="route('admin.settings.metas.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
