<x-admin::layouts>
    <x-slot:title>Inmuebles</x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">Inmuebles</div>
                <p class="text-gray-500 dark:text-gray-400">
                    Solo los inmuebles en estado <b>Disponible</b> los ofrece el agente de WhatsApp.
                </p>
            </div>

            @if (bouncer()->hasPermission('inmuebles.create'))
                <a href="{{ route('admin.inmuebles.create') }}" class="primary-button">
                    Nuevo inmueble
                </a>
            @endif
        </div>

        <x-admin::datagrid :src="route('admin.inmuebles.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
