<x-admin::layouts>
    <x-slot:title>
        Línea
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Listado de Líneas
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                <a
                    href="{{ route('admin.specialties.create') }}"
                    class="primary-button"
                >
                    Crear Línea
                </a>
            </div>
        </div>

        <v-specialties>
            <x-admin::shimmer.datagrid :is-multi-row="false"/>
        </v-specialties>
    </div>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-specialties-template"
        >
            <x-admin::datagrid
                src="{{ route('admin.specialties.index') }}"
                ref="datagrid"
                :is-multi-row="false"
            >
            </x-admin::datagrid>
        </script>

        <script type="module">
            app.component('v-specialties', {
                template: '#v-specialties-template',
            });
        </script>
    @endPushOnce
</x-admin::layouts>
