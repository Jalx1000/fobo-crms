<x-admin::layouts>
    <x-slot:title>
        {{ isset($specialty) ? 'Editar Línea' : 'Crear Línea' }}
    </x-slot>

    <x-admin::form
        :action="isset($specialty) ? route('admin.specialties.update', $specialty->id) : route('admin.specialties.store')"
        method="{{ isset($specialty) ? 'PUT' : 'POST' }}"
    >
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <div class="text-xl font-bold dark:text-white">
                        {{ isset($specialty) ? 'Editar Línea' : 'Crear Línea' }}
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <a href="{{ route('admin.specialties.index') }}" class="secondary-button">
                        Volver al listado
                    </a>
                    <button type="submit" class="primary-button">
                        Guardar
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
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

                    <x-admin::form.control-group class="col-span-2">
                        <x-admin::form.control-group.label>
                            Descripción
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="textarea"
                            id="description"
                            name="description"
                            tinymce="true"
                            :label="trans('Descripción')"
                            :placeholder="trans('Describe la línea')"
                            :value="old('description', isset($specialty) ? $specialty->description : '')"
                        />
                        <x-admin::form.control-group.error control-name="description" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
