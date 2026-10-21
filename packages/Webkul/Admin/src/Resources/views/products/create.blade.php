
<x-admin::layouts>
    <!-- Page Title -->
    <x-slot:title>
        @lang('admin::app.products.create.title')
    </x-slot>

    {!! view_render_event('admin.products.create.form.before') !!}

    <x-admin::form
        :action="route('admin.products.store')"
        enctype="multipart/form-data"
        method="POST"
    >
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 max-lg:sticky max-lg:top-[62px] max-lg:z-[10] max-lg:shadow-sm">
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

            {{-- Celular: secciones de borde a borde. min-w-0 en las columnas: sin eso se estiraban al ancho de las tablas de precios y variantes (1344 px en el celular); las tablas ya se deslizan dentro de su sección. --}}
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
                                ['code', 'NOTIN', ['price', 'quantity']],
                            ])"
                        />

                        {!! view_render_event('admin.products.create.attributes.after') !!}
                    </div>

                    @include('admin::products._caracteristicas')

                    @include('admin::products._precios')

                    @include('admin::products._variantes')

                    @include('admin::products._entrega')

                    @include('admin::products._sucursal-responsables')

                    {{--
                        La galería completa no está acá: reordenar, portada y
                        borrar necesitan el producto ya creado. Lo que sí se
                        puede es soltar las primeras imágenes, que viajan con el
                        formulario y se guardan al crear.
                    --}}
                    @include('admin::products._fotos-al-crear')
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
