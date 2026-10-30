<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.activities.index.title')
    </x-slot>

    {!! view_render_event('admin.activities.index.activities.before') !!}

    <!-- Activities Datagrid -->
    <v-activities>
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:flex-wrap max-lg:gap-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs name="activities" />

                    <div class="text-xl font-bold dark:text-white">
                        @lang('admin::app.activities.index.title')
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    @if (bouncer()->hasPermission('leads.create'))
                        <a
                            href="{{ route('admin.leads.create') }}"
                            class="primary-button"
                        >
                            @lang('admin::app.leads.index.create-btn')
                        </a>
                    @endif

                    <div class="flex gap-2">
                        <i class="icon-list cursor-pointer rounded p-2 text-2xl"></i>

                        <i class="icon-calendar cursor-pointer rounded p-2 text-2xl"></i>
                    </div>
                </div>
            </div>

            <!-- DataGrid Shimmer -->
            @if (
                request()->get('view-type') == 'table'
                || ! request()->has('view-type')
            )
                <x-admin::shimmer.datagrid :is-multi-row="true"/>
            @endif
        </div>
    </v-activities>

    {!! view_render_event('admin.activities.index.activities.after') !!}

    {{-- En <head> (pila "styles"): un <style> dentro del contenido lo descarta
         Vue al montar #app, y la cabecera quedaba sin estos estilos. --}}
    @push('styles')
    <style>
        /* Cabecera del Calendario. Escritorio: el título toma el espacio libre y
           acciones + vista quedan juntas a la derecha. */
        .act-head .act-head-title { flex: 1 1 auto; }
        .act-head .act-head-right { display: flex; align-items: center; gap: 8px; }

        /* Celular y tablet: sin borde gris, a lo ancho como leads, y en dos filas. */
        @media (max-width: 1023px) {
            .act-head {
                display: grid; grid-template-columns: 1fr auto; gap: 10px;
                margin: -12px -12px 0; padding: 12px; border: 0; border-radius: 0;
            }
            .act-head .act-head-right { display: contents; }
            .act-head .act-head-title { grid-column: 1; grid-row: 1; }
            .act-head .act-head-views { grid-column: 2; grid-row: 1; align-self: center; }
            .act-head .act-head-actions {
                grid-column: 1 / -1; grid-row: 2; display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
                gap: 8px; margin: 0;
            }
            /* Sin fondo gris a la vista: sin separación entre cabecera y
               calendario, y el calendario de borde a borde. */
            .act-page { gap: 0; }
            .act-page > .act-body:has(.dwc-container) { margin: 0 -12px; }
            .act-head .act-head-create {
                display: inline-flex; align-items: center; justify-content: center;
                height: 36px; padding: 0 12px; border: 0; border-radius: 8px; white-space: nowrap;
            }
        }

        /* Celulares angostos (320px): que las tres acciones entren sin salirse. */
        @media (max-width: 359px) {
            .act-head .act-head-create, .act-head .ssc-btn { padding: 0 8px; font-size: 13px; }
            .act-head .ssc-wrap { gap: 6px; }
            .act-head .act-head-actions { gap: 6px; }
        }
    </style>
    @endpush

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-activities-template"
        >
            <div class="act-page flex flex-col gap-4">
                <!-- Cabecera: en el celular título + vista arriba y las tres acciones
                     debajo, a lo ancho y sin bordes grises (estilos al final). -->
                <div class="act-head flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    <div class="act-head-title flex flex-col gap-2">
                        <x-admin::breadcrumbs name="activities" />

                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.activities.index.title')
                        </div>
                    </div>

                    {!! view_render_event('admin.activities.index.toggle_view.before') !!}

                    <!-- Acciones y selector de vista juntos a la derecha. -->
                    <div class="act-head-right">
                    <div class="act-head-actions flex items-center gap-x-2.5">
                        @if (bouncer()->hasPermission('activities'))
                            <v-smd-sync-control></v-smd-sync-control>
                        @endif

                        @if (bouncer()->hasPermission('leads.create'))
                            <a
                                href="{{ route('admin.leads.create') }}"
                                class="primary-button act-head-create"
                            >
                                @lang('admin::app.leads.index.create-btn')
                            </a>
                        @endif
                    </div>

                    <div class="act-head-views flex">
                        <i
                            class="icon-list cursor-pointer rounded-md p-2 text-2xl"
                            :class="{'bg-gray-200 dark:bg-gray-800 text-gray-800 dark:text-white': viewType == 'table'}"
                            @click="toggleView('table')"
                        ></i>

                        <i
                            class="icon-calendar cursor-pointer rounded-md p-2 text-2xl"
                            :class="{'bg-gray-200 dark:bg-gray-800 text-gray-800 dark:text-white': viewType == 'calendar'}"
                            @click="toggleView('calendar')"
                        ></i>
                    </div>
                    </div>

                    {!! view_render_event('admin.activities.index.toggle_view.after') !!}
                </div>

                <!-- DataGrid Shimmer -->
                <div class="act-body">
                    <template v-if="viewType == 'table'">
                        {!! view_render_event('admin.activities.index.datagrid.before') !!}

                        <x-admin::datagrid
                            src="{{ route('admin.activities.get') }}"
                            :isMultiRow="true"
                            ref="datagrid"
                        >
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
                                    <div class="row grid grid-cols-[.3fr_.1fr_.3fr_.5fr] grid-rows-1 items-center gap-x-2.5 border-b px-4 py-2.5 dark:border-gray-800 max-lg:hidden">
                                        <div
                                            class="flex select-none items-center gap-2.5"
                                            v-for="(columnGroup, index) in [['id', 'title', 'created_by_id'], ['is_done'], ['comment', 'lead_title', 'type'], ['schedule_from', 'schedule_to', 'created_at']]"
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
                                        class="row grid grid-cols-[.3fr_.1fr_.3fr_.5fr] grid-rows-1 gap-x-2.5 border-b px-4 py-2.5 transition-all hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950 max-lg:hidden"
                                        v-for="record in available.records"
                                    >
                                        <!-- Mass Actions, Title and Created By -->
                                        <div class="flex gap-2.5">
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

                                            <div class="flex flex-col gap-1.5">
                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @{{ record.id }}
                                                </p>

                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @{{ record.title }}
                                                </p>

                                                <p
                                                    class="text-gray-600 dark:text-gray-300"
                                                    v-html="record.created_by_id"
                                                >
                                                </p>
                                            </div>
                                        </div>

                                        <!-- Is Done -->
                                        <div class="flex gap-1.5">
                                            <div class="flex flex-col gap-1.5">
                                                <p
                                                    class="text-gray-600 dark:text-gray-300"
                                                    v-html="record.is_done"
                                                >
                                                </p>
                                            </div>
                                        </div>

                                        <!-- Comment, Lead Title and Type -->
                                        <div class="flex gap-1.5">
                                            <div class="flex flex-col gap-1.5">
                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @{{ record.comment ? (record.comment.length > 180 ? record.comment.slice(0, 180) + '...' : record.comment) : '' }}
                                                </p>

                                                <p v-html="record.lead_title"></p>

                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @{{ record.type ?? 'N/A'}}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex items-start justify-between gap-x-4">
                                            <div class="flex flex-col gap-1.5">
                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @{{ record.schedule_from ?? 'N/A' }}
                                                </p>

                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @{{ record.schedule_to }}
                                                </p>

                                                <p class="text-gray-600 dark:text-gray-300">
                                                    @{{ record.created_at }}
                                                </p>
                                            </div>

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

                        {!! view_render_event('admin.activities.index.datagrid.after') !!}
                    </template>

                    <template v-else>
                        {!! view_render_event('admin.activities.index.vue_calender.before') !!}

                        <v-doctor-day-calendar></v-doctor-day-calendar>

                        {!! view_render_event('admin.activities.index.vue_calender.after') !!}
                    </template>
                </div>
            </div>
        </script>

        @include('admin::components.activities.doctor-day-calendar')

        {{-- Chat de WhatsApp embebido en el modal de detalle de la cita --}}
        @includeIf('whatsapp::inbox-component')

        @if (bouncer()->hasPermission('activities'))
            @include('admin::components.smd-sync-control')
        @endif

        <script type="module">
            app.component('v-activities', {
                template: '#v-activities-template',

                data() {
                    return {
                        viewType: '{{ request('view-type') }}' || 'calendar',
                    };
                },

                methods: {
                    /**
                     * Toggle view type.
                     *
                     * @param {String} type
                     * @return {void}
                     */
                    toggleView(type) {
                        this.viewType = type;

                        let currentUrl = new URL(window.location);

                        currentUrl.searchParams.set('view-type', type);

                        window.history.pushState({}, '', currentUrl);
                    },
                },
            });
        </script>

        <script>
            /**
             * Update status for `is_done`.
             *
             * @param {Event} {target}
             * @return {void}
             */
            const updateStatus = ({ target }, url) => {
                axios
                    .post(url, {
                        _method: 'put',
                        is_done: target.checked,
                    })
                    .then(response => {
                        window.emitter.emit('add-flash', { type: 'success', message: response.data.message });
                    })
                    .catch(error => {});
            };
        </script>
    @endPushOnce

</x-admin::layouts>
