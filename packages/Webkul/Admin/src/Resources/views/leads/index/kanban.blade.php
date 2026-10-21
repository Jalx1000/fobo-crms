{!! view_render_event('admin.leads.index.kanban.before') !!}

<!-- Kanban Vue Component -->
<v-leads-kanban ref="leadsKanban">
    <div class="flex flex-col gap-4">
        <!-- Shimmer -->
        <x-admin::shimmer.leads.index.kanban />
    </div>
</v-leads-kanban>

{!! view_render_event('admin.leads.index.kanban.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-leads-kanban-template"
    >
        <template v-if="isLoading">
            <div class="flex flex-col gap-4">
                <x-admin::shimmer.leads.index.kanban />
            </div>
        </template>

        <template v-else>
            <div class="flex flex-col gap-4">
                @include('admin::leads.index.kanban.toolbar')

                {!! view_render_event('admin.leads.index.kanban.content.before') !!}

                <!--
                    Celular: una etapa por pantalla. Las pestañas saltan a la
                    etapa y se marcan solas al deslizar entre columnas.
                -->
                <div
                    class="-mx-3 flex gap-1 overflow-x-auto border-b border-gray-200 bg-white px-3 dark:border-gray-800 dark:bg-gray-900 lg:hidden"
                    role="tablist"
                    aria-label="@lang('admin::app.leads.index.kanban.etapas')"
                >
                    <button
                        type="button"
                        role="tab"
                        class="h-11 shrink-0 whitespace-nowrap border-b-[3px] px-3 text-sm transition"
                        :class="etapaVisible === posicion ? 'border-brandColor font-semibold text-brandColor' : 'border-transparent text-gray-600 dark:text-gray-300'"
                        :aria-selected="etapaVisible === posicion ? 'true' : 'false'"
                        v-for="(stage, clave, posicion) in stageLeads"
                        @click="irAEtapa(posicion)"
                    >
                        @{{ stage.name }} · @{{ stage.leads.meta.total }}
                    </button>
                </div>

                <div
                    ref="columnas"
                    class="flex gap-2.5 overflow-x-auto max-lg:snap-x max-lg:snap-mandatory max-lg:gap-3"
                    @scroll.passive="alDesplazarColumnas"
                >
                    <!-- Stage Cards -->
                    <div
                        class="flex min-w-[275px] max-w-[275px] flex-col gap-1 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 max-lg:min-w-full max-lg:max-w-full max-lg:snap-start"
                        v-for="(stage, index) in stageLeads"
                    >
                        {!! view_render_event('admin.leads.index.kanban.content.stage.header.before') !!}

                        <!-- Stage Header -->
                        <div class="flex flex-col px-2 py-3">
                            <!-- Stage Title and Action -->
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium dark:text-white">
                                    @{{ stage.name }} (@{{ stage.leads.meta.total }})
                                </span>

                                @if (bouncer()->hasPermission('leads.create'))
                                    <a
                                        :href="'{{ route('admin.leads.create') }}' + '?stage_id=' + stage.id"
                                        class="icon-add cursor-pointer rounded p-1 text-lg text-gray-600 transition-all hover:bg-gray-200 hover:text-gray-800 dark:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-white"
                                        target="_blank"
                                    >
                                    </a>
                                @endif
                            </div>

                            <!-- Stage Total Leads and Amount -->
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-medium dark:text-white">
                                    @{{ $admin.formatPrice(stage.lead_value) }}
                                </span>

                                <!-- Progress Bar -->
                                <div class="h-1 w-36 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                    <div
                                        class="h-1 bg-green-500"
                                        :style="{ width: (stage.lead_value / totalStagesAmount) * 100 + '%' }"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        {!! view_render_event('admin.leads.index.kanban.content.stage.header.after') !!}

                        {!! view_render_event('admin.leads.index.kanban.content.stage.body.before') !!}

                        <!-- Draggable Stage Lead Cards -->
                        <draggable
                            class="flex h-[calc(100vh-317px)] flex-col gap-2 overflow-y-auto p-2"
                            :class="{ 'justify-center': stage.leads.data.length === 0 }"
                            ghost-class="draggable-ghost"
                            handle=".lead-item"
                            v-bind="{animation: 200, delay: 300, delayOnTouchOnly: true}"
                            :list="stage.leads.data"
                            item-key="id"
                            group="leads"
                            @scroll="handleScroll(stage, $event)"
                            @change="handleUpdate(stage, $event)"
                        >
                            <template #header>
                                <div
                                    class="flex flex-col items-center justify-center"
                                    v-if="! stage.leads.data.length"
                                >
                                    <img
                                        class="dark:mix-blend-exclusion dark:invert"
                                        src="{{ vite()->asset('images/empty-placeholders/pipedrive.svg') }}"
                                    >

                                    <div class="flex flex-col items-center gap-4">
                                        <div class="flex flex-col items-center gap-2">
                                            <p class="!text-base font-semibold dark:text-white">
                                                @lang('admin::app.leads.index.kanban.empty-list')
                                            </p>

                                            <p class="!text-sm text-gray-400 dark:text-gray-400">
                                                @lang('admin::app.leads.index.kanban.empty-list-description')
                                            </p>
                                        </div>

                                        @if (bouncer()->hasPermission('leads.create'))
                                            <a
                                                :href="'{{ route('admin.leads.create') }}' + '?stage_id=' + stage.id"
                                                class="secondary-button"
                                            >
                                                @lang('admin::app.leads.index.kanban.create-lead-btn')
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </template>

                            <!-- Lead Card -->
                            <template #item="{ element, index }">
                                {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.before') !!}

                                <a
                                    class="lead-item flex cursor-pointer flex-col gap-5 rounded-md border border-gray-100 bg-gray-50 p-2 dark:border-gray-400 dark:bg-gray-400"
                                    :href="'{{ route('admin.leads.view', 'replaceId') }}'.replace('replaceId', element.id)"
                                >
                                    {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.header.before') !!}

                                    <!-- Header -->
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-center gap-1">
                                            <x-admin::avatar ::name="element.person?.name || ''" />

                                            <div class="flex flex-col gap-0.5">
                                                <span class="text-xs font-medium">
                                                    @{{ element.person?.name || '' }}
                                                </span>

                                                <span class="text-[10px] leading-normal">
                                                    @{{ element.person?.organization?.name || '' }}
                                                </span>
                                            </div>
                                        </div>

                                        <div
                                            class="group relative"
                                            v-if="element.rotten_days > 0"
                                        >
                                            <span class="icon-rotten cursor-default text-xl text-rose-600"></span>

                                            <div class="absolute -top-1 right-7 hidden w-max flex-col items-center group-hover:flex">
                                                <span class="whitespace-no-wrap relative rounded-md bg-black px-4 py-2 text-xs leading-none text-white shadow-lg">
                                                    @{{ "@lang('admin::app.leads.index.kanban.rotten-days', ['days' => 'replaceDays'])".replace('replaceDays', element.rotten_days) }}
                                                </span>

                                                <div class="absolute -right-1 top-2 h-3 w-3 rotate-45 bg-black"></div>
                                            </div>
                                        </div>
                                    </div>

                                    {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.header.after') !!}

                                    {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.title.before') !!}

                                    <!-- Lead Title -->
                                    <p class="text-xs font-medium">
                                        @{{ element.title }}
                                    </p>

                                    {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.title.after') !!}

                                    <div class="flex flex-wrap gap-1">
                                        <!-- City badge (only in "all pipelines" mode) -->
                                        <div
                                            class="flex items-center gap-1 rounded-xl bg-violet-100 px-2 py-1 text-xs font-semibold text-violet-700 dark:bg-violet-900 dark:text-violet-200"
                                            v-if="allPipelines && element.pipeline"
                                        >
                                            <span class="icon-location text-sm"></span>

                                            @{{ element.pipeline?.name || '' }}
                                        </div>

                                        <div
                                            class="flex items-center gap-1 rounded-xl bg-gray-200 px-2 py-1 text-xs font-medium dark:bg-gray-800 dark:text-white"
                                            v-if="element.user"
                                        >
                                            <span class="icon-settings-user text-sm"></span>

                                            @{{ element.user?.name || '' }}
                                        </div>

                                        <div class="rounded-xl bg-gray-200 px-2 py-1 text-xs font-medium dark:bg-gray-800 dark:text-white">
                                            @{{ element.formatted_lead_value }}
                                        </div>

                                        <div 
                                            class="rounded-xl bg-gray-200 px-2 py-1 text-xs font-medium dark:bg-gray-800 dark:text-white"
                                            v-if="element.source"
                                        >
                                            @{{ element.source?.name || '' }}
                                        </div>

                                        <div 
                                            class="rounded-xl bg-gray-200 px-2 py-1 text-xs font-medium dark:bg-gray-800 dark:text-white"
                                            v-if="element.type"
                                        >
                                            @{{ element.type?.name || '' }}
                                        </div>

                                        <!-- Tags -->
                                        <template v-for="tag in element.tags">
                                            {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.tag.before') !!}

                                            <div
                                                class="rounded-xl bg-gray-200 px-2 py-1 text-xs font-medium dark:bg-gray-800"
                                                v-if="tag"
                                                :style="{
                                                    backgroundColor: tag?.color,
                                                    color: tagTextColor[tag?.color]
                                                }"
                                            >
                                                @{{ tag?.name || '' }}
                                            </div>

                                            {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.tag.after') !!}
                                        </template>
                                    </div>
                                </a>

                                {!! view_render_event('admin.leads.index.kanban.content.stage.body.card.after') !!}
                            </template>
                        </draggable>

                        {!! view_render_event('admin.leads.index.kanban.content.stage.body.after') !!}
                    </div>
                </div>

                {!! view_render_event('admin.leads.index.kanban.content.after') !!}
            </div>

            <!-- Show modal for additional information while updating the leads into won or lost stage. -->
            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="stageUpdateForm"
            >
                <form @submit="handleSubmit($event, handleFormSubmit)">
                    <!-- Modal -->
                    <x-admin::modal
                        ref="stageUpdateModal"
                        @toggle="handleCloseModal"
                    >
                        <!-- Header -->
                        <x-slot:header>
                            <h3 class="text-base font-semibold dark:text-white">
                                @lang('admin::app.leads.index.kanban.stages.need-more-info')
                            </h3>
                        </x-slot>

                        <!-- Content -->
                        <x-slot:content>
                            <x-admin::form.control-group.control
                                type="hidden"
                                name="lead_pipeline_stage_id"
                                ::value="finalized.stage.id"
                            />

                            <!-- Won Value -->
                            <template v-if="finalized.stage.code == 'won'">
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>
                                        @lang('admin::app.leads.index.kanban.stages.won-value')
                                    </x-admin::form.control-group.label>

                                    <x-admin::form.control-group.control
                                        type="price"
                                        name="lead_value"
                                        ::value="finalized.lead.lead_value"
                                    />
                                </x-admin::form.control-group>
                            </template>

                            <!-- Lost Reason -->
                            <template v-else>
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>
                                        @lang('admin::app.leads.index.kanban.stages.lost-reason')
                                    </x-admin::form.control-group.label>

                                    <x-admin::form.control-group.control
                                        type="textarea"
                                        name="lost_reason"
                                    />
                                </x-admin::form.control-group>
                            </template>

                            <!-- Closed At -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>
                                    @lang('admin::app.leads.index.kanban.stages.closed-at')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="datetime"
                                    name="closed_at"
                                    :label="trans('admin::app.leads.index.kanban.stages.closed-at')"
                                />

                                <x-admin::form.control-group.error control-name="closed_at"/>
                            </x-admin::form.control-group>
                        </x-slot>

                        <!-- Footer -->
                        <x-slot:footer>
                            <x-admin::button
                                class="primary-button"
                                :title="trans('admin::app.leads.index.kanban.stages.save-btn')"
                                ::loading="finalized.updating"
                                ::disabled="finalized.updating"
                            />
                        </x-slot>
                    </x-admin::modal>
                </form>
            </x-admin::form>
        </template>
    </script>

    <script type="module">
        app.component('v-leads-kanban', {
            template: '#v-leads-kanban-template',

            data() {
                return {
                    available: {
                        columns: @json($columns),
                    },

                    // Celular: la etapa que se ve (índice de la columna).
                    etapaVisible: 0,

                    /**
                     * Per-pipeline storage key so applied filters never bleed across
                     * pipelines (cities) or the "all prospects" view.
                     */
                    src: "{{ request('pipeline_id') ?: 'default' }}",

                    applied: {
                        filters: {
                            columns: [],
                        }
                    },

                    finalized: {
                        lead: null,
                        stage: null,
                        updating: false,
                    },

                    stages: @json($pipeline->stages->toArray()),

                    allPipelines: @json($allPipelines ?? false),

                    stageLeads: {},

                    /**
                     * Origen del ultimo arrastre, para devolver la tarjeta a su
                     * columna si el backend rechaza el cambio de etapa.
                     */
                    lastRemoval: null,

                    isLoading: true,

                    tagTextColor: {
                        '#FEE2E2': '#DC2626',
                        '#FFEDD5': '#EA580C',
                        '#FEF3C7': '#D97706',
                        '#FEF9C3': '#CA8A04',
                        '#ECFCCB': '#65A30D',
                        '#DCFCE7': '#16A34A',
                    },
                };
            },

            computed: {
                /**
                 * Computes the total amount of leads across all stages.
                 *
                 * @return {number} The total amount of leads.
                 */
                totalStagesAmount() {
                    let totalAmount = 0;

                    for (let [key, stage] of Object.entries(this.stageLeads)) {
                        totalAmount += parseFloat(stage.lead_value);
                    }

                    return totalAmount;
                }
            },

            mounted () {
                this.boot();
            },

            methods: {
                /**
                 * Celular: lleva a la columna de la etapa elegida.
                 */
                irAEtapa(posicion) {
                    const columnas = this.$refs.columnas;

                    const columna = columnas?.children[posicion];

                    if (columna) {
                        columnas.scrollTo({ left: columna.offsetLeft - columnas.offsetLeft, behavior: 'smooth' });
                    }
                },

                /**
                 * Celular: al deslizar, marca la pestaña de la columna que quedó a la vista.
                 */
                alDesplazarColumnas(event) {
                    const columnas = event.target;

                    if (! columnas.clientWidth) {
                        return;
                    }

                    // La columna más cerca del borde izquierdo.
                    const inicio = columnas.scrollLeft + columnas.offsetLeft;

                    let cercana = 0;

                    [...columnas.children].forEach((columna, posicion) => {
                        if (Math.abs(columna.offsetLeft - inicio) < Math.abs(columnas.children[cercana].offsetLeft - inicio)) {
                            cercana = posicion;
                        }
                    });

                    this.etapaVisible = cercana;
                },

                /**
                 * Initialization: This function checks for any previously saved filters in local storage and applies them as needed.
                 *
                 * @returns {void}
                 */
                boot() {
                    let kanbans = this.getKanbans();

                    if (kanbans?.length) {
                        const currentKanban = kanbans.find(({ src }) => src === this.src);

                        if (currentKanban) {
                            this.applied.filters = currentKanban.applied.filters;

                            this.applySearchFromUrl();

                            this.get()
                                .then(response => {
                                    for (let [sortOrder, data] of Object.entries(response.data)) {
                                        this.stageLeads[sortOrder] = data;
                                    }
                                });

                            return;
                        }
                    }

                    this.applySearchFromUrl();

                    this.get()
                        .then(response => {
                            for (let [sortOrder, data] of Object.entries(response.data)) {
                                this.stageLeads[sortOrder] = data;
                            }
                        });
                },

                /**
                 * Seeds the search term from the "search" query param, which is how the
                 * view switcher hands it over when coming from the table view. The URL
                 * wins over whatever was left in local storage, since it reflects the
                 * search the user was actually looking at.
                 *
                 * @returns {void}
                 */
                applySearchFromUrl() {
                    const term = new URLSearchParams(window.location.search).get('search');

                    if (term === null) {
                        return;
                    }

                    const appliedColumn = this.applied.filters.columns.find(column => column.index === 'all');

                    if (appliedColumn) {
                        appliedColumn.value = term ? [term] : [];
                    } else if (term) {
                        this.applied.filters.columns.push({ index: 'all', value: [term] });
                    }
                },

                /**
                 * Normalizes an applied column's value into a flat array.
                 *
                 * The filter drawer stores date quick-filters as a bare string
                 * ("today") and date ranges as a nested array ([[from, to]]), so
                 * calling `.join()` on the raw value blows up for the former.
                 *
                 * @param {string|Array} value
                 * @returns {Array}
                 */
                toValues(value) {
                    if (value === undefined || value === null || value === '') {
                        return [];
                    }

                    return Array.isArray(value) ? value.flat() : [value];
                },

                /**
                 * Strips the characters that delimit the RequestCriteria search
                 * protocol ("field:value,value;field:value"). Without this, a term
                 * containing ":" or ";" is silently truncated server side.
                 *
                 * @param {string} value
                 * @returns {string}
                 */
                escapeSearchValue(value) {
                    return String(value).replace(/[;:]/g, ' ').trim();
                },

                /**
                 * Fetches the leads based on the applied filters.
                 *
                 * @param {object} requestedParams - The requested parameters.
                 * @returns {Promise} The promise object representing the request.
                 */
                get(requestedParams = {}) {
                    let params = {
                        search: '',
                        searchFields: '',
                        /**
                         * RequestCriteria ORs every field of "search" together unless
                         * told otherwise, which would make each added filter *widen*
                         * the result set instead of narrowing it.
                         */
                        searchJoin: 'and',
                        pipeline_id: "{{ request('pipeline_id') }}",
                        limit: 20,
                    };

                    this.applied.filters.columns.forEach((column) => {
                        if (column.index === 'all') {
                            const term = this.toValues(column.value).join(' ').trim();

                            if (! term) {
                                return;
                            }

                            /**
                             * Free-text search travels in its own "q" param instead of the
                             * "field:value;" protocol: the term must match the lead title OR
                             * the contact (name, email, phone, messenger id) as a single
                             * grouped condition, which that protocol cannot express.
                             */
                            params['q'] = term;

                            return;
                        }

                        /**
                         * If the column is a searchable dropdown, then we need to append the column value
                         * with the column label. Otherwise, we can directly append the column value.
                         */
                        const values = column.filterable_type === 'searchable_dropdown'
                            ? this.toValues(column.value).map(option => option?.value ?? option)
                            : this.toValues(column.value);

                        params['search'] += `${column.index}:${values.map(this.escapeSearchValue).join(',')};`;

                        params['searchFields'] += `${column.index}:${column.search_field};`;
                    });

                    /**
                     * Only the params that scope the whole page are carried over from the
                     * URL. Copying the full query string would let a shared/bookmarked
                     * link overwrite the search and paging params built above.
                     */
                    const urlParams = new URLSearchParams(window.location.search);

                    ['pipeline_id', 'date_filter', 'date_from', 'date_to'].forEach((key) => {
                        if (urlParams.has(key)) {
                            params[key] = urlParams.get(key);
                        }
                    });

                    return this.$axios
                        .get("{{ route('admin.leads.get') }}", {
                            params: {
                                ...params,

                                ...requestedParams,
                            }
                        })
                        .then(response => {
                            this.isLoading = false;

                            this.updateKanbans();

                            return response;
                        })
                        .catch(error => {
                            console.log(error)
                        });
                },

                /**
                 * Filters the leads based on the applied filters.
                 *
                 * @param {object} filters - The filters to be applied.
                 * @returns {void}
                 */
                filter(filters) {
                    this.applied.filters.columns = [
                        ...(this.applied.filters.columns.filter((column) => column.index === 'all')),
                        ...filters.columns,
                    ];

                    this.get()
                        .then(response => {
                            for (let [sortOrder, data] of Object.entries(response.data)) {
                                this.stageLeads[sortOrder] = data;
                            }
                        });
                },

                /**
                 * Searches the leads based on the applied filters.
                 *
                 * @param {object} filters - The filters to be applied.
                 * @returns {void}
                 */
                search(filters) {
                    this.applied.filters.columns = [
                        ...(this.applied.filters.columns.filter((column) => column.index !== 'all')),
                        ...filters.columns,
                    ];

                    this.get()
                        .then(response => {
                            for (let [sortOrder, data] of Object.entries(response.data)) {
                                this.stageLeads[sortOrder] = data;
                            }
                        });
                },

                /**
                 * Appends the leads to the stage.
                 *
                 * @param {object} params - The parameters to be appended.
                 * @returns {void}
                 */
                append(params) {
                    this.get(params)
                        .then(response => {
                            for (let [sortOrder, data] of Object.entries(response.data)) {
                                if (! this.stageLeads[sortOrder]) {
                                    this.stageLeads[sortOrder] = data;
                                } else {
                                    this.stageLeads[sortOrder].leads.data = this.stageLeads[sortOrder].leads.data.concat(data.leads.data);

                                    this.stageLeads[sortOrder].leads.meta = data.leads.meta;
                                }
                            }
                        });
                },

                /**
                 * Updates the stage with the latest lead data.
                 *
                 * @param {object} stage - The stage object.
                 * @param {object} event - The event object.
                 * @returns {void}
                 */
                handleUpdate(stage, event) {
                    if (event.moved) {
                        return;
                    }

                    if (
                        (stage.code === "won" || stage.code === "lost")
                        && event.added
                        && event.added.element
                    ) {
                        this.finalized.lead = event.added.element;

                        this.finalized.stage = stage;

                        this.toggleStageUpdateModal();

                        return;
                    }

                    if (event.removed) {
                        stage.lead_value = parseFloat(stage.lead_value) - parseFloat(event.removed.element.lead_value);

                        this.stageLeads[stage.sort_order].leads.meta.total = this.stageLeads[stage.sort_order].leads.meta.total - 1;

                        /**
                         * Vuedraggable emite "removed" en la columna de origen antes que
                         * "added" en la de destino. Guardamos el origen para poder devolver
                         * la tarjeta si el backend rechaza el cambio de etapa.
                         */
                        this.lastRemoval = {
                            stage:    stage,
                            element:  event.removed.element,
                            oldIndex: event.removed.oldIndex,
                        };

                        return;
                    }

                    stage.lead_value = parseFloat(stage.lead_value) + parseFloat(event.added.element.lead_value);

                    this.stageLeads[stage.sort_order].leads.meta.total = this.stageLeads[stage.sort_order].leads.meta.total + 1;

                    let removal = this.lastRemoval;

                    this.lastRemoval = null;

                    this.updateStage('{{ route('admin.leads.stage.update', '__LEAD_ID__') }}'.replace('__LEAD_ID__', event.added.element.id), {
                        'lead_pipeline_stage_id': stage.id
                    })
                        .then(response => {
                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                        })
                        .catch(error => {
                            this.revertMove(stage, event.added, removal);

                            this.$emitter.emit('add-flash', {
                                type:    'error',
                                message: error.response?.status === 401
                                    ? "@lang('admin::app.leads.stage-permission-denied')"
                                    : error.response?.data?.message,
                            });
                        });
                },

                /**
                 * Devuelve la tarjeta a su columna original cuando el backend rechaza
                 * el cambio de etapa, para que el tablero no muestre una etapa que la
                 * base de datos no tiene.
                 *
                 * @param {object} target - La etapa de destino.
                 * @param {object} added - El evento "added" de vuedraggable.
                 * @param {object|null} removal - El origen capturado en el evento "removed".
                 *
                 * @returns {void}
                 */
                revertMove(target, added, removal) {
                    /**
                     * Sin el origen capturado no podemos deshacer el movimiento a mano,
                     * asi que recargamos el tablero respetando los filtros aplicados.
                     */
                    if (! removal) {
                        this.get().then(response => {
                            for (let [sortOrder, data] of Object.entries(response.data)) {
                                this.stageLeads[sortOrder] = data;
                            }
                        });

                        return;
                    }

                    let value = parseFloat(added.element.lead_value);

                    let targetLeads = this.stageLeads[target.sort_order].leads;

                    targetLeads.data.splice(targetLeads.data.indexOf(added.element), 1);

                    target.lead_value = parseFloat(target.lead_value) - value;

                    targetLeads.meta.total = targetLeads.meta.total - 1;

                    let sourceLeads = this.stageLeads[removal.stage.sort_order].leads;

                    sourceLeads.data.splice(removal.oldIndex, 0, removal.element);

                    removal.stage.lead_value = parseFloat(removal.stage.lead_value) + value;

                    sourceLeads.meta.total = sourceLeads.meta.total + 1;
                },

                /**
                 * Updates the stage with the latest lead data.
                 *
                 * @param {string} url - The URL to update the stage.
                 * @param {object} params - The parameters to be updated.
                 *
                 * @returns {Promise} The promise object representing the request.
                 */
                updateStage(url, params) {
                    return this.$axios.put(url, params);
                },

                handleFormSubmit(params) {
                    this.finalized.updating = true;

                    this.updateStage("{{ route('admin.leads.stage.update', '__LEAD_ID__') }}".replace('__LEAD_ID__', this.finalized.lead.id), {
                        ...params,
                        lead_pipeline_stage_id: this.finalized.stage.id,
                    })
                        .then(response => {
                            this.toggleStageUpdateModal();

                            this.resetFinalized();

                            this.get()
                                .then(response => {
                                    for (let [sortOrder, data] of Object.entries(response.data)) {
                                        this.stageLeads[sortOrder] = data;
                                    }
                                });

                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                        })
                        .catch(error => {
                            /**
                             * La tarjeta ya fue movida por el arrastre antes de abrir el
                             * modal, asi que recargamos el tablero para devolverla a su
                             * etapa real cuando el backend rechaza el cambio.
                             */
                            this.get().then(response => {
                                for (let [sortOrder, data] of Object.entries(response.data)) {
                                    this.stageLeads[sortOrder] = data;
                                }
                            });

                            this.$emitter.emit('add-flash', {
                                type:    'error',
                                message: error.response?.status === 401
                                    ? "@lang('admin::app.leads.stage-permission-denied')"
                                    : error.response?.data?.message,
                            });
                        }).finally(() => {
                            this.finalized.updating = false;
                        });
                },

                /**
                 * Resets the finalized lead and stage data.
                 *
                 * @returns {void}
                 */
                resetFinalized() {
                    this.finalized = {
                        lead: null,
                        stage: null,
                        updating: false,
                    };
                },

                /**
                 * Handles the close event of the modal.
                 *
                 * @returns {void}
                 */
                handleCloseModal(state) {
                    if (state.isActive) {
                        return;
                    }

                    this.resetFinalized();

                    this.get()
                        .then(response => {
                            for (let [sortOrder, data] of Object.entries(response.data)) {
                                this.stageLeads[sortOrder] = data;
                            }
                        });
                },

                /**
                 * Toggles the stage update modal.
                 *
                 * @returns {void}
                 */
                toggleStageUpdateModal() {
                    this.$refs.stageUpdateModal.toggle();
                },

                /**
                 * Handles the scroll event on the stage leads.
                 *
                 * @param {object} stage - The stage object.
                 * @param {object} event - The scroll event.
                 * @returns {void}
                 */
                handleScroll(stage, event) {
                    const bottom = event.target.scrollHeight - event.target.scrollTop === event.target.clientHeight;

                    if (! bottom) {
                        return;
                    }

                    if (this.stageLeads[stage.sort_order].leads.meta.current_page == this.stageLeads[stage.sort_order].leads.meta.last_page) {
                        return;
                    }

                    this.append({
                        pipeline_stage_id: stage.id,
                        pipeline_id: stage.lead_pipeline_id,
                        page: this.stageLeads[stage.sort_order].leads.meta.current_page + 1,
                        limit: 20,
                    });
                },

                //=======================================================================================
                // Support for previous applied values in kanban's. All code is based on local storage.
                //=======================================================================================

                /**
                 * Updates the kanban's stored in local storage with the latest data.
                 *
                 * @returns {void}
                 */
                 updateKanbans() {
                    let kanbans = this.getKanbans();

                    if (kanbans?.length) {
                        const currentKanban = kanbans.find(({ src }) => src === this.src);

                        if (currentKanban) {
                            kanbans = kanbans.map(kanban => {
                                if (kanban.src === this.src) {
                                    return {
                                        ...kanban,
                                        requestCount: ++kanban.requestCount,
                                        available: this.available,
                                        applied: this.applied,
                                    };
                                }

                                return kanban;
                            });
                        } else {
                            kanbans.push(this.getKanbanInitialProperties());
                        }
                    } else {
                        kanbans = [this.getKanbanInitialProperties()];
                    }

                    this.setKanbans(kanbans);
                },

                /**
                 * Returns the initial properties for a kanban.
                 *
                 * @returns {object} Initial properties for a kanban.
                 */
                getKanbanInitialProperties() {
                    return {
                        src: this.src,
                        requestCount: 0,
                        available: this.available,
                        applied: this.applied,
                    };
                },

                /**
                 * Returns the storage key for kanban's in local storage.
                 *
                 * @returns {string} Storage key for kanban's.
                 */
                getKanbansStorageKey() {
                    return 'kanbans';
                },

                /**
                 * Retrieves the kanban's stored in local storage.
                 *
                 * @returns {Array} Kanban's stored in local storage.
                 */
                getKanbans() {
                    let kanbans = localStorage.getItem(
                        this.getKanbansStorageKey()
                    );

                    return JSON.parse(kanbans) ?? [];
                },

                /**
                 * Sets the kanban's in local storage.
                 *
                 * @param {Array} kanbans - Kanban's to be stored in local storage.
                 * @returns {void}
                 */
                setKanbans(kanbans) {
                    localStorage.setItem(
                        this.getKanbansStorageKey(),
                        JSON.stringify(kanbans)
                    );
                },
            }
        });
    </script>
@endPushOnce
