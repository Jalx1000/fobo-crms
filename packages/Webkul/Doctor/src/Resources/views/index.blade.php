<x-admin::layouts>
    <x-slot:title>
        Sucursales
    </x-slot>

    <!-- Body -->
    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Listado de Sucursales
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                <a
                    href="{{ route('admin.doctor.create') }}"
                    class="primary-button"
                >
                    Crear Sucursal
                </a>
            </div>
        </div>

        <v-doctors>
            <x-admin::shimmer.datagrid :is-multi-row="false"/>
        </v-doctors>
    </div>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-doctors-template"
        >
            <x-admin::datagrid
                src="{{ route('admin.doctor.index') }}"
                ref="datagrid"
                :is-multi-row="false"
            >
                <!-- Usamos el renderizado por defecto del DataGrid para que cada dato se muestre en su propia columna,
                     y que aparezca automáticamente la columna de acciones cuando el DataGrid define acciones. -->
            </x-admin::datagrid>
        </script>

        <script type="module">
            app.component('v-doctors', {
                template: '#v-doctors-template',
            });
        </script>
    @endPushOnce
</x-admin::layouts>
