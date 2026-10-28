
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
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    {!! view_render_event('admin.products.create.breadcrumbs.before') !!}

                    <!-- Breadcrumbs -->
                    <x-admin::breadcrumbs name="products.create" />

                    {!! view_render_event('admin.products.create.breadcrumbs.after') !!}
                    
                    <div class="text-xl font-bold dark:text-white">
                        @lang('admin::app.products.create.title')
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <div class="flex items-center gap-x-2.5">
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

            <div class="flex gap-2.5 max-xl:flex-wrap">
                <!-- Left sub-component -->
                <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                    <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                            @lang('admin::app.products.create.general')
                        </p>

                        {!! view_render_event('admin.products.create.attributes.before') !!}

                        <x-admin::attributes
                            :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                'entity_type' => 'products',
                                ['code', 'NOTIN', ['price', 'quantity']],
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
                                {{ __('Especialidad (línea)') }}
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
                                    {{ __('Sesiones sugeridas (mín)') }}
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="number"
                                    name="recommended_sessions_min"
                                    :value="old('recommended_sessions_min', '')"
                                    min="1"
                                    max="100"
                                />

                                <x-admin::form.control-group.error field-name="recommended_sessions_min" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="flex-1">
                                <x-admin::form.control-group.label>
                                    {{ __('Sesiones sugeridas (máx)') }}
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="number"
                                    name="recommended_sessions_max"
                                    :value="old('recommended_sessions_max', '')"
                                    min="1"
                                    max="100"
                                />

                                <x-admin::form.control-group.error field-name="recommended_sessions_max" />
                            </x-admin::form.control-group>
                        </div>
                    </div>
                </div>

                <!-- Right sub-component -->
                <div class="flex w-[360px] max-w-full flex-col gap-2 max-sm:w-full">
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
        </div>
    </x-admin::form>

    {!! view_render_event('admin.products.create.form.after') !!}
</x-admin::layouts>
