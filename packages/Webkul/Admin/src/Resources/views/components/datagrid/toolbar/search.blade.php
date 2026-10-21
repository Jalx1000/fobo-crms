<v-datagrid-search
    :is-loading="isLoading"
    :available="available"
    :applied="applied"
    :src="src"
    @search="search"
    @filter="filter"
    @applySavedFilter="applySavedFilter"
>
    {{ $slot }}
</v-datagrid-search>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-datagrid-search-template"
    >
        <slot
            name="search"
            :available="available"
            :applied="applied"
            :search="search"
            :get-searched-values="getSearchedValues"
        >
            <template v-if="isLoading">
                <x-admin::shimmer.datagrid.toolbar.search />
            </template>

            <template v-else>
                <div class="flex w-full items-center gap-x-1.5">
                    <!-- Search Panel -->
                    <div class="flex max-w-[445px] items-center max-sm:w-full max-sm:max-w-full">
                        <div class="relative w-full">
                            <div class="icon-search absolute top-1.5 flex items-center text-2xl ltr:left-3 rtl:right-3"></div>

                            <input
                                type="text"
                                name="search"
                                :value="getSearchedValues('all')"
                                class="block w-full rounded-lg border bg-white py-1.5 leading-6 text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400 ltr:pl-10 ltr:pr-3 rtl:pl-3 rtl:pr-10"
                                placeholder="@lang('admin::app.components.datagrid.toolbar.search.title')"
                                autocomplete="off"
                                @input="searchDebounced"
                                @keyup.enter="searchNow"
                            >
                        </div>
                    </div>

                    <!-- Channel Panel -->
                    <!--
                        Rendered only by grids that declare a `platform` column
                        (Personas, Leads, Cotizaciones), so this shared toolbar
                        stays untouched for every other datagrid. Deliberately
                        out here rather than inside the filter drawer: narrowing
                        by channel is a one-click move people make constantly,
                        not something to go hunting for behind a panel.
                    -->
                    <select
                        v-if="platformColumn"
                        class="datagrid-plataforma w-max cursor-pointer appearance-none rounded-lg border bg-white px-3 py-1.5 leading-6 text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        :value="selectedPlatform"
                        @change="applyPlatform"
                    >
                        <option value="">@{{ platformColumn.label }}</option>

                        <option
                            v-for="option in platformColumn.filterable_options"
                            :key="option.value"
                            :value="option.value"
                            v-text="option.label"
                        ></option>
                    </select>

                    <!-- Filter Panel -->
                    <x-admin::datagrid.toolbar.filter>
                        <template #filter="{
                            available,
                            applied,
                            filters,
                            applyFilter,
                            applyColumnValues,
                            findAppliedColumn,
                            hasAnyAppliedColumnValues,
                            getAppliedColumnValues,
                            removeAppliedColumnValue,
                            removeAppliedColumnAllValues
                        }">
                            <slot
                                name="filter"
                                :available="available"
                                :applied="applied"
                                :filters="filters"
                                :apply-filter="applyFilter"
                                :apply-column-values="applyColumnValues"
                                :find-applied-column="findAppliedColumn"
                                :has-any-applied-column-values="hasAnyAppliedColumnValues"
                                :get-applied-column-values="getAppliedColumnValues"
                                :remove-applied-column-value="removeAppliedColumnValue"
                                :remove-applied-column-all-values="removeAppliedColumnAllValues"
                            >
                            </slot>
                        </template>
                    </x-admin::datagrid.toolbar.filter>
                </div>
            </template>
        </slot>
    </script>

    <script type="module">
        app.component('v-datagrid-search', {
            template: '#v-datagrid-search-template',

            props: ['isLoading', 'available', 'applied', 'src'],

            emits: ['search', 'filter', 'applySavedFilter'],

            data() {
                return {
                    filters: {
                        columns: [],
                    },

                    /**
                     * Pausa, en ms, entre la ultima tecla y la busqueda.
                     *
                     * Buscar en cada tecla dispararia una consulta por letra
                     * contra grillas que ya hacen varios joins; esperar a que la
                     * persona termine de escribir manda una sola. 300ms es el
                     * punto donde la busqueda se siente inmediata sin salir a
                     * mitad de una palabra.
                     */
                    searchDelay: 300,

                    searchTimer: null,
                };
            },

            mounted() {
                this.filters.columns = this.applied.filters.columns.filter((column) => column.index === 'all');
            },

            /**
             * Un temporizador vivo despues de destruido el componente busca
             * sobre una grilla que ya no esta en pantalla.
             */
            beforeUnmount() {
                clearTimeout(this.searchTimer);
            },

            computed: {
                /**
                 * The channel column, when this grid offers one.
                 */
                platformColumn() {
                    return this.available?.columns?.find(
                        column => column.index === 'platform' && column.filterable
                    );
                },

                /**
                 * The channel currently applied, for the select to show.
                 *
                 * The value arrives as an array when the column allows multiple
                 * values and as a bare string otherwise, so both are handled —
                 * reading the wrong one leaves the select blank while a filter
                 * is actually applied.
                 */
                selectedPlatform() {
                    const applied = this.applied?.filters?.columns?.find(column => column.index === 'platform');

                    if (! applied) {
                        return '';
                    }

                    return Array.isArray(applied.value) ? (applied.value[0] ?? '') : applied.value;
                },
            },

            methods: {
                /**
                 * Narrow the grid to one channel.
                 *
                 * Emits `filter`, not `search`: the parent's filter() keeps the
                 * 'all' column and replaces every other one, which is exactly
                 * the shape needed here. Going through search() would append a
                 * second platform column on top of the existing one instead of
                 * replacing it.
                 */
                applyPlatform($event) {
                    const value = $event.target.value;

                    // Every other panel filter is carried over untouched — only
                    // the channel is being changed.
                    const columns = (this.applied?.filters?.columns ?? []).filter(
                        column => column.index !== 'all' && column.index !== 'platform'
                    );

                    if (value) {
                        const column = this.platformColumn;

                        columns.push({
                            index: column.index,
                            label: column.label,
                            type: column.type,
                            value: column.allow_multiple_values ? [value] : value,
                            allow_multiple_values: column.allow_multiple_values,
                        });
                    }

                    this.$emit('filter', { columns });
                },

                /**
                 * Perform a search operation based on the input value.
                 *
                 * @param {Event} $event
                 * @returns {void}
                 */
                /**
                 * Buscar cuando la persona deja de escribir.
                 *
                 * El valor se copia ahora y no dentro del setTimeout: el evento
                 * de Vue se recicla, y leerlo tarde puede devolver el texto de
                 * otra pulsacion.
                 */
                searchDebounced($event) {
                    const requestedValue = $event.target.value;

                    clearTimeout(this.searchTimer);

                    this.searchTimer = setTimeout(
                        () => this.search({ target: { value: requestedValue } }),
                        this.searchDelay
                    );
                },

                /**
                 * Enter no espera: quien lo aprieta ya termino de escribir.
                 * Sin cancelar el temporizador, la busqueda saldria dos veces.
                 */
                searchNow($event) {
                    clearTimeout(this.searchTimer);

                    this.search($event);
                },

                search($event) {
                    let requestedValue = $event.target.value;

                    let appliedColumn = this.filters.columns.find(column => column.index === 'all');

                    if (! requestedValue) {
                        // Vaciar la caja es ahora un camino habitual (antes solo
                        // se llegaba aca con Enter). Sin columna aplicada no hay
                        // nada que limpiar, y tocar `.value` reventaria.
                        if (appliedColumn) {
                            appliedColumn.value = [];
                        }

                        this.$emit('search', this.filters);

                        return;
                    }

                    if (appliedColumn) {
                        appliedColumn.value = [requestedValue];
                    } else {
                        this.filters.columns.push({
                            index: 'all',
                            value: [requestedValue]
                        });
                    }

                    this.$emit('search', this.filters);
                },

                filter(filter) {
                    this.$emit('filter', filter);
                },

                applySavedFilter(filter) {
                    this.$emit('applySavedFilter', filter);
                },

                /**
                 * Get the searched values for a specific column.
                 *
                 * @param {string} columnIndex
                 * @returns {Array}
                 */
                getSearchedValues(columnIndex) {
                    let appliedColumn = this.filters.columns.find(column => column.index === 'all');

                    return appliedColumn?.value ?? [];
                },
            },
        });
    </script>
@endPushOnce
