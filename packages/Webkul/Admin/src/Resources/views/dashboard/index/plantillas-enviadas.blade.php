{!! view_render_event('admin.dashboard.index.plantillas_enviadas.before') !!}

<v-dashboard-plantillas-enviadas>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-plantillas-enviadas>

{!! view_render_event('admin.dashboard.index.plantillas_enviadas.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-plantillas-enviadas-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col justify-between gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">Plantillas de WhatsApp enviadas</p>
                    {{--
                        Dice que se pagan. Es el único gasto del CRM que crece solo —la
                        bienvenida sale con cada alta, el recordatorio con cada ciclo— y
                        sin decirlo el número parece una estadística más.
                    --}}
                    <p class="text-xs text-gray-600 dark:text-gray-400">
                        Cada una se le paga a Meta. Sólo cuenta las que salieron.
                    </p>
                </div>

                <p class="text-3xl font-bold dark:text-white">@{{ report.total }}</p>

                <div v-if="report.por_motivo && report.por_motivo.length" class="grid gap-2">
                    <div
                        v-for="fila in report.por_motivo"
                        :key="fila.plantilla"
                        class="flex items-baseline justify-between gap-2 border-b border-dashed border-gray-200 pb-1 last:border-0 dark:border-gray-800"
                    >
                        <span class="min-w-0 truncate text-sm dark:text-gray-300">@{{ fila.plantilla }}</span>
                        <span class="shrink-0 text-sm font-bold dark:text-white">@{{ fila.total }}</span>
                    </div>
                </div>

                <p v-else class="text-sm text-gray-600 dark:text-gray-400">
                    No se envió ninguna plantilla en este período.
                </p>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-plantillas-enviadas', {
            template: '#v-dashboard-plantillas-enviadas-template',

            data() {
                return {
                    report: { total: 0, por_motivo: [] },
                    isLoading: true,
                }
            },

            mounted() {
                this.get();

                /*
                 * El tablero avisa cuando cambia el filtro (fechas, ciudad). Sin escuchar esto
                 * la tarjeta se queda con el período con el que cargó, y muestra un
                 * número que no corresponde al resto de la pantalla.
                 */
                this.$emitter.on('reporting-filter-updated', this.get);
            },

            methods: {
                get() {
                    this.isLoading = true;

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: { type: 'plantillas-enviadas' },
                        })
                        .then(response => {
                            this.report = response.data.statistics;
                            this.isLoading = false;
                        })
                        .catch(error => {
                            this.isLoading = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
