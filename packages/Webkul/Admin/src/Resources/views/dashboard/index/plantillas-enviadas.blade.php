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

                {{-- A quién se envió cada una (las últimas 100 del período). --}}
                <div v-if="report.envios && report.envios.length" class="grid gap-1.5">
                    <p class="text-sm font-semibold dark:text-gray-300">
                        Enviadas a
                        <span v-if="report.envios.length < report.total" class="font-normal text-gray-500 dark:text-gray-400">
                            (últimas @{{ report.envios.length }})
                        </span>
                    </p>

                    {{-- tabindex: la zona tiene scroll propio y tiene que poder desplazarse con el teclado. --}}
                    <div
                        class="max-h-80 overflow-auto rounded border border-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:border-gray-800"
                        tabindex="0"
                        role="region"
                        aria-label="Plantillas enviadas por cliente"
                    >
                        <table class="w-full text-left text-xs">
                            <caption class="sr-only">Plantillas de WhatsApp enviadas: fecha, cliente, plantilla y estado</caption>
                            <thead class="sticky top-0 bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <tr>
                                    <th scope="col" class="px-2 py-1.5 font-semibold">Fecha</th>
                                    <th scope="col" class="px-2 py-1.5 font-semibold">Cliente</th>
                                    <th scope="col" class="px-2 py-1.5 font-semibold">Plantilla</th>
                                    <th scope="col" class="px-2 py-1.5 font-semibold">Estado</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800 dark:text-gray-300">
                                <tr v-for="(envio, i) in report.envios" :key="i">
                                    <td class="whitespace-nowrap px-2 py-1.5">@{{ fecha(envio.fecha) }}</td>
                                    <td class="px-2 py-1.5">
                                        <span class="block [overflow-wrap:anywhere]">@{{ envio.cliente }}</span>
                                        <span class="block text-gray-500 dark:text-gray-400">@{{ envio.telefono }}</span>
                                    </td>
                                    <td class="px-2 py-1.5 [overflow-wrap:anywhere]">@{{ envio.plantilla }}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5">@{{ estados[envio.estado] || envio.estado }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-plantillas-enviadas', {
            template: '#v-dashboard-plantillas-enviadas-template',

            data() {
                return {
                    report: { total: 0, por_motivo: [], envios: [] },
                    isLoading: true,
                    estados: { sent: 'Enviada', delivered: 'Entregada', read: 'Leída' },
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
                fecha(valor) {
                    const d = new Date(String(valor).replace(' ', 'T'));

                    return Number.isNaN(d.getTime())
                        ? valor
                        : d.toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
                },

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
