<v-date-period-filter
    @filter-date="applyDateFilter"
>
</v-date-period-filter>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-date-period-filter-template"
    >
        <div class="flex flex-wrap items-center gap-1">
            <!-- Período rápido -->
            <button
                v-for="option in periods"
                :key="option.value"
                type="button"
                class="rounded px-2.5 py-1.5 text-xs font-medium transition-all"
                :class="activePeriod === option.value
                    ? 'bg-violet-700 text-white'
                    : 'border border-gray-200 bg-white text-gray-600 hover:border-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600'"
                @click="selectPeriod(option.value)"
            >
                @{{ option.label }}
            </button>

            <!-- Limpiar filtro -->
            <button
                v-if="activePeriod"
                type="button"
                class="icon-cross rounded p-1 text-base text-gray-500 transition-all hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                title="Limpiar filtro de fecha"
                @click="clearFilter"
            >
            </button>

            <!-- Rango personalizado -->
            <div
                v-if="activePeriod === 'custom'"
                class="flex items-center gap-1.5"
            >
                <input
                    type="date"
                    v-model="dateFrom"
                    class="rounded border w-full border-gray-200 bg-white py-1 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                />
                <span class="text-xs text-gray-400">-</span>
                <input
                    type="date"
                    v-model="dateTo"
                    class="rounded border w-full border-gray-200 bg-white py-1 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                />
                <button
                    type="button"
                    class="rounded bg-violet-700 px-2.5 py-1.5 text-xs font-medium text-white transition-all hover:bg-violet-800 disabled:opacity-50"
                    :disabled="! dateFrom || ! dateTo || dateFrom > dateTo"
                    @click="applyCustom"
                >
                    Aplicar
                </button>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-date-period-filter', {
            template: '#v-date-period-filter-template',

            emits: ['filter-date'],

            data() {
                return {
                    activePeriod: null,
                    dateFrom: '',
                    dateTo: '',
                    periods: [
                        { value: 'today',  label: 'Hoy' },
                        { value: 'week',   label: 'Esta semana' },
                        { value: 'month',  label: 'Este mes' },
                        { value: 'custom', label: 'Personalizado' },
                    ],
                };
            },

            methods: {
                selectPeriod(period) {
                    if (this.activePeriod === period && period !== 'custom') {
                        this.clearFilter();
                        return;
                    }

                    this.activePeriod = period;

                    if (period !== 'custom') {
                        this.$emit('filter-date', { period, date_from: null, date_to: null });
                    }
                },

                applyCustom() {
                    if (! this.dateFrom || ! this.dateTo || this.dateFrom > this.dateTo) {
                        return;
                    }

                    this.$emit('filter-date', {
                        period:    'custom',
                        date_from: this.dateFrom,
                        date_to:   this.dateTo,
                    });
                },

                clearFilter() {
                    this.activePeriod = null;
                    this.dateFrom     = '';
                    this.dateTo       = '';
                    this.$emit('filter-date', { period: null, date_from: null, date_to: null });
                },

                restore(dateFilter) {
                    if (! dateFilter || ! dateFilter.period) return;

                    this.activePeriod = dateFilter.period;

                    if (dateFilter.period === 'custom') {
                        this.dateFrom = dateFilter.date_from ?? '';
                        this.dateTo   = dateFilter.date_to ?? '';
                    }
                },
            },
        });
    </script>
@endPushOnce
