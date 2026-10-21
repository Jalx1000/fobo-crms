<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.quotes.index.title')
    </x-slot>

    <v-qoute>
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:flex-wrap max-lg:gap-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <!-- Bredcrumbs -->
                    <x-admin::breadcrumbs name="quotes" />
        
                    <div class="text-xl font-bold dark:text-white">
                        @lang('admin::app.quotes.index.title')
                    </div>
                </div>
        
                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    @isset($templatesSentThisMonth)
                        {{-- Solo Administrador: el envío por plantilla se cobra. --}}
                        <span
                            class="flex items-center gap-1.5 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                            title="@lang('admin::app.quotes.index.whatsapp.counter-hint')"
                        >
                            <span class="icon-sent text-base"></span>
                            @lang('admin::app.quotes.index.whatsapp.counter', ['count' => $templatesSentThisMonth])
                        </span>
                    @endisset

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
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:flex-wrap max-lg:gap-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    <div class="flex flex-col gap-2">
                        <!-- Bredcrumbs -->
                        <x-admin::breadcrumbs name="quotes" />
        
                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.quotes.index.title')
                        </div>
                    </div>

                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        @isset($templatesSentThisMonth)
                            {{-- Solo Administrador: el envío por plantilla se cobra. --}}
                            <span
                                class="flex items-center gap-1.5 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                                title="@lang('admin::app.quotes.index.whatsapp.counter-hint')"
                            >
                                <span class="icon-sent text-base"></span>
                                @lang('admin::app.quotes.index.whatsapp.counter', ['count' => $templatesSentThisMonth])
                            </span>
                        @endisset

                        <!-- Create button for person -->
                        <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                            @include('admin::leads.index.ejecutivo-filter', ['rutaEjecutivo' => 'admin.quotes.index'])

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
                <x-admin::datagrid :src="route('admin.quotes.index')" />

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
