<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.contacts.persons.index.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:flex-wrap max-lg:gap-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="contacts.persons" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('admin::app.contacts.persons.index.title')
                </div>
            </div>

            <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                <!-- Export Modal -->
                <x-admin::datagrid.export :src="route('admin.contacts.persons.index')" />

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

        <v-persons>
            <!-- Datagrid shimmer -->
            <x-admin::shimmer.datagrid :is-multi-row="true"/>
        </v-persons>

        {!! view_render_event('admin.persons.index.datagrid.after') !!}
    </div>

    {{-- Helper compartido para sincronizar el rango de fechas con Tablero y Leads --}}
    @include('admin::components.global-date-range')

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-persons-template"
        >
          <div>
            <!-- Filtro por rango de fechas de registro (persistente por cookie) -->
            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 dark:border-gray-800 dark:bg-gray-900">
                <span class="text-sm font-medium text-gray-600 dark:text-gray-300">Registrados:</span>

                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300"
                    :style="quickRangeStyle(7)"
                    @click="setQuickRange(7)"
                >
                    7 días
                </button>

                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300"
                    :style="quickRangeStyle(30)"
                    @click="setQuickRange(30)"
                >
                    30 días
                </button>

                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300"
                    :style="quickRangeStyle(90)"
                    @click="setQuickRange(90)"
                >
                    90 días
                </button>

                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300"
                    :style="quickRangeStyle('month')"
                    @click="setCurrentMonth"
                >
                    Este mes
                </button>

                <label class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300">
                    Desde
                    <input
                        type="date"
                        v-model="dateStart"
                        @change="onManualDate"
                        class="rounded-md border px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    >
                </label>

                <label class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300">
                    Hasta
                    <input
                        type="date"
                        v-model="dateEnd"
                        @change="onManualDate"
                        class="rounded-md border px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    >
                </label>

                <button
                    type="button"
                    v-if="dateStart || dateEnd"
                    @click="clear"
                    class="text-sm text-gray-500 underline dark:text-gray-400"
                >
                    Limpiar
                </button>
            </div>

            <x-admin::datagrid
                src="{{ route('admin.contacts.persons.index') }}"
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
                        <div class="row grid grid-rows-1 items-center border-b px-4 py-2.5 dark:border-gray-800 max-lg:hidden" style="grid-template-columns: .1fr .25fr .25fr .25fr .15fr;">
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
                            class="row grid grid-rows-1 border-b px-4 py-2.5 transition-all hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950 max-lg:hidden"
                            style="grid-template-columns: .1fr .25fr .25fr .25fr .15fr;"
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
          </div>
        </script>

        <script type="module">
            app.component('v-persons', {
                template: '#v-persons-template',

                data() {
                    // Rango sincronizado (cookie compartida global_date_range) para que
                    // coincida con Tablero y Leads. 'quick' evita ambigüedad cuando dos
                    // botones producen el mismo rango (p. ej. "Este mes" y "7 días" el día 7).
                    const saved = window.OdontoDateRange.read();

                    return {
                        dateStart: saved.from,
                        dateEnd: saved.to,
                        // Botón rápido activo: 7 | 30 | 90 | 'month' | '' (custom).
                        activeQuick: window.OdontoDateRange.normalizeQuick(saved.quick),
                    };
                },

                created() {
                    // Reflejar el rango restaurado en la URL ANTES de que el datagrid
                    // haga su primer fetch (su mounted lee window.location).
                    if (this.dateStart || this.dateEnd) {
                        this.syncUrl();
                    }
                },

                methods: {
                    formatDate(date) {
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');

                        return `${year}-${month}-${day}`;
                    },

                    quickRangeStyle(key) {
                        if (this.activeQuick !== key) {
                            return {};
                        }

                        return {
                            borderColor: '#2AA8B3',
                            backgroundColor: 'rgba(42, 168, 179, 0.12)',
                            color: '#2AA8B3',
                            fontWeight: '600',
                        };
                    },

                    setCookie() {
                        window.OdontoDateRange.write(this.dateStart, this.dateEnd, this.activeQuick);
                    },

                    syncUrl() {
                        const url = new URL(window.location);

                        this.dateStart
                            ? url.searchParams.set('start_date', this.dateStart)
                            : url.searchParams.delete('start_date');

                        this.dateEnd
                            ? url.searchParams.set('end_date', this.dateEnd)
                            : url.searchParams.delete('end_date');

                        window.history.replaceState({}, '', url);
                    },

                    apply() {
                        this.setCookie();
                        this.syncUrl();
                        this.$refs.datagrid.get();
                    },

                    setQuickRange(days) {
                        const r = window.OdontoDateRange.resolve(days);

                        this.dateStart = r.from;
                        this.dateEnd = r.to;
                        this.activeQuick = days;
                        this.apply();
                    },

                    setCurrentMonth() {
                        const r = window.OdontoDateRange.resolve('month');

                        this.dateStart = r.from;
                        this.dateEnd = r.to;
                        this.activeQuick = 'month';
                        this.apply();
                    },

                    // Edición manual de los date pickers: rango personalizado.
                    onManualDate() {
                        this.activeQuick = '';
                        this.apply();
                    },

                    clear() {
                        this.dateStart = '';
                        this.dateEnd = '';
                        this.activeQuick = '';
                        this.apply();
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
