<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.contacts.persons.view.title', ['name' => $person->name])
    </x-slot>

    <!-- Content -->
    {{-- Celular: de borde a borde, sin el gris entre bloques (como la ficha del pedido). --}}
    <div class="flex gap-4 max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-0">
        <!-- Left Panel -->
        {!! view_render_event('admin.contact.persons.view.left.before', ['person' => $person]) !!}

        <div class="max-lg:min-w-full max-lg:max-w-full max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 [&>div:last-child]:border-b-0 lg:sticky lg:top-[73px] flex min-w-[394px] max-w-[394px] flex-col self-start rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <!-- Person Information -->
            <div class="flex w-full flex-col gap-2 border-b border-gray-200 p-4 dark:border-gray-800">
                <!-- Breadcrumbs -->
                <div class="flex items-center justify-between">
                    <x-admin::breadcrumbs
                        name="contacts.persons.view"
                        :entity="$person"
                    />
                </div>

                {!! view_render_event('admin.contact.persons.view.tags.before', ['person' => $person]) !!}

                <!-- Tags -->
                <x-admin::tags
                    :attach-endpoint="route('admin.contacts.persons.tags.attach', $person->id)"
                    :detach-endpoint="route('admin.contacts.persons.tags.detach', $person->id)"
                    :added-tags="$person->tags"
                />

                {!! view_render_event('admin.contact.persons.view.tags.after', ['person' => $person]) !!}

                
                <!-- Title -->
                {{-- Celular: el nombre con «←» va primero, antes de las etiquetas. --}}
                <div class="mb-4 flex flex-col gap-0.5 max-lg:-order-1 max-lg:mb-0">
                    {!! view_render_event('admin.contact.persons.view.title.before', ['person' => $person]) !!}

                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.contacts.persons.index" />

                        <h3 class="text-lg font-bold dark:text-white">
                            {{ $person->name }}
                        </h3>
                    </div>

                    {!! view_render_event('admin.contact.persons.view.title.after', ['person' => $person]) !!}
                </div>
                
                <!-- Activity Actions -->
                <div class="flex flex-wrap gap-2">
                    {!! view_render_event('admin.contact.persons.view.actions.before', ['person' => $person]) !!}

                    <!-- Mail Activity Action -->
                    {{-- <x-admin::activities.actions.mail
                        :entity="$person"
                        entity-control-name="person_id"
                    /> --}}

                    <!-- File Activity Action -->
                    <x-admin::activities.actions.file
                        :entity="$person"
                        entity-control-name="person_id"
                    />

                    <!-- Note Activity Action -->
                    <x-admin::activities.actions.note
                        :entity="$person"
                        entity-control-name="person_id"
                    />

                    <!-- Activity Action -->
                    {{-- <x-admin::activities.actions.activity
                        :entity="$person"
                        entity-control-name="person_id"
                    /> --}}

                    {!! view_render_event('admin.contact.persons.view.actions.after', ['person' => $person]) !!}
                </div>
            </div>

            <!-- Person Attributes -->
            @include ('admin::contacts.persons.view.attributes')

            <!-- Contact Organization -->
            @include ('admin::contacts.persons.view.organization')
        </div>

        {!! view_render_event('admin.contact.persons.view.left.after', ['person' => $person]) !!}

        <!-- Right Panel -->
        <div class="flex w-full flex-col gap-4 rounded-lg max-lg:gap-0 max-lg:[&>*]:!rounded-none">
            {!! view_render_event('admin.contact.persons.view.right.before', ['person' => $person]) !!}

            <!-- Activities -->
            <x-admin::activities
                :endpoint="route('admin.contacts.persons.activities.index', $person->id)"
                :extra-types="array_merge(
                    /*
                     * La pestaña sólo existe para quien tiene el módulo.
                     * Aparecía para todos, incluido un rol sin permiso de
                     * WhatsApp: el menú lateral lo ocultaba y la pestaña no,
                     * así que el mismo usuario tenía y no tenía el módulo
                     * según por dónde entrara.
                     */
                    bouncer()->hasPermission('whatsapp')
                        ? [['name' => 'whatsapp', 'label' => 'WhatsApp']]
                        : [],
                    [['name' => 'historial', 'label' => 'Historial IA']],
                )"
            >
                @if (bouncer()->hasPermission('whatsapp'))
                    <x-slot:whatsapp>
                        @include('whatsapp::inbox')
                    </x-slot>
                @endif

                <x-slot:historial>
                    @include('admin::contacts.persons.view.historial-ia')
                </x-slot>
            </x-admin::activities>

            {!! view_render_event('admin.contact.persons.view.right.after', ['person' => $person]) !!}
        </div>
    </div>
</x-admin::layouts>
