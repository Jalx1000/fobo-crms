<x-admin::layouts>
    <x-slot:title>
        {{ isset($specialty) ? 'Editar Especialidad' : 'Crear Especialidad' }}
    </x-slot>

    <x-admin::form
        :action="isset($specialty) ? route('admin.specialties.update', $specialty->id) : route('admin.specialties.store')"
        method="{{ isset($specialty) ? 'PUT' : 'POST' }}"
    >
        {{-- Celular: de borde a borde, sin el gris de alrededor (como Servicios y Pacientes). --}}
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.specialties.index" />

                        <div class="text-xl font-bold dark:text-white">
                            {{ isset($specialty) ? 'Editar Especialidad' : 'Crear Especialidad' }}
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    {{-- En el celular ya está la «←» junto al título. --}}
                    <a href="{{ route('admin.specialties.index') }}" class="secondary-button max-lg:hidden">
                        Volver al listado
                    </a>
                    <button type="submit" class="primary-button">
                        Guardar
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                <div class="grid grid-cols-2 gap-4 max-lg:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Nombre
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="text"
                            id="name"
                            name="name"
                            rules="required|min:2|max:150"
                            :label="trans('Nombre')"
                            :placeholder="trans('Nombre de la especialidad')"
                            :value="old('name', isset($specialty) ? $specialty->name : '')"
                            v-debounce="500"
                        />
                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2 max-lg:col-span-1">
                        <x-admin::form.control-group.label>
                            Descripción
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="textarea"
                            id="description"
                            name="description"
                            tinymce="true"
                            :label="trans('Descripción')"
                            :placeholder="trans('Describe la especialidad')"
                            :value="old('description', isset($specialty) ? $specialty->description : '')"
                        />
                        <x-admin::form.control-group.error control-name="description" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
