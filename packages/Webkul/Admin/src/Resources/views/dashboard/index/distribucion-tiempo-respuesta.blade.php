{!! view_render_event('admin.dashboard.index.distribucion_tiempo_respuesta.before') !!}

<v-dashboard-distribucion-tiempo-respuesta>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-distribucion-tiempo-respuesta>

{!! view_render_event('admin.dashboard.index.distribucion_tiempo_respuesta.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-distribucion-tiempo-respuesta-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <!-- flex y no grid: la columna implícita del grid crecía con el canvas y lo sacaba de la card. -->
            <div class="flex min-w-0 flex-col gap-4 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <!-- Encabezado. El ejecutivo se elige con el filtro «Ejecutivo comercial» del tablero. -->
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <p class="text-base font-semibold dark:text-gray-300">Distribución del tiempo de respuesta</p>

                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Tiempo entre que el pedido entra a una etapa y pasa a la siguiente, en horas hábiles.
                            <span
                                class="text-gray-400"
                                :title="nota"
                                aria-hidden="true"
                            >ⓘ</span>
                        </p>
                    </div>

                </div>

                <div class="min-w-0" :class="{ 'opacity-50 transition-all': isFetching }">
                    <!-- Sin movimientos: nunca mostrar 0 h -->
                    <p
                        v-if="! stats.total"
                        class="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                    >
                        Sin movimientos en el periodo.
                    </p>

                    <template v-else>
                        <!-- KPIs -->
                        <div class="grid grid-cols-4 gap-3 max-md:grid-cols-2">
                            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Mejor</p>
                                <p class="text-xl font-bold text-green-600 dark:text-green-300">@{{ hours(stats.min) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">@{{ shortEquivalent(stats.min) }}</p>
                            </div>

                            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Promedio</p>
                                <p class="text-xl font-bold text-blue-600 dark:text-blue-300">@{{ hours(stats.avg) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">@{{ days(stats.avg) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">mediana @{{ hours(stats.median) }}</p>
                            </div>

                            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Peor</p>
                                <p class="text-xl font-bold text-red-600 dark:text-red-400">@{{ hours(stats.max) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">@{{ days(stats.max) }}</p>
                            </div>

                            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Respondidos &lt; 1 día hábil</p>
                                <p class="text-xl font-bold text-gray-800 dark:text-gray-200">@{{ stats.pct_under_1_day }} %</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    @{{ number(stats.count_under_1_day) }} de @{{ number(stats.total) }} movimientos
                                </p>
                            </div>
                        </div>

                        <!-- Gráfico de área -->
                        <div class="relative mt-4 w-full min-w-0">
                            <canvas
                                ref="canvas"
                                class="w-full"
                                style="aspect-ratio: 2.6 / 1"
                                role="img"
                                :aria-label="ariaSummary"
                            ></canvas>
                        </div>

                        <p class="mt-1 text-center text-xs text-gray-500 dark:text-gray-400">días hábiles de respuesta (1 día = @{{ hours(stats.segundos_por_dia) }})</p>

                        <!-- Leyenda de las líneas -->
                        <div class="mt-3 flex flex-wrap justify-center gap-x-5 text-xs dark:text-gray-300">
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block w-5" style="border-top: 2px solid #16a34a"></span> Mejor
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block w-5" style="border-top: 2px dashed #9ca3af"></span> Mediana
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block w-5" style="border-top: 2px dashed #2563eb"></span> Promedio
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block w-5" style="border-top: 2px dotted #dc2626"></span> Peor
                            </span>
                        </div>

                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">ⓘ @{{ nota }}</p>
                    </template>
                </div>

                <!-- Se recarga por axios; sin esto el cambio es mudo para un lector de pantalla. -->
                <p class="sr-only" aria-live="polite">@{{ ariaSummary }}</p>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-distribucion-tiempo-respuesta', {
            template: '#v-dashboard-distribucion-tiempo-respuesta-template',

            data() {
                return {
                    stats: {},
                    filters: {},
                    isLoading: true,
                    isFetching: false,
                    nota: 'Horas hábiles: lunes a viernes de 08:00 a 19:00. Cada movimiento pesa igual; '
                        + 'no cuentan los pasos de menos de 1 minuto ni los movimientos en bloque '
                        + '(un usuario que mueve 5 o más pedidos en el mismo minuto). Puede diferir de '
                        + '"Tiempo en responder", que mide desde la creación hasta ahora o el cierre.',
                };
            },

            computed: {
                ariaSummary() {
                    if (! this.stats.total) {
                        return 'Sin movimientos en el periodo.';
                    }

                    return `Promedio ${this.hours(this.stats.avg)}, mediana ${this.hours(this.stats.median)}, `
                        + `peor ${this.hours(this.stats.max)}, ${this.stats.pct_under_1_day} % en menos de un día hábil.`;
                },
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', (filters) => {
                    this.filters = filters;

                    this.getStats(filters);
                });
            },

            beforeUnmount() {
                this.chart?.destroy();
            },

            methods: {
                getStats(filters) {
                    if (this.stats.total === undefined) {
                        this.isLoading = true;
                    } else {
                        this.isFetching = true;
                    }

                    const params = Object.assign({}, filters, { type: 'distribucion-tiempo-respuesta' });

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params })
                        .then((response) => {
                            const statistics = response.data.statistics;

                            this.stats = statistics;

                            this.isLoading = false;
                            this.isFetching = false;

                            this.$nextTick(() => this.drawChart());
                        })
                        .catch(() => {
                            this.isLoading = false;
                            this.isFetching = false;
                        });
                },

                hours(seconds) {
                    if (seconds === null || seconds === undefined) {
                        return '-';
                    }

                    const h = seconds / 3600;

                    return (h < 1 ? h.toFixed(2) : h.toFixed(1)) + ' h';
                },

                // Días hábiles (11 h), no de 24 h: es la unidad del histograma.
                days(seconds) {
                    return (seconds / this.stats.segundos_por_dia).toFixed(1) + ' días hábiles';
                },

                // Menos de un día se lee mejor en minutos u horas que en "0.0 días".
                shortEquivalent(seconds) {
                    if (seconds < 3600) {
                        return Math.round(seconds / 60) + ' min';
                    }

                    if (seconds < this.stats.segundos_por_dia) {
                        const h = Math.floor(seconds / 3600);
                        const m = Math.round((seconds % 3600) / 60);

                        return `${h} h ${String(m).padStart(2, '0')} min`;
                    }

                    return this.days(seconds);
                },

                number(n) {
                    return Number(n || 0).toLocaleString('es-BO');
                },

                isDark() {
                    return document.documentElement.classList.contains('dark');
                },

                drawChart() {
                    this.chart?.destroy();
                    this.chart = null;

                    const canvas = this.$refs.canvas;

                    if (! canvas || ! this.stats.total) {
                        return;
                    }

                    const buckets = this.stats.buckets;
                    const textColor = this.isDark() ? '#d1d5db' : '#4b5563';
                    const gridColor = this.isDark() ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
                    const labelBackground = this.isDark() ? '#111827' : '#ffffff';

                    const markers = [
                        { seconds: this.stats.min, color: '#16a34a', dash: [], label: 'Mejor' },
                        { seconds: this.stats.median, color: '#9ca3af', dash: [6, 4], label: 'Mediana' },
                        { seconds: this.stats.avg, color: '#2563eb', dash: [6, 4], label: 'Prom.' },
                        { seconds: this.stats.max, color: '#dc2626', dash: [2, 3], label: 'Peor' },
                    ];

                    const hours = this.hours;
                    const dia = this.stats.segundos_por_dia;

                    /*
                     * Líneas verticales con etiqueta. Chart.js de este proyecto no
                     * trae el plugin de anotaciones, así que se dibujan a mano.
                     * Cada tramo i del eje cubre [i, i+1) días hábiles y su punto está en el
                     * centro, así que un valor d cae en pixel(0) + (d - 0.5) * paso.
                     * Lo mayor a 13 días se dibuja en el tramo 13+.
                     */
                    const markerPlugin = {
                        id: 'responseTimeMarkers',

                        afterDatasetsDraw(chart) {
                            const { ctx, chartArea, scales } = chart;
                            const step = scales.x.getPixelForValue(1) - scales.x.getPixelForValue(0);
                            const first = scales.x.getPixelForValue(0);
                            const last = scales.x.getPixelForValue(13);

                            ctx.save();
                            ctx.font = '11px sans-serif';
                            ctx.textBaseline = 'top';

                            const xOf = (seconds) => {
                                const day = seconds / dia;

                                return day >= 13
                                    ? last
                                    : Math.min(last, Math.max(chartArea.left, first + (day - 0.5) * step));
                            };

                            // Primero todas las líneas y después las etiquetas, con
                            // fondo, para que ninguna línea tache una etiqueta.
                            markers.forEach((marker) => {
                                const x = xOf(marker.seconds);

                                ctx.strokeStyle = marker.color;
                                ctx.lineWidth = 2;
                                ctx.setLineDash(marker.dash);
                                ctx.beginPath();
                                ctx.moveTo(x, chartArea.top);
                                ctx.lineTo(x, chartArea.bottom);
                                ctx.stroke();
                            });

                            ctx.setLineDash([]);

                            markers.forEach((marker, i) => {
                                const x = xOf(marker.seconds);

                                // Escalonadas en altura para que no se tapen.
                                const text = `${marker.label} ${hours(marker.seconds)}`;
                                const width = ctx.measureText(text).width;
                                const y = chartArea.top + 2 + i * 14;

                                // Si se sale por la derecha, a la izquierda de su línea.
                                const tx = x + 4 + width > chartArea.right ? x - 4 - width : x + 4;

                                ctx.fillStyle = labelBackground;
                                ctx.fillRect(tx - 2, y - 1, width + 4, 13);
                                ctx.fillStyle = marker.color;
                                ctx.fillText(text, tx, y);
                            });

                            ctx.restore();
                        },
                    };

                    this.chart = new Chart(canvas, {
                        type: 'line',

                        data: {
                            labels: buckets.map((b) => b.label),

                            datasets: [{
                                data: buckets.map((b) => b.percent),
                                fill: true,
                                tension: 0.3,
                                borderColor: '#0d9488',
                                backgroundColor: 'rgba(20, 184, 166, 0.25)',
                                pointBackgroundColor: '#0d9488',
                                pointRadius: 3,
                                pointHoverRadius: 5,
                            }],
                        },

                        options: {
                            aspectRatio: 2.6,

                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },

                            plugins: {
                                legend: { display: false },

                                tooltip: {
                                    displayColors: false,
                                    callbacks: {
                                        title: () => '',
                                        label: (item) => {
                                            const b = buckets[item.dataIndex];
                                            const tramo = b.from_day === 13 ? 'Día hábil 13 o más' : `Día hábil ${b.from_day} a ${b.from_day + 1}`;

                                            return `${tramo} - ${b.percent}% de movimientos`;
                                        },
                                    },
                                },
                            },

                            scales: {
                                x: {
                                    ticks: { color: textColor },
                                    grid: { display: false },
                                },

                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: textColor,
                                        callback: (value) => value + ' %',
                                    },
                                    grid: { color: gridColor },
                                },
                            },
                        },

                        plugins: [markerPlugin],
                    });
                },
            },
        });
    </script>
@endPushOnce
