<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.leads.view.title', ['title' => $lead->title])
    </x-slot>

    <!-- Content -->
    {{-- Celular: de borde a borde, sin el gris entre bloques. --}}
    <div class="relative flex gap-4 max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-0">
        <!-- Left Panel -->
        {!! view_render_event('admin.leads.view.left.before', ['lead' => $lead]) !!}

        <div
            class="max-lg:min-w-full max-lg:max-w-full max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 [&>div:last-child]:border-b-0 lg:sticky lg:top-[73px] flex min-w-[394px] max-w-[394px] flex-col self-start rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <!-- Lead Information -->
            <div class="flex w-full flex-col gap-2 border-b border-gray-200 p-4 dark:border-gray-800">
                <!-- Breadcrumb's -->
                <div class="flex items-center justify-between">
                    <x-admin::breadcrumbs name="leads.view" :entity="$lead" />
                </div>

                <div class="mb-2">
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
                {{-- Celular: el título con «←» va primero, antes de las etiquetas. --}}
                <div class="flex items-center gap-1 max-lg:-order-1">
                    <x-admin::layouts.volver listado="admin.leads.index" :parametros="['pipeline_id' => $lead->lead_pipeline_id]" />

                    <h1 class="text-lg font-bold dark:text-white">
                        {{ $lead->title }}
                    </h1>
                </div>

                {!! view_render_event('admin.leads.view.title.after', ['lead' => $lead]) !!}

                <!-- Activity Actions -->
                <div class="flex flex-wrap gap-2">
                    {!! view_render_event('admin.leads.view.actions.before', ['lead' => $lead]) !!}

                    {{-- @if (bouncer()->hasPermission('mail.compose'))
                        <!-- Mail Activity Action -->
                        <x-admin::activities.actions.mail :entity="$lead" entity-control-name="lead_id" />
                    @endif --}}

                    @if (bouncer()->hasPermission('activities.create'))
                        <!-- File Activity Action -->
                        <x-admin::activities.actions.file :entity="$lead" entity-control-name="lead_id" />

                        <!-- Note Activity Action -->
                        <x-admin::activities.actions.note :entity="$lead" entity-control-name="lead_id" />

                        <!-- Activity Action -->
                        <x-admin::activities.actions.activity :entity="$lead" entity-control-name="lead_id" />
                    @endif

                    {!! view_render_event('admin.leads.view.actions.after', ['lead' => $lead]) !!}
                </div>
            </div>

            <!-- Lead Attributes -->
            @include ('admin::leads.view.attributes')

            <!-- Contact Person -->
            @include ('admin::leads.view.person')
        </div>

        {!! view_render_event('admin.leads.view.left.after', ['lead' => $lead]) !!}

        {!! view_render_event('admin.leads.view.right.before', ['lead' => $lead]) !!}

        <!-- Right Panel -->
        {{-- min-w-0: sin esto el chat de WhatsApp (pestaña abierta al entrar) estiraba la columna y la página se salía de la pantalla. --}}
        <div class="flex w-full min-w-0 flex-col gap-4 rounded-lg max-lg:gap-0 max-lg:[&>*]:!rounded-none">
            <!-- Stages Navigation -->
            @include ('admin::leads.view.stages')

            <!-- Activities -->
            {!! view_render_event('admin.leads.view.activities.before', ['lead' => $lead]) !!}

            <x-admin::activities :endpoint="route('admin.leads.activities.index', $lead->id)" :email-detach-endpoint="route('admin.leads.emails.detach', $lead->id)" :activeType="request()->query('from') === 'quotes' ? 'quotes' : 'whatsapp'" :extra-types="[
                // WhatsApp primero y abierta al entrar: es por donde se atiende al paciente.
                ['name' => 'whatsapp', 'label' => 'WhatsApp', 'primera' => true],
                ['name' => 'historial', 'label' => 'Historial IA'],
                ['name' => 'description', 'label' => trans('admin::app.leads.view.tabs.description')],
                ['name' => 'products', 'label' => trans('admin::app.leads.view.tabs.products')],
                // ['name' => 'quotes', 'label' => trans('admin::app.leads.view.tabs.quotes')],
            ]">
                {{-- WhatsApp Inbox --}}
                <x-slot:whatsapp>
                    @include('whatsapp::inbox')
                </x-slot>

                {{-- Historial IA --}}
                <x-slot:historial>
                    @include('admin::leads.view.historial-ia')
                </x-slot>

                <!-- Products -->
                <x-slot:products>
                    @include ('admin::leads.view.products')
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
