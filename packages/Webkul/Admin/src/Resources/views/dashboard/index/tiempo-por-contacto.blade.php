{!! view_render_event('admin.dashboard.index.tiempo_por_contacto.before') !!}

<v-dashboard-tiempo-por-contacto>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-tiempo-por-contacto>

{!! view_render_event('admin.dashboard.index.tiempo_por_contacto.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-tiempo-por-contacto-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <!-- Encabezado: título + filtro por encargado -->
                <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold dark:text-gray-300">Tiempo en responder - resumido por contacto</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Del contacto del lead a la respuesta del encargado - @{{ meta.total }} leads
                        </p>
                    </div>

                    <!-- Un encargado solo se ve a sí mismo: el selector de un solo nombre no filtra nada. -->
                    <select
                        v-if="encargados.length > 1"
                        class="flex min-h-[39px] w-[200px] rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="encargadoId"
                        @change="changeEncargado"
                    >
                        <option value="">Todos los encargados</option>

                        <option
                            v-for="encargado in encargados"
                            :key="encargado.id"
                            :value="encargado.id"
                        >
                            @{{ encargado.name }}
                        </option>
                    </select>
                </div>

                <!-- Tabla de detalle -->
                <div class="w-full max-w-full overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-800">
                                <th
                                    class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400"
                                    style="letter-spacing: .05em"
                                    v-for="column in columns"
                                    :key="column"
                                >
                                    @{{ column }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-if="! rows.length">
                                <td
                                    class="px-4 py-4 text-center text-sm text-gray-500 dark:text-gray-400"
                                    :colspan="columns.length"
                                >
                                    No hay leads en el periodo seleccionado.
                                </td>
                            </tr>

                            <tr
                                class="border-b border-gray-100 align-top last:border-b-0 dark:border-gray-800"
                                v-for="row in rows"
                                :key="row.lead_id"
                            >
                                <!-- Fecha de contacto del lead -->
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span class="block text-sm text-gray-600 dark:text-gray-300">@{{ row.contact_at }}</span>
                                    <span class="text-xs text-gray-400 dark:text-gray-400">#@{{ row.lead_id }}</span>
                                </td>

                                <!-- Nombre del person -->
                                <td class="px-4 py-2.5">
                                    <span class="block max-w-xs truncate text-sm font-semibold text-gray-700 dark:text-gray-300">
                                        @{{ row.person_name }}
                                    </span>
                                </td>

                                <!-- Respuesta del agente (rol Sistema deja el lead en No atendido) -->
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span
                                        class="block text-sm"
                                        :class="row.agent_at ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400 dark:text-gray-400'"
                                    >
                                        @{{ row.agent_at || 'Carga manual' }}
                                    </span>

                                    <span
                                        class="mt-1 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :style="chipStyle(row.agent_light)"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :style="dotStyle(row.agent_light)"></span>
                                        @{{ row.agent_human || 'n/d' }}
                                    </span>
                                </td>

                                <!-- Respuesta del encargado (rol Encargado lo saca de No atendido) -->
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span
                                        class="block text-sm"
                                        :class="row.encargado_at ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400 dark:text-gray-400'"
                                    >
                                        @{{ row.encargado_at || 'Sin atender' }}
                                    </span>

                                    <span
                                        class="mt-1 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :style="chipStyle(row.encargado_light)"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :style="dotStyle(row.encargado_light)"></span>
                                        @{{ row.encargado_human || 'n/d' }}
                                    </span>
                                </td>

                                <!-- Total contacto -> encargado -->
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :style="chipStyle(row.total_light)"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :style="dotStyle(row.total_light)"></span>
                                        @{{ row.total_human || 'n/d' }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">
                                    @{{ row.encargado_name }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pie: leyenda del semáforo + paginación -->
                <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                    <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                        <span
                            class="flex items-center gap-1.5"
                            v-for="item in legend"
                            :key="item.light"
                        >
                            <span class="h-2 w-2 rounded-full" :style="dotStyle(item.light)"></span>
                            @{{ item.label }}
                        </span>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="mr-1 text-xs text-gray-500 dark:text-gray-400">
                            @{{ meta.per_page }} por página - página @{{ meta.current_page }} de @{{ meta.last_page }}
                        </span>

                        <button
                            type="button"
                            class="flex w-8 items-center justify-center rounded-md border px-2 py-1 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300 dark:hover:border-gray-400"
                            style="min-height: 30px"
                            :style="{ opacity: meta.current_page <= 1 ? .5 : 1 }"
                            :disabled="meta.current_page <= 1"
                            @click="changePage(meta.current_page - 1)"
                        >
                            &lsaquo;
                        </button>

                        <button
                            type="button"
                            class="flex w-8 items-center justify-center rounded-md border px-2 py-1 text-sm transition-all hover:border-gray-400 dark:border-gray-800 dark:hover:border-gray-400"
                            style="min-height: 30px"
                            :class="page === meta.current_page
                                ? 'border-transparent bg-brandColor font-semibold text-white'
                                : 'text-gray-600 dark:text-gray-300'"
                            v-for="page in pageNumbers"
                            :key="page"
                            @click="changePage(page)"
                        >
                            @{{ page }}
                        </button>

                        <button
                            type="button"
                            class="flex w-8 items-center justify-center rounded-md border px-2 py-1 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300 dark:hover:border-gray-400"
                            style="min-height: 30px"
                            :style="{ opacity: meta.current_page >= meta.last_page ? .5 : 1 }"
                            :disabled="meta.current_page >= meta.last_page"
                            @click="changePage(meta.current_page + 1)"
                        >
                            &rsaquo;
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-tiempo-por-contacto', {
            template: '#v-dashboard-tiempo-por-contacto-template',

            data() {
                return {
                    rows: [],
                    encargados: [],
                    encargadoId: '',
                    isLoading: true,

                    /**
                     * Los filtros globales (ciudad + rango de fechas) llegan por el
                     * emitter; se guardan para poder recombinarlos con la página y el
                     * encargado sin perderlos al paginar.
                     */
                    filters: {},

                    meta: {
                        total: 0,
                        per_page: 15,
                        current_page: 1,
                        last_page: 1,
                    },

                    columns: [
                        'Contacto del lead',
                        'Cliente',
                        'Respuesta del agente',
                        'Respuesta del encargado',
                        'Total',
                        'Encargado',
                    ],

                    legend: [
                        { light: 'green',  label: 'Hasta 4 h' },
                        { light: 'yellow', label: '4 h – 24 h' },
                        { light: 'red',    label: 'Más de 24 h' },
                        { light: 'gray',   label: 'Sin dato' },
                    ],

                    /**
                     * El semáforo va como estilo inline en lugar de clases de Tailwind
                     * para que el card funcione sin recompilar los assets.
                     */
                    lights: {
                        green:  { color: '#0f9d6e', background: 'rgba(15, 157, 110, .12)' },
                        yellow: { color: '#d97706', background: 'rgba(217, 119, 6, .12)' },
                        red:    { color: '#dc2626', background: 'rgba(220, 38, 38, .12)' },
                        gray:   { color: '#8b94a3', background: 'rgba(139, 148, 163, .14)' },
                    },
                }
            },

            computed: {
                /**
                 * Ventana de hasta 5 páginas alrededor de la actual, para no dibujar
                 * 36 botones cuando el rango de fechas es amplio.
                 */
                pageNumbers() {
                    const last = this.meta.last_page;
                    const current = this.meta.current_page;
                    const start = Math.max(1, Math.min(current - 2, last - 4));
                    const end = Math.min(last, start + 4);
                    const pages = [];

                    for (let page = start; page <= end; page++) {
                        pages.push(page);
                    }

                    return pages;
                },
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', (filters) => {
                    this.filters = filters;

                    // Un cambio de ciudad o de fechas cambia el universo de leads,
                    // así que la página actual deja de tener sentido.
                    this.getStats(filters, 1);
                });
            },

            methods: {
                chipStyle(light) {
                    const entry = this.lights[light] || this.lights.gray;

                    return { color: entry.color, backgroundColor: entry.background };
                },

                dotStyle(light) {
                    return { backgroundColor: (this.lights[light] || this.lights.gray).color };
                },

                changePage(page) {
                    if (page < 1 || page > this.meta.last_page || page === this.meta.current_page) {
                        return;
                    }

                    this.getStats(this.filters, page);
                },

                changeEncargado() {
                    this.getStats(this.filters, 1);
                },

                getStats(filters, page = 1) {
                    this.isLoading = true;

                    const params = Object.assign({}, filters, {
                        type: 'tiempo-por-contacto',
                        page: page,
                        encargado_id: this.encargadoId,
                    });

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params })
                        .then((response) => {
                            const statistics = response.data.statistics || {};

                            this.rows = statistics.rows || [];
                            this.encargados = statistics.encargados || [];
                            this.meta = statistics.meta || this.meta;
                            this.isLoading = false;
                        })
                        .catch(() => {
                            this.rows = [];
                            this.meta = { total: 0, per_page: 15, current_page: 1, last_page: 1 };
                            this.isLoading = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
