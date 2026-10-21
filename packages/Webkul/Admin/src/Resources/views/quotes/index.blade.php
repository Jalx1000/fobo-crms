<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.quotes.index.title')
    </x-slot>

    <v-qoute>
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <!-- Bredcrumbs -->
                    <x-admin::breadcrumbs name="quotes" />
        
                    <div class="text-xl font-bold dark:text-white">
                        @lang('admin::app.quotes.index.title')
                    </div>
                </div>
        
                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    <!-- Create button for Quote -->
                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        @if (bouncer()->hasPermission('quotes.create'))
                            <a 
                                href="{{ route('admin.quotes.create') }}"
                                class="primary-button"
                            >
                                @lang('admin::app.quotes.index.create-btn')
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        
            <!-- DataGrid Shimmer -->
            <x-admin::shimmer.datagrid />
        </div>
    </v-qoute>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-qoute-template"
        >
            <div class="flex flex-col gap-4 max-lg:gap-0">
                <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    <div class="flex flex-col gap-2">
                        <!-- Bredcrumbs -->
                        <x-admin::breadcrumbs name="quotes" />
        
                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.quotes.index.title')
                        </div>
                    </div>

                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        <!-- Create button for person -->
                        <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                            {!! view_render_event('admin.quotes.index.create_button.before') !!}
                            
                            @if (bouncer()->hasPermission('quotes.create'))
                                <a 
                                    href="{{ route('admin.quotes.create') }}"
                                    class="primary-button"
                                >
                                    @lang('admin::app.quotes.index.create-btn')
                                </a>
                            @endif
            
                            {!! view_render_event('admin.quotes.index.create_button.after') !!}
                        </div>
                    </div>
                </div>
            
                {!! view_render_event('admin.quotes.index.datagrid.before') !!}
            
                <!-- DataGrid -->
                {{-- Celular, como la lista de Pedidos: de borde a borde, con el buscador
                     y el botón de filtros de la tabla en una banda blanca compacta.
                     En Sensia no hay ejecutivo ni plataforma, así que no hace falta
                     un panel aparte: el botón abre directo los filtros por columna. --}}
                <div class="max-lg:-mx-3 max-lg:[&_.datagrid-toolbar]:gap-2 max-lg:[&_.datagrid-toolbar]:border-t-0 max-lg:[&_.datagrid-toolbar]:px-3 max-lg:[&_.datagrid-toolbar]:py-2 max-lg:[&_.toolbarLeft]:w-full max-lg:[&_.toolbarLeft]:gap-x-2 max-lg:[&_.rounded-t-lg]:rounded-none max-lg:[&_.rounded-t-lg]:border-x-0 max-lg:[&_.table-responsive]:rounded-none max-lg:[&_.table-responsive]:border-x-0">
                    <x-admin::datagrid :src="route('admin.quotes.index')" />
                </div>

                {!! view_render_event('admin.quotes.index.datagrid.after') !!}
            </div>
        </script>

        <script type="module">
            app.component('v-qoute', {
                template: '#v-qoute-template',
            });
        </script>
    @endPushOnce
</x-admin::layouts>
