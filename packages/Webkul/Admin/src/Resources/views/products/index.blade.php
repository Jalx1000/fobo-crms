<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.products.index.title')
    </x-slot>

    {{-- Celular: de borde a borde, sin el gris de alrededor (como Citas y Pacientes). --}}
    <div class="flex flex-col gap-4 max-lg:gap-0">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <!-- Breadcrumbs -->
                <x-admin::breadcrumbs name="products" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('admin::app.products.index.title')
                </div>
            </div>

            <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                {!! view_render_event('admin.products.index.create_button.before') !!}

                <!-- Create button for Product -->
                @if (bouncer()->hasPermission('products.create'))
                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        <a
                            href="{{ route('admin.products.create') }}"
                            class="primary-button"
                        >
                            @lang('admin::app.products.index.create-btn')
                        </a>
                    </div>
                @endif

                {!! view_render_event('admin.products.index.create_button.after') !!}
            </div>
        </div>

        {!! view_render_event('admin.products.index.datagrid.before') !!}

        <div class="max-lg:-mx-3 max-lg:[&_.datagrid-toolbar]:gap-2 max-lg:[&_.datagrid-toolbar]:border-t-0 max-lg:[&_.datagrid-toolbar]:px-3 max-lg:[&_.datagrid-toolbar]:py-2 max-lg:[&_.toolbarLeft]:w-full max-lg:[&_.rounded-t-lg]:rounded-none max-lg:[&_.rounded-t-lg]:border-x-0 max-lg:[&_.table-responsive]:rounded-none max-lg:[&_.table-responsive]:border-x-0">
            <x-admin::datagrid :src="route('admin.products.index')">
                <!-- DataGrid Shimmer -->
                <x-admin::shimmer.datagrid />
            </x-admin::datagrid>
        </div>

        {!! view_render_event('admin.products.index.datagrid.after') !!}
    </div>
</x-admin::layouts>
