<x-admin::layouts>
    <x-slot:title>
        Especialidades
    </x-slot>

    {{-- Celular: de borde a borde, sin el gris de alrededor (como Servicios y Pacientes). --}}
    <div class="flex flex-col gap-4 max-lg:gap-0">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Listado de Especialidades
                </div>
            </div>

            <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                <a
                    href="{{ route('admin.specialties.sync') }}"
                    class="secondary-button"
                >
                    Sincronizar con ShareMeData
                </a>
                <a
                    href="{{ route('admin.specialties.create') }}"
                    class="primary-button"
                >
                    Crear Especialidad
                </a>
            </div>
        </div>

        <div class="max-lg:-mx-3 max-lg:[&_.datagrid-toolbar]:gap-2 max-lg:[&_.datagrid-toolbar]:border-t-0 max-lg:[&_.datagrid-toolbar]:px-3 max-lg:[&_.datagrid-toolbar]:py-2 max-lg:[&_.toolbarLeft]:w-full max-lg:[&_.rounded-t-lg]:rounded-none max-lg:[&_.rounded-t-lg]:border-x-0 max-lg:[&_.table-responsive]:rounded-none max-lg:[&_.table-responsive]:border-x-0">
        <v-specialties>
            <x-admin::shimmer.datagrid :is-multi-row="false"/>
        </v-specialties>
        </div>
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
