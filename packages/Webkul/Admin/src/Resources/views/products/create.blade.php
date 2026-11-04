
<x-admin::layouts>
    <!-- Page Title -->
    <x-slot:title>
        @lang('admin::app.products.create.title')
    </x-slot>

    {!! view_render_event('admin.products.create.form.before') !!}

    <x-admin::form
        :action="route('admin.products.store')"
        method="POST"
    >
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    {!! view_render_event('admin.products.create.breadcrumbs.before') !!}

                    <!-- Breadcrumbs -->
                    <x-admin::breadcrumbs name="products.create" />

                    {!! view_render_event('admin.products.create.breadcrumbs.after') !!}
                    
                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.products.index" />

                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.products.create.title')
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        {!! view_render_event('admin.products.create.save_button.before') !!}

                        <!-- Create button for Product -->
                        @if (bouncer()->hasPermission('products.create'))
                            <button
                                type="submit"
                                class="primary-button"
                            >
                                @lang('admin::app.products.create.save-btn')
                            </button>
                        @endif

                        {!! view_render_event('admin.products.create.save_button.after') !!}
                    </div>
                </div>
            </div>

            {{-- Celular: secciones de borde a borde. min-w-0 en las columnas: sin eso una tabla ancha estira la página entera; así la tabla se desliza dentro de su sección. --}}
            <div class="flex gap-2.5 max-xl:flex-wrap max-lg:-mx-3 max-lg:gap-2">
                <!-- Left sub-component -->
                <div class="flex min-w-0 flex-1 flex-col gap-2 max-xl:flex-auto max-xl:basis-full max-lg:[&>*]:rounded-none max-lg:[&>*]:border-x-0 max-lg:[&>*]:shadow-none">
                    <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                            @lang('admin::app.products.create.general')
                        </p>

                        {!! view_render_event('admin.products.create.attributes.before') !!}

                        <x-admin::attributes
                            :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                'entity_type' => 'products',
                                ['code', 'NOTIN', ['price', 'quantity', 'type_service_2']],
                            ])"
                        />

                        {!! view_render_event('admin.products.create.attributes.after') !!}
                    </div>

                    <!-- Datos del servicio (Tessa) -->
                    <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                            {{ __('Datos del servicio') }}
                        </p>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                {{ __('Linea') }}
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="select"
                                name="specialty_id"
                                :value="old('specialty_id', '')"
                            >
                                <option value="">{{ __('— Sin especialidad —') }}</option>
                                @foreach ($specialties as $specialty)
                                    <option value="{{ $specialty->id }}">{{ $specialty->name }}</option>
                                @endforeach
                            </x-admin::form.control-group.control>

                            <x-admin::form.control-group.error field-name="specialty_id" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                {{ __('Sesión y frecuencia') }}
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="textarea"
                                name="frequency_text"
                                :value="old('frequency_text', '')"
                                placeholder="Cada 90 días, como plazo mínimo…"
                            />

                            <x-admin::form.control-group.error field-name="frequency_text" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                {{ __('Intervalo mínimo entre sesiones (días)') }}
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="number"
                                name="min_interval_days"
                                :value="old('min_interval_days', '')"
                                placeholder="30"
                                min="1"
                                max="3650"
                            />

                            <x-admin::form.control-group.error field-name="min_interval_days" />
                        </x-admin::form.control-group>

                        <div class="flex gap-4">
                            <x-admin::form.control-group class="flex-1">
                                <x-admin::form.control-group.label>
                                    {{ __('Sesiones sugeridas (mín)') }} <span class="text-gray-400">({{ __('opcional') }})</span>
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="number"
                                    name="recommended_sessions_min"
                                    :value="old('recommended_sessions_min', '')"
                                    min="0"
                                    max="100"
                                    placeholder="{{ __('Opcional') }}"
                                />

                                <x-admin::form.control-group.error field-name="recommended_sessions_min" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="flex-1">
                                <x-admin::form.control-group.label>
                                    {{ __('Sesiones sugeridas (máx)') }} <span class="text-gray-400">({{ __('opcional') }})</span>
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="number"
                                    name="recommended_sessions_max"
                                    :value="old('recommended_sessions_max', '')"
                                    min="0"
                                    max="100"
                                    placeholder="{{ __('Opcional') }}"
                                />

                                <x-admin::form.control-group.error field-name="recommended_sessions_max" />
                            </x-admin::form.control-group>
                        </div>
                    </div>
                </div>

                <!-- Right sub-component -->
                <div class="flex w-[360px] min-w-0 max-w-full flex-col gap-2 max-sm:w-full max-lg:[&>*]:rounded-none max-lg:[&>*]:border-x-0">
                    {!! view_render_event('admin.products.create.accordion.before') !!}

                    <x-admin::accordion>
                        <x-slot:header>
                            {!! view_render_event('admin.products.create.accordion.header.before') !!}

                            <div class="flex items-center justify-between">
                                <p class="p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                                    @lang('admin::app.products.create.price')
                                </p>
                            </div>

                            {!! view_render_event('admin.products.create.accordion.header.after') !!}
                        </x-slot>

                        <x-slot:content>
                            {!! view_render_event('admin.products.create.accordion.content.attributes.before') !!}

                            <x-admin::attributes
                                :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                    'entity_type' => 'products',
                                    ['code', 'IN', ['price', 'quantity']],
                                ])"
                            />

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>
                                    {{ __('Duración (minutos)') }}
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="number"
                                    name="duration_minutes"
                                    :value="old('duration_minutes', '')"
                                    placeholder="60"
                                    min="1"
                                    max="480"
                                />

                                <x-admin::form.control-group.error field-name="duration_minutes" />
                            </x-admin::form.control-group>

                            {!! view_render_event('admin.products.create.accordion.content.attributes.after') !!}
                        </x-slot>
                    </x-admin::accordion>

                    {!! view_render_event('admin.products.create.accordion.before') !!}
                </div>
            </div>

            <!-- Zonas del servicio (Tessa) -->
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">
                    {{ __('Zonas') }}
                </p>

                <p class="mb-4 text-sm text-gray-500 dark:text-gray-300">
                    {{ __('Cada zona es una variante del servicio, con su propia duración y precio. Se guardan junto con el servicio.') }}
                </p>

                <v-create-service-zones></v-create-service-zones>

                <p class="mt-4 flex items-start gap-2 text-sm text-gray-500 dark:text-gray-300">
                    <i class="icon-info text-xl"></i>
                    <span>{{ __('Las promociones se agregan al editar el servicio, una vez creado.') }}</span>
                </p>
            </div>
        </div>
    </x-admin::form>

    {!! view_render_event('admin.products.create.form.after') !!}

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-create-service-zones-template"
        >
            <div class="flex flex-col gap-4">
                <div class="block w-full overflow-x-auto" v-if="zones.length">
                    <x-admin::table>
                        <x-admin::table.thead>
                            <x-admin::table.thead.tr>
                                <x-admin::table.th>{{ __('Zona') }}</x-admin::table.th>
                                <x-admin::table.th class="!w-[150px]">{{ __('Duración (min)') }}</x-admin::table.th>
                                <x-admin::table.th class="!w-[150px]">{{ __('Precio') }}</x-admin::table.th>
                                <x-admin::table.th class="!w-[50px]"></x-admin::table.th>
                            </x-admin::table.thead.tr>
                        </x-admin::table.thead>

                        <x-admin::table.tbody>
                            <x-admin::table.tbody.tr
                                v-for="(zone, index) in zones"
                                ::key="index"
                            >
                                <x-admin::table.td class="!px-2">
                                    <input
                                        type="text"
                                        v-model="zone.name"
                                        :name="`zones[${index}][name]`"
                                        placeholder="Axilas"
                                        class="w-full rounded-md border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    />
                                </x-admin::table.td>

                                <x-admin::table.td class="!px-2">
                                    <input
                                        type="number"
                                        min="1"
                                        max="480"
                                        v-model="zone.duration_minutes"
                                        :name="`zones[${index}][duration_minutes]`"
                                        class="w-full rounded-md border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    />
                                </x-admin::table.td>

                                <x-admin::table.td class="!px-2">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        v-model="zone.price"
                                        :name="`zones[${index}][price]`"
                                        class="w-full rounded-md border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    />
                                </x-admin::table.td>

                                <x-admin::table.td class="!px-2">
                                    <button
                                        type="button"
                                        @click="removeZone(index)"
                                        aria-label="{{ __('Eliminar zona') }}"
                                        class="cursor-pointer leading-none text-gray-600 transition-colors hover:text-red-600 dark:text-gray-300 dark:hover:text-red-400"
                                    >
                                        <i class="icon-delete text-2xl"></i>
                                    </button>
                                </x-admin::table.td>
                            </x-admin::table.tbody.tr>
                        </x-admin::table.tbody>
                    </x-admin::table>
                </div>

                <p
                    v-if="! zones.length"
                    class="py-2 text-sm text-gray-400 dark:text-gray-400"
                >
                    {{ __('Todavía no agregaste zonas.') }}
                </p>

                <button
                    type="button"
                    class="flex max-w-max items-center gap-2 text-brandColor"
                    @click="addZone"
                >
                    <i class="icon-add text-md !text-brandColor"></i>
                    {{ __('Agregar zona') }}
                </button>
            </div>
        </script>

        <script type="module">
            app.component('v-create-service-zones', {
                template: '#v-create-service-zones-template',

                data() {
                    return {
                        zones: [],
                    };
                },

                methods: {
                    addZone() {
                        this.zones.push({ name: '', duration_minutes: 60, price: 0 });
                    },

                    removeZone(index) {
                        this.zones.splice(index, 1);
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
