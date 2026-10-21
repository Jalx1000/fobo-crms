{!! view_render_event('admin.leads.index.kanban.search.before') !!}

<v-kanban-search
    :is-loading="isLoading"
    :available="available"
    :applied="applied"
    @search="search"
>
</v-kanban-search>

{!! view_render_event('admin.leads.index.kanban.search.after') !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-kanban-search-template"
    >
        <div class="relative flex max-w-[445px] items-center max-lg:min-w-0 max-lg:flex-1 max-lg:max-w-full">
            <div class="icon-search absolute top-1.5 flex items-center text-2xl ltr:left-3 rtl:right-3"></div>

            <input
                type="text"
                name="search"
                class="block w-full rounded-lg border bg-white py-1.5 leading-6 text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400 ltr:pl-10 ltr:pr-9 rtl:pl-9 rtl:pr-10"
                placeholder="@lang('admin::app.leads.index.kanban.toolbar.search.title')"
                autocomplete="off"
                v-model="term"
                @input="handleInput"
                @keyup.enter="searchNow"
            >

            <span
                class="icon-cross-large absolute cursor-pointer text-xl text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white ltr:right-2 rtl:left-2"
                title="@lang('admin::app.leads.index.kanban.toolbar.search.clear')"
                v-if="term"
                @click="clear"
            >
            </span>
        </div>
    </script>

    <script type="module">
        app.component('v-kanban-search', {
            template: '#v-kanban-search-template',

            props: ['isLoading', 'available', 'applied'],

            emits: ['search'],

            data() {
                return {
                    filters: {
                        columns: [],
                    },

                    term: '',

                    debounceTimer: null,
                };
            },

            /**
             * Below this length the term is too broad to be worth a round trip: the
             * board fires one request per stage column, so searching on one or two
             * characters would hammer the endpoint on the way to a real term.
             */
            minLength: 3,

            debounceDelay: 400,

            mounted() {
                this.filters.columns = this.applied.filters.columns.filter((column) => column.index === 'all');

                this.term = this.getSearchedValue();
            },

            beforeUnmount() {
                clearTimeout(this.debounceTimer);
            },

            methods: {
                /**
                 * Auto-search as the user types. Clearing the box searches straight
                 * away (there is nothing to wait for); anything shorter than the
                 * minimum length is ignored until it grows or the user hits enter.
                 *
                 * @returns {void}
                 */
                handleInput() {
                    clearTimeout(this.debounceTimer);

                    const term = this.term.trim();

                    if (! term) {
                        this.searchNow();

                        return;
                    }

                    if (term.length < this.$options.minLength) {
                        return;
                    }

                    this.debounceTimer = setTimeout(this.searchNow, this.$options.debounceDelay);
                },

                /**
                 * Perform the search immediately, bypassing the debounce.
                 *
                 * @returns {void}
                 */
                searchNow() {
                    clearTimeout(this.debounceTimer);

                    const requestedValue = this.term.trim();

                    let appliedColumn = this.filters.columns.find(column => column.index === 'all');

                    /**
                     * `appliedColumn` is undefined until the first search runs, so it can
                     * only be mutated once it exists — clearing an untouched box used to
                     * throw "Cannot set properties of undefined".
                     */
                    if (appliedColumn) {
                        appliedColumn.value = requestedValue ? [requestedValue] : [];
                    } else if (requestedValue) {
                        this.filters.columns.push({
                            index: 'all',
                            value: [requestedValue]
                        });
                    }

                    this.$emit('search', this.filters);
                },

                /**
                 * Empty the box and drop the search.
                 *
                 * @returns {void}
                 */
                clear() {
                    this.term = '';

                    this.searchNow();
                },

                /**
                 * Get the currently applied search term.
                 *
                 * @returns {string}
                 */
                getSearchedValue() {
                    let appliedColumn = this.filters.columns.find(column => column.index === 'all');

                    return appliedColumn?.value?.[0] ?? '';
                },
            },
        });
    </script>
@endPushOnce
