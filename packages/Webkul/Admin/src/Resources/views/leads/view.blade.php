<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.leads.view.title', ['title' => $lead->title])
    </x-slot>

    {{-- Escritorio: panel izquierdo fijo (cabecera + atributos) y actividades
         a la derecha.

         Teléfono/tablet (< lg): el panel izquierdo pasa a `display: contents`
         y sus dos bloques se reordenan alrededor de las actividades, para que
         la conversación quede arriba y no después de ~25 atributos:
           1. cabecera (nombre, etiquetas, Archivo/Nota)
           2. etapas + pestañas (WhatsApp primero)
           3. atributos y contacto --}}
    <!-- Content -->
    <div class="relative flex gap-4 max-lg:flex-col">
        <!-- Left Panel -->
        {!! view_render_event('admin.leads.view.left.before', ['lead' => $lead]) !!}

        <div
            class="lg:sticky lg:top-[73px] flex min-w-[394px] max-w-[394px] flex-col self-start rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 max-lg:contents">
            <!-- Lead Information -->
            <div class="flex w-full flex-col gap-2 border-b border-gray-200 p-4 dark:border-gray-800 max-lg:order-1 max-lg:rounded-lg max-lg:border max-lg:bg-white dark:max-lg:bg-gray-900">
                <!-- Breadcrumb's -->
                <div class="flex items-center justify-between">
                    <x-admin::breadcrumbs name="leads.view" :entity="$lead" />
                </div>

                <div class="mb-2 flex">
                    @if (($days = $lead->rotten_days) > 0)
                        @php
                            $lead->tags->prepend([
                                'name' =>
                                    '<span class="icon-rotten text-base"></span>' .
                                    trans('admin::app.leads.view.rotten-days', ['days' => $days]),
                                'color' => '#FEE2E2',
                            ]);
                        @endphp
                    @endif

                    {!! view_render_event('admin.leads.view.tags.before', ['lead' => $lead]) !!}

                    <!-- Tags -->
                    <x-admin::tags :attach-endpoint="route('admin.leads.tags.attach', $lead->id)" :detach-endpoint="route('admin.leads.tags.detach', $lead->id)" :added-tags="$lead->tags" />

                    {!! view_render_event('admin.leads.view.tags.after', ['lead' => $lead]) !!}
                </div>


                {!! view_render_event('admin.leads.view.title.before', ['lead' => $lead]) !!}

                <!-- Title -->
                <h1 class="text-lg font-bold dark:text-white">
                    {{ $lead->title }}
                </h1>

                {!! view_render_event('admin.leads.view.title.after', ['lead' => $lead]) !!}

                <!-- Activity Actions -->
                <div class="flex flex-wrap gap-2">
                    {!! view_render_event('admin.leads.view.actions.before', ['lead' => $lead]) !!}

                    @if (bouncer()->hasPermission('activities.create') || bouncer()->hasPermission('leads.edit'))
                        <!-- File Activity Action -->
                        <x-admin::activities.actions.file :entity="$lead" entity-control-name="lead_id" />

                        <!-- Note Activity Action -->
                        <x-admin::activities.actions.note :entity="$lead" entity-control-name="lead_id" />

                        <!-- Activity Action -->
                        {{-- <x-admin::activities.actions.activity :entity="$lead" entity-control-name="lead_id" /> --}}
                    @endif

                    {!! view_render_event('admin.leads.view.actions.after', ['lead' => $lead]) !!}
                </div>
            </div>

            <div class="flex flex-col [&>div:last-child]:border-b-0 max-lg:order-3 max-lg:rounded-lg max-lg:border max-lg:border-gray-200 max-lg:bg-white dark:max-lg:border-gray-800 dark:max-lg:bg-gray-900">
                <!-- Lead Attributes -->
                @include ('admin::leads.view.attributes')

                <!-- Contact Person -->
                @include ('admin::leads.view.person')
            </div>
        </div>

        {!! view_render_event('admin.leads.view.left.after', ['lead' => $lead]) !!}

        {!! view_render_event('admin.leads.view.right.before', ['lead' => $lead]) !!}

        <!-- Right Panel -->
        {{-- `min-w-0`: como hijo de flex su ancho mínimo es el de su contenido,
             y la barra de etapas lo estiraba más allá de la pantalla. --}}
        <div class="flex w-full min-w-0 flex-col gap-4 rounded-lg max-lg:order-2">
            <!-- Stages Navigation -->
            @include ('admin::leads.view.stages')

            <!-- Activities -->
            {!! view_render_event('admin.leads.view.activities.before', ['lead' => $lead]) !!}

            <x-admin::activities :endpoint="route('admin.leads.activities.index', $lead->id)" :email-detach-endpoint="route('admin.leads.emails.detach', $lead->id)" :activeType="request()->query('from') === 'quotes' ? 'quotes' : 'whatsapp'" :extra-types="[
                // WhatsApp es la primera pestaña y la que se abre: es donde
                // ocurre la atención del lead.
                ['name' => 'whatsapp', 'label' => 'WhatsApp', 'first' => true],
                ['name' => 'description', 'label' => trans('admin::app.leads.view.tabs.description')],
                ['name' => 'inmuebles', 'label' => 'Inmuebles'],
                // Historial IA y Productos: fuera del tablero de pestañas a pedido
                // de C21. La conversación completa vive en la pestaña WhatsApp, y
                // acá no se venden productos sino inmuebles.
                // ['name' => 'historial', 'label' => 'Historial IA'],
                // ['name' => 'products', 'label' => trans('admin::app.leads.view.tabs.products')],
                // ['name' => 'quotes', 'label' => trans('admin::app.leads.view.tabs.quotes')],
            ]">
                {{-- WhatsApp Inbox --}}
                <x-slot:whatsapp>
                    @include('whatsapp::inbox')
                </x-slot>

                {{-- Historial IA y Productos quedaron sin pestaña (ver la lista de
                     extra-types arriba). Los slots se retiran junto con ellas: un
                     slot sin pestaña es contenido que se renderiza y nadie ve. --}}

                {{-- Inmuebles de interés: los que el cliente consultó --}}
                <x-slot:inmuebles>
                    @include ('inmobiliaria::leads.inmuebles-interes')
                </x-slot>

                <!-- Quotes -->
                {{-- <x-slot:quotes>
                    @include ('admin::leads.view.quotes')
                </x-slot> --}}

                <!-- Description -->
                <x-slot:description>
                    <div class="p-4 dark:text-white">
                        {{ $lead->description }}
                    </div>
                </x-slot>

            </x-admin::activities>

            {!! view_render_event('admin.leads.view.activities.after', ['lead' => $lead]) !!}
        </div>

        {!! view_render_event('admin.leads.view.right.after', ['lead' => $lead]) !!}
    </div>
</x-admin::layouts>
