{!! view_render_event('admin.dashboard.index.metas.before') !!}

<v-dashboard-metas>
    <x-admin::shimmer.dashboard.index.over-all />
</v-dashboard-metas>

{!! view_render_event('admin.dashboard.index.metas.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-metas-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.over-all />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-2 p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        Metas de @{{ nombrePeriodo }}
                    </p>

                    {{--
                        Los días restantes son la mitad del valor de la métrica:
                        «7 de 10» asusta el día 28 y tranquiliza el día 5. Un
                        porcentaje sin tiempo restante no se puede interpretar.
                    --}}
                    <span v-if="report.dias_restantes > 0" class="text-xs text-gray-500 dark:text-gray-400">
                        quedan <b>@{{ report.dias_restantes }}</b> días
                    </span>
                    <span v-else class="text-xs text-gray-400 dark:text-gray-500">período cerrado</span>
                </div>

                <div v-if="report.sin_metas" class="flex flex-col items-center gap-2 px-4 py-8 text-center">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No hay metas cargadas para este mes.
                    </p>
                    @if (bouncer()->hasPermission('settings.lead.metas'))
                        <a href="{{ route('admin.settings.metas.index') }}"
                           class="text-sm font-semibold text-brandColor hover:underline">
                            Cargar metas
                        </a>
                    @endif
                </div>

                <div v-else class="flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
                    <div v-for="m in report.metas" :key="m.id" class="flex flex-col gap-1.5 px-4 py-3">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="truncate">
                                <b :class="m.es_equipo ? 'text-gray-800 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300'">
                                    @{{ m.quien }}
                                </b>
                                <span class="text-gray-500 dark:text-gray-400">· @{{ m.etiqueta }}</span>
                            </span>

                            <span class="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">
                                <b :class="colorTexto(m)">@{{ formato(m.logrado, m.moneda) }}</b>
                                <span class="text-gray-400"> / @{{ formato(m.objetivo, m.moneda) }}</span>
                            </span>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                {{--
                                    El ancho se corta en 100 % para que la barra no
                                    se salga del contenedor, pero el número de al
                                    lado muestra el real: superar la meta en 140 %
                                    es información que no hay que esconder.
                                --}}
                                <div class="h-full rounded-full transition-[width] duration-500"
                                     :class="colorBarra(m)"
                                     :style="{ width: m.porcentaje + '%' }"
                                     role="progressbar"
                                     :aria-valuenow="m.porcentaje_real"
                                     aria-valuemin="0"
                                     aria-valuemax="100"
                                     :aria-label="m.quien + ' ' + m.etiqueta"></div>
                            </div>

                            <span class="w-12 shrink-0 text-right text-xs tabular-nums" :class="colorTexto(m)">
                                @{{ m.porcentaje_real }}%
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-metas', {
            template: '#v-dashboard-metas-template',

            data() {
                return {
                    isLoading: true,
                    report: { sin_metas: true, metas: [], dias_restantes: 0, periodo: '' },
                };
            },

            computed: {
                nombrePeriodo() {
                    if (! this.report.periodo) return '';

                    const [a, m] = this.report.periodo.split('-');

                    return new Date(a, m - 1, 1)
                        .toLocaleDateString('es', { month: 'long', year: 'numeric' });
                },
            },

            mounted() {
                this.getStats({});
                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStats(filters) {
                    this.isLoading = true;

                    const params = Object.assign({}, filters, { type: 'metas' });

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params })
                        .then(response => {
                            this.report = response.data?.statistics ?? this.report;
                            this.isLoading = false;
                        })
                        .catch(() => { this.isLoading = false; });
                },

                /*
                 * El color sale del avance contra el tiempo transcurrido, no del
                 * avance solo: 50 % el día 10 va bien y el día 28 va mal. Sin
                 * esto, toda meta sería roja a principio de mes.
                 */
                aTiempo(m) {
                    if (m.porcentaje_real >= 100) return true;
                    if (! this.report.dias_restantes) return false;

                    const diasDelMes = new Date(
                        this.report.periodo.split('-')[0],
                        this.report.periodo.split('-')[1],
                        0
                    ).getDate();

                    const transcurrido = (diasDelMes - this.report.dias_restantes) / diasDelMes * 100;

                    // 15 puntos de tolerancia: una meta no avanza lineal, avanza
                    // a saltos cuando se firma.
                    return m.porcentaje_real >= transcurrido - 15;
                },

                colorBarra(m) {
                    if (m.porcentaje_real >= 100) return 'bg-emerald-500';

                    return this.aTiempo(m) ? 'bg-brandColor' : 'bg-amber-500';
                },

                colorTexto(m) {
                    if (m.porcentaje_real >= 100) return 'text-emerald-600 dark:text-emerald-400';

                    return this.aTiempo(m)
                        ? 'text-gray-700 dark:text-gray-200'
                        : 'text-amber-700 dark:text-amber-400';
                },

                formato(valor, moneda) {
                    const n = Number(valor);
                    const texto = n >= 1000
                        ? n.toLocaleString('es', { maximumFractionDigits: 0 })
                        : String(n);

                    return moneda ? moneda + ' ' + texto : texto;
                },
            },
        });
    </script>
@endPushOnce
