<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.contacts.persons.index.title')
    </x-slot>

    <div class="flex flex-col gap-4 max-lg:gap-0">
        {{-- Celular: de borde a borde, sin el gris de alrededor (como Pedidos y Cotizaciones). --}}
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="contacts.persons" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('admin::app.contacts.persons.index.title')
                </div>
            </div>

            <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                <!-- Export Modal (carries the active city/date filter so the
                     download matches the on-screen list) -->
                <x-admin::datagrid.export
                    file-name="prospectos"
                    :src="route('admin.contacts.persons.index', array_merge(
                        request()->only(['start', 'end']),
                        ['pipeline_id' => request('pipeline_id')]
                    ))"
                />

                <!-- Create button for person -->
                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    {!! view_render_event('admin.persons.index.create_button.before') !!}

                    @if (bouncer()->hasPermission('contacts.persons.create'))
                        <a
                            href="{{ route('admin.contacts.persons.create') }}"
                            class="primary-button"
                        >
                            @lang('admin::app.contacts.persons.index.create-btn')
                        </a>
                    @endif

                    {!! view_render_event('admin.persons.index.create_button.after') !!}
                </div>
            </div>
        </div>

        {!! view_render_event('admin.persons.index.datagrid.before') !!}

        <!-- Global filters (city + date range), shared with the dashboard/Pedidos -->
        {{-- Celular: estos filtros están en el panel «Filtros» (persons.filtros-movil). --}}
        <div class="flex flex-wrap items-center justify-end gap-1.5 rounded-lg border border-gray-200 bg-white px-4 py-2.5 max-lg:hidden dark:border-gray-800 dark:bg-gray-900">
            @include('admin::contacts.persons.filters')
        </div>

        {{-- Celular: buscador y «Filtros» en una banda blanca compacta, de borde a borde. --}}
        <div class="max-lg:-mx-3 max-lg:[&_.datagrid-toolbar]:gap-2 max-lg:[&_.datagrid-toolbar]:border-t-0 max-lg:[&_.datagrid-toolbar]:px-3 max-lg:[&_.datagrid-toolbar]:py-2 max-lg:[&_.toolbarLeft]:w-full max-lg:[&_.toolbarLeft]:gap-x-2 max-lg:[&_.rounded-t-lg]:rounded-none max-lg:[&_.rounded-t-lg]:border-x-0 max-lg:[&_.table-responsive]:rounded-none max-lg:[&_.table-responsive]:border-x-0">
            <v-persons>
                <!-- Datagrid shimmer -->
                <x-admin::shimmer.datagrid :is-multi-row="true"/>
            </v-persons>
        </div>

        @push('styles')
            <style>
                .toolbarLeft > div > div > div > .relative.flex.cursor-pointer.items-center {
                    display: none !important;
                }
            </style>
        @endpush

        {!! view_render_event('admin.persons.index.datagrid.after') !!}
    </div>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-persons-template"
        >
            <x-admin::datagrid
                src="{{ route('admin.contacts.persons.index') }}"
                :isMultiRow="true"
                ref="datagrid"
            >
                <x-slot:toolbar-left-after>
                    <!-- Celular: el resto de los filtros, en el panel lateral -->
                    @include('admin::contacts.persons.filtros-movil')
                </x-slot>

                <template #header="{
                    isLoading,
                    available,
                    applied,
                    selectAll,
                    sort,
                    performAction
                }">
                    <template v-if="isLoading">
                        <x-admin::shimmer.datagrid.table.head :isMultiRow="true" />
                    </template>

                    <template v-else>
                        <div class="row grid grid-cols-[.1fr_.2fr_.2fr_.2fr_.2fr_.2fr] grid-rows-1 items-center border-b px-4 py-2.5 dark:border-gray-800 max-lg:hidden">
                            <div
                                class="flex select-none items-center gap-2.5"
                                v-for="(columnGroup, index) in [['id'], ['person_name'], ['emails'], ['contact_numbers']]"
                            >
                                <label
                                    class="flex w-max cursor-pointer select-none items-center gap-1"
                                    for="mass_action_select_all_records"
                                    v-if="! index"
                                >
                                    <input
                                        type="checkbox"
                                        name="mass_action_select_all_records"
                                        id="mass_action_select_all_records"
                                        class="peer hidden"
                                        :checked="['all', 'partial'].includes(applied.massActions.meta.mode)"
                                        @change="selectAll"
                                    >

                                    <span
                                        class="icon-checkbox-outline cursor-pointer rounded-md text-2xl text-gray-600 dark:text-gray-300"
                                        :class="[
                                            applied.massActions.meta.mode === 'all' ? 'peer-checked:icon-checkbox-select peer-checked:text-brandColor' : (
                                                applied.massActions.meta.mode === 'partial' ? 'peer-checked:icon-checkbox-multiple peer-checked:text-brandColor' : ''
                                            ),
                                        ]"
                                    >
                                    </span>
                                </label>

                                <p class="text-gray-600 dark:text-gray-300">
                                    <span class="[&>*]:after:content-['_/_']">
                                        <template v-for="column in columnGroup">
                                            <span
                                                class="after:content-['/'] last:after:content-['']"
                                                :class="{
                                                    'font-medium text-gray-800 dark:text-white': applied.sort.column == column,
                                                    'cursor-pointer hover:text-gray-800 dark:hover:text-white': available.columns.find(columnTemp => columnTemp.index === column)?.sortable,
                                                }"
                                                @click="
                                                    available.columns.find(columnTemp => columnTemp.index === column)?.sortable ? sort(available.columns.find(columnTemp => columnTemp.index === column)): {}
                                                "
                                            >
                                                @{{ available.columns.find(columnTemp => columnTemp.index === column)?.label }}
                                            </span>
                                        </template>
                                    </span>

                                    <i
                                        class="align-text-bottom text-base text-gray-800 dark:text-white ltr:ml-1.5 rtl:mr-1.5"
                                        :class="[applied.sort.order === 'asc' ? 'icon-stats-down': 'icon-stats-up']"
                                        v-if="columnGroup.includes(applied.sort.column)"
                                    ></i>
                                </p>
                            </div>
                        </div>

                        <!-- Mobile Sort/Filter Header -->
                        <div class="hidden border-b bg-gray-50 px-4 py-3 text-black dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 max-lg:block">
                            <div class="flex items-center justify-between">
                                <!-- Mass Actions for Mobile -->
                                <div v-if="available.massActions.length">
                                    <label
                                        class="flex w-max cursor-pointer select-none items-center gap-1"
                                        for="mass_action_select_all_records"
                                    >
                                        <input
                                            type="checkbox"
                                            name="mass_action_select_all_records"
                                            id="mass_action_select_all_records"
                                            class="peer hidden"
                                            :checked="['all', 'partial'].includes(applied.massActions.meta.mode)"
                                            @change="selectAll"
                                        >

                                        <span
                                            class="icon-checkbox-outline cursor-pointer rounded-md text-2xl text-gray-600 dark:text-gray-300"
                                            :class="[
                                                applied.massActions.meta.mode === 'all' ? 'peer-checked:icon-checkbox-select peer-checked:text-brandColor' : (
                                                    applied.massActions.meta.mode === 'partial' ? 'peer-checked:icon-checkbox-multiple peer-checked:text-brandColor' : ''
                                                ),
                                            ]"
                                        >
                                        </span>
                                    </label>
                                </div>
                                
                                <!-- Mobile Sort Dropdown -->
                                <div v-if="available.columns.some(column => column.sortable)">
                                    <x-admin::dropdown position="bottom-{{ in_array(app()->getLocale(), ['fa', 'ar']) ? 'left' : 'right' }}">
                                        <x-slot:toggle>
                                            <div class="flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    class="inline-flex w-full max-w-max cursor-pointer appearance-none items-center justify-between gap-x-2 rounded-md border bg-white px-2.5 py-1.5 text-center leading-6 text-gray-600 transition-all marker:shadow hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                                >
                                                    <span>
                                                        Sort
                                                    </span>
                    
                                                    <span class="icon-down-arrow text-2xl"></span>
                                                </button>
                                            </div>
                                        </x-slot>
                
                                        <x-slot:menu>
                                            <x-admin::dropdown.menu.item
                                                v-for="column in available.columns.filter(column => column.sortable && column.visibility)"
                                                @click="sort(column)"
                                            >
                                                <div class="flex items-center gap-2">
                                                    <span v-html="column.label"></span>
                                                    <i
                                                        class="align-text-bottom text-base text-gray-600 dark:text-gray-300"
                                                        :class="[applied.sort.order === 'asc' ? 'icon-stats-down': 'icon-stats-up']"
                                                        v-if="column.index == applied.sort.column"
                                                    ></i>
                                                </div>
                                            </x-admin::dropdown.menu.item>
                                        </x-slot>
                                    </x-admin::dropdown>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>

                <template #body="{
                    isLoading,
                    available,
                    applied,
                    selectAll,
                    sort,
                    performAction
                }">
                    <template v-if="isLoading">
                        <x-admin::shimmer.datagrid.table.body :isMultiRow="true" />
                    </template>

                    <template v-else>
                        <div
                            class="row grid grid-cols-[.1fr_.2fr_.2fr_.2fr_.2fr_.2fr] grid-rows-1 border-b px-4 py-2.5 transition-all hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950 max-lg:hidden"
                            v-for="record in available.records"
                        >
                            <!-- Mass Action and Person ID. -->
                            <div class="flex items-center gap-2.5">
                                <input
                                    type="checkbox"
                                    :name="`mass_action_select_record_${record.id}`"
                                    :id="`mass_action_select_record_${record.id}`"
                                    :value="record.id"
                                    class="peer hidden"
                                    v-model="applied.massActions.indices"
                                >

                                <label
                                    class="icon-checkbox-outline peer-checked:icon-checkbox-select cursor-pointer rounded-md text-2xl text-gray-600 peer-checked:text-brandColor dark:text-gray-300"
                                    :for="`mass_action_select_record_${record.id}`"
                                ></label>

                                <div class="flex flex-col gap-1.5 dark:text-gray-300">
                                    @{{ record.id }}
                                </div>
                            </div>

                            <!-- Name -->
                            <div class="flex items-center gap-1.5 dark:text-gray-300">
                                <x-admin::avatar ::name="record.person_name" />

                                @{{ record.person_name }}
                            </div>

                            <!-- Emails -->
                            <p class="flex items-center dark:text-gray-300">
                                @{{ record.emails }}
                            </p>

                            <!-- Contact Numbers -->
                            <p class="flex items-center dark:text-gray-300">
                                @{{ record.contact_numbers }}
                            </p>

                            <!-- Organization -->
                            {{-- <p class="flex items-center dark:text-gray-300">
                                @{{ record.organization }}
                            </p> --}}

                            <!-- Actions -->
                            <div class="flex items-center justify-end gap-x-4">
                                <div class="flex items-center gap-1.5">
                                    <p
                                        class="place-self-end"
                                        v-if="available.actions.length"
                                    >
                                        <span
                                            class="cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800 max-sm:place-self-center"
                                            :class="action.icon"
                                            v-text="! action.icon ? action.title : ''"
                                            v-for="action in record.actions"
                                            @click="performAction(action)"
                                        ></span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Mobile Card View: componente compartido (datagrid/mobile-card). -->
                        <v-datagrid-mobile-card
                            v-for="record in available.records"
                            :key="record[available.meta.primary_column]"
                            :record="record"
                            :available="available"
                            :applied="applied"
                            @action="performAction"
                        ></v-datagrid-mobile-card>
                    </template>
                </template>
            </x-admin::datagrid>
        </script>

        <script type="module">
            app.component('v-persons', {
                template: '#v-persons-template',
            });
        </script>
    @endPushOnce
</x-admin::layouts>
