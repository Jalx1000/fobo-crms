{!! view_render_event('admin.dashboard.index.tiempo_por_etapa.before') !!}

<v-dashboard-tiempo-por-etapa>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-tiempo-por-etapa>

{!! view_render_event('admin.dashboard.index.tiempo_por_etapa.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-tiempo-por-etapa-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <!-- Encabezado: título + filtro por encargado -->
                <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold dark:text-gray-300">Tiempo por etapa - detalle por lead</p>

                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Cuánto demoró el asesor en mover el lead en cada etapa - @{{ meta.total }} leads
                        </p>
                    </div>

                    <!-- Un encargado solo se ve a sí mismo: el selector de un solo nombre no filtra nada. -->
                    <select
                        v-if="encargados.length > 1"
                        class="flex min-h-[39px] w-[200px] cursor-pointer rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="encargadoId"
                        @change="changeEncargado"
                        aria-label="Filtrar por encargado"
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

                <!-- La tabla se recarga por axios; sin esto el cambio es mudo para un lector de pantalla. -->
                <p class="sr-only" aria-live="polite">
                    @{{ meta.total }} leads, página @{{ meta.current_page }} de @{{ meta.last_page }}
                </p>

                <!-- Tabla de detalle -->
                <div class="w-full max-w-full overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-800">
                                <th
                                    scope="col"
                                    class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400"
                                    :style="stickyHeaderStyle(0, 140)"
                                >
                                    Contacto del lead
                                </th>

                                <th
                                    scope="col"
                                    class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400"
                                    :style="stickyHeaderStyle(140, 150)"
                                >
                                    Cliente
                                </th>

                                <!-- Una columna por etapa no terminal -->
                                <th
                                    scope="col"
                                    class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400"
                                    style="letter-spacing: .05em"
                                    v-for="column in columns"
                                    :key="column.code"
                                    :title="column.terminal
                                        ? 'Etapa final: antigüedad desde que el lead entró'
                                        : `Verde hasta ${column.green}, amarillo hasta ${column.red}`"
                                >
                                    @{{ column.name }}
                                </th>

                                <th
                                    scope="col"
                                    class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400"
                                    style="letter-spacing: .05em"
                                >
                                    Total
                                </th>

                                <th
                                    scope="col"
                                    class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400"
                                    style="letter-spacing: .05em"
                                >
                                    Etapa actual
                                </th>

                                <th
                                    scope="col"
                                    class="whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400"
                                    style="letter-spacing: .05em"
                                >
                                    Encargado
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-if="! rows.length">
                                <td
                                    class="px-4 py-4 text-center text-sm text-gray-500 dark:text-gray-400"
                                    :colspan="columns.length + 5"
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
                                <td
                                    class="whitespace-nowrap px-4 py-2.5"
                                    :style="stickyStyle(0, 140)"
                                >
                                    <span class="block text-sm text-gray-600 dark:text-gray-300">@{{ row.contact_at }}</span>
                                    <span class="text-xs text-gray-400 dark:text-gray-400">#@{{ row.lead_id }}</span>
                                </td>

                                <!-- Nombre del person: es lo que identifica la fila -->
                                <th
                                    scope="row"
                                    class="px-4 py-2.5 text-left font-normal"
                                    :style="stickyStyle(140, 150)"
                                >
                                    <a
                                        class="block max-w-full text-sm font-semibold leading-snug text-gray-700 transition-all hover:text-brandColor dark:text-gray-300"
                                        :style="clampStyle"
                                        :href="leadUrl(row.lead_id)"
                                        :title="`Abrir el lead de ${row.person_name}`"
                                    >
                                        @{{ row.person_name }}
                                    </a>
                                </th>

                                <!-- Permanencia en cada etapa -->
                                <td
                                    class="whitespace-nowrap px-4 py-2.5"
                                    v-for="column in columns"
                                    :key="column.code"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :style="chipStyle(row.stages[column.code].light)"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :style="dotStyle(row.stages[column.code].light)" aria-hidden="true"></span>
                                        @{{ row.stages[column.code].human || 'No pasó' }}
                                    </span>
                                </td>

                                <!-- Total: suma de las columnas de etapa -->
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                        @{{ row.total_human || 'n/d' }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">
                                    @{{ row.current_stage }}
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
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <!--
                            El umbral depende de la etapa: 4 h es exigible para contestar
                            un lead pero absurdo para entregar un pedido.
                        -->
                        <span
                            class="flex items-center gap-1.5"
                            v-for="column in timedColumns"
                            :key="column.code"
                        >
                            <span class="font-semibold text-gray-600 dark:text-gray-300">@{{ column.name }}:</span>

                            <span class="flex items-center gap-1">
                                <span class="h-2 w-2 rounded-full" :style="dotStyle('green')" aria-hidden="true"></span>
                                ≤ @{{ column.green }}
                            </span>

                            <span class="flex items-center gap-1">
                                <span class="h-2 w-2 rounded-full" :style="dotStyle('yellow')" aria-hidden="true"></span>
                                ≤ @{{ column.red }}
                            </span>

                            <span class="flex items-center gap-1">
                                <span class="h-2 w-2 rounded-full" :style="dotStyle('red')" aria-hidden="true"></span>
                                más
                            </span>
                        </span>

                        <!-- Las etapas finales no se salen: su número es antigüedad, no demora. -->
                        <span class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full" :style="dotStyle('neutral')" aria-hidden="true"></span>
                            Etapa final (antigüedad)
                        </span>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="mr-1 text-xs text-gray-500 dark:text-gray-400">
                            @{{ meta.per_page }} por página - página @{{ meta.current_page }} de @{{ meta.last_page }}
                        </span>

                        <button
                            type="button"
                            class="flex w-9 min-h-[36px] max-sm:min-h-[44px] max-sm:min-w-[44px] cursor-pointer items-center justify-center rounded-md border px-2 py-1 text-sm disabled:cursor-not-allowed text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300 dark:hover:border-gray-400"
                            :style="{ opacity: meta.current_page <= 1 ? .5 : 1 }"
                            :disabled="meta.current_page <= 1"
                            @click="changePage(meta.current_page - 1)"
                            aria-label="Página anterior"
                        >
                            &lsaquo;
                        </button>

                        <button
                            type="button"
                            class="flex w-9 min-h-[36px] max-sm:min-h-[44px] max-sm:min-w-[44px] cursor-pointer items-center justify-center rounded-md border px-2 py-1 text-sm disabled:cursor-not-allowed transition-all hover:border-gray-400 dark:border-gray-800 dark:hover:border-gray-400"
                            :class="page === meta.current_page
                                ? 'border-transparent bg-brandColor font-semibold text-white'
                                : 'text-gray-600 dark:text-gray-300'"
                            v-for="page in pageNumbers"
                            :key="page"
                            @click="changePage(page)"
                            :aria-label="`Página ${page}`"
                            :aria-current="page === meta.current_page ? 'page' : null"
                        >
                            @{{ page }}
                        </button>

                        <button
                            type="button"
                            class="flex w-9 min-h-[36px] max-sm:min-h-[44px] max-sm:min-w-[44px] cursor-pointer items-center justify-center rounded-md border px-2 py-1 text-sm disabled:cursor-not-allowed text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:text-gray-300 dark:hover:border-gray-400"
                            :style="{ opacity: meta.current_page >= meta.last_page ? .5 : 1 }"
                            :disabled="meta.current_page >= meta.last_page"
                            @click="changePage(meta.current_page + 1)"
                            aria-label="Página siguiente"
                        >
                            &rsaquo;
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-tiempo-por-etapa', {
            template: '#v-dashboard-tiempo-por-etapa-template',

            data() {
                return {
                    columns: [],
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

                    isDark: false,

                    /**
                     * El semáforo va como estilo inline en lugar de clases de Tailwind
                     * para que el card funcione sin recompilar los assets. El costo es
                     * que `dark:` no aplica, así que la paleta se elige en JS: los tonos
                     * saturados que contrastan sobre blanco caen por debajo de 4.5:1
                     * sobre el gris-900 del modo oscuro (el rojo #dc2626 llega a 3.3:1).
                     *
                     * `neutral` marca las etapas terminales, donde el número es
                     * antigüedad y no demora: se distingue del gris de "no pasó" sin
                     * sugerir atraso.
                     */
                    palettes: {
                        light: {
                            green:   { color: '#0f7a56', background: 'rgba(15, 122, 86, .12)' },
                            yellow:  { color: '#b45309', background: 'rgba(180, 83, 9, .12)' },
                            red:     { color: '#b91c1c', background: 'rgba(185, 28, 28, .12)' },
                            gray:    { color: '#64748b', background: 'rgba(100, 116, 139, .14)' },
                            neutral: { color: '#475569', background: 'rgba(71, 85, 105, .10)' },
                        },

                        dark: {
                            green:   { color: '#34d399', background: 'rgba(52, 211, 153, .16)' },
                            yellow:  { color: '#fbbf24', background: 'rgba(251, 191, 36, .16)' },
                            red:     { color: '#f87171', background: 'rgba(248, 113, 113, .16)' },
                            gray:    { color: '#94a3b8', background: 'rgba(148, 163, 184, .16)' },
                            neutral: { color: '#cbd5e1', background: 'rgba(203, 213, 225, .12)' },
                        },
                    },
                }
            },

            computed: {
                lights() {
                    return this.isDark ? this.palettes.dark : this.palettes.light;
                },

                /**
                 * La columna es angosta, así que el nombre largo baja a una segunda
                 * línea en vez de estirarla. A partir de la tercera se corta con
                 * puntos suspensivos; el nombre completo queda en el `title` del enlace.
                 *
                 * Va inline en vez de `line-clamp-2` por lo mismo que el resto del card:
                 * no depender de recompilar los assets.
                 */
                clampStyle() {
                    return {
                        display: '-webkit-box',
                        WebkitLineClamp: 2,
                        WebkitBoxOrient: 'vertical',
                        overflow: 'hidden',
                        wordBreak: 'break-word',
                    };
                },

                /**
                 * Solo las etapas de las que se puede salir tienen umbral que explicar.
                 */
                timedColumns() {
                    return this.columns.filter((column) => ! column.terminal);
                },

                /**
                 * Ventana de hasta 5 páginas alrededor de la actual, para no dibujar
                 * 33 botones cuando el rango de fechas es amplio.
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
                this.syncTheme();

                /**
                 * El tema se alterna agregando/quitando `dark` en <html>, sin evento
                 * propio: se observa el atributo para repintar el semáforo al vuelo.
                 */
                this.themeObserver = new MutationObserver(this.syncTheme);

                this.themeObserver.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class'],
                });

                this.getStats({});

                this.$emitter.on('reporting-filter-updated', (filters) => {
                    this.filters = filters;

                    // Un cambio de ciudad o de fechas cambia el universo de leads,
                    // así que la página actual deja de tener sentido.
                    this.getStats(filters, 1);
                });
            },

            beforeUnmount() {
                this.themeObserver?.disconnect();
            },

            methods: {
                syncTheme() {
                    this.isDark = document.documentElement.classList.contains('dark');
                },

                chipStyle(light) {
                    const entry = this.lights[light] || this.lights.gray;

                    return { color: entry.color, backgroundColor: entry.background };
                },

                dotStyle(light) {
                    return { backgroundColor: (this.lights[light] || this.lights.gray).color };
                },

                /**
                 * Las dos primeras columnas van congeladas: con la tabla desplazada a la
                 * derecha, sin ellas no se sabe de qué lead es cada tiempo.
                 *
                 * El fondo tiene que ser opaco y del color del card, o las celdas que
                 * pasan por debajo se leen encima. Va como estilo inline (y no con
                 * `sticky left-0` de Tailwind) por lo mismo que el semáforo: así el card
                 * no depende de recompilar los assets. El costo es que `dark:` no aplica
                 * y el color se elige acá con `isDark`.
                 */
                stickyStyle(left, width) {
                    return {
                        position: 'sticky',
                        left: `${left}px`,
                        width: `${width}px`,
                        minWidth: `${width}px`,
                        zIndex: 2,
                        backgroundColor: this.isDark ? '#111827' : '#ffffff',

                        // Sólo la última columna congelada marca el borde del bloque.
                        boxShadow: left
                            ? `1px 0 0 0 ${this.isDark ? '#1f2937' : '#e5e7eb'}`
                            : 'none',
                    };
                },

                stickyHeaderStyle(left, width) {
                    return {
                        ...this.stickyStyle(left, width),
                        zIndex: 3,
                        letterSpacing: '.05em',
                    };
                },

                /**
                 * El nombre lleva al lead: desde el tablero se ve que un lead está
                 * demorado y el siguiente paso siempre es abrirlo.
                 */
                leadUrl(leadId) {
                    return '{{ route('admin.leads.view', ':id') }}'.replace(':id', leadId);
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
                        type: 'tiempo-por-etapa',
                        page: page,
                        encargado_id: this.encargadoId,
                    });

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params })
                        .then((response) => {
                            const statistics = response.data.statistics || {};

                            this.columns = statistics.columns || [];
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
