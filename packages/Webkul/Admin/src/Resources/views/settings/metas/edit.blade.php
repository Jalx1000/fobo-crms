<x-admin::layouts>
    <x-slot:title>Editar meta</x-slot>

    <x-admin::form :action="route('admin.settings.metas.update', $meta->id)" method="PUT">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xl font-bold dark:text-white">
                    Editar meta · {{ $meta->periodo }}
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.settings.metas.index') }}" class="secondary-button">Cancelar</a>
                    <button type="submit" class="primary-button">Guardar</button>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                @include('admin::settings.metas._form')
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
