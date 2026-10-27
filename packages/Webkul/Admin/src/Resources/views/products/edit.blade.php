
<x-admin::layouts>
    <!-- Page Title -->
    <x-slot:title>
        @lang('admin::app.products.edit.title')
    </x-slot>

    {!! view_render_event('admin.products.edit.form.before') !!}

    <x-admin::form
        :action="route('admin.products.update', $product->id)"
        encType="multipart/form-data"
        method="PUT"
    >
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <!-- Breadcrumbs -->
                    <x-admin::breadcrumbs
                        name="products.edit"
                        :entity="$product"
                     />

                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.products.index" />

                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.products.edit.title')
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        {!! view_render_event('admin.products.edit.create_button.before', ['product' => $product]) !!}
                        
                        <!-- Edit button for Product -->
                        @if (bouncer()->hasPermission('products.edit'))
                            <button
                                type="submit"
                                class="primary-button"
                            >
                                @lang('admin::app.products.edit.save-btn')
                            </button>
                        @endif

                        {!! view_render_event('admin.products.edit.create_button.after', ['product' => $product]) !!}
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

                        {!! view_render_event('admin.products.edit.attributes.before', ['product' => $product]) !!}

                        <x-admin::attributes
                            :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                'entity_type' => 'products',
                                ['code', 'NOTIN', ['price', 'quantity']],
                            ])"
                            :entity="$product"
                        />

                        {!! view_render_event('admin.products.edit.attributes.after', ['product' => $product]) !!}
                    </div>
                </div>

                <!-- Right sub-component -->
                <div class="flex w-[360px] min-w-0 max-w-full flex-col gap-2 max-sm:w-full max-lg:[&>*]:rounded-none max-lg:[&>*]:border-x-0">
                    {!! view_render_event('admin.products.edit.accordion.before', ['product' => $product]) !!}

                    <x-admin::accordion >
                        <x-slot:header>
                            {!! view_render_event('admin.products.edit.accordion.header.before', ['product' => $product]) !!}

                            <div class="flex items-center justify-between">
                                <p class="p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                                    @lang('admin::app.products.create.price')
                                </p>
                            </div>

                            {!! view_render_event('admin.products.edit.accordion.header.after', ['product' => $product]) !!}
                        </x-slot>

                        <x-slot:content>
                            {!! view_render_event('admin.products.edit.accordion.content.attributes.before', ['product' => $product]) !!}

                            <x-admin::attributes
                                :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                    'entity_type' => 'products',
                                    ['code', 'IN', ['price', 'quantity']],
                                ])"
                                :entity="$product"
                            />

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>
                                    {{ __('Duración (minutos)') }}
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="number"
                                    name="duration_minutes"
                                    :value="old('duration_minutes', $product->duration_minutes ?? '')"
                                    placeholder="60"
                                    min="1"
                                    max="480"
                                />

                                <x-admin::form.control-group.error field-name="duration_minutes" />
                            </x-admin::form.control-group>

                            {!! view_render_event('admin.products.edit.accordion.content.attributes.after', ['product' => $product]) !!}
                        </x-slot>
                    </x-admin::accordion>

                    {!! view_render_event('admin.products.edit.accordion.after', ['product' => $product]) !!}
                </div>
            </div>
        </div>
    </x-admin::form>

    {!! view_render_event('admin.products.edit.form.after') !!}
</x-admin::layouts>
