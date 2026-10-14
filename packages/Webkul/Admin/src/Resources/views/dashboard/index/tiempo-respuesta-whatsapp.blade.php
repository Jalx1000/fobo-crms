{!! view_render_event('admin.dashboard.index.tiempo_respuesta_whatsapp.before') !!}

<v-dashboard-tiempo-respuesta-whatsapp>
    <x-admin::shimmer.dashboard.index.total-leads />
</v-dashboard-tiempo-respuesta-whatsapp>

{!! view_render_event('admin.dashboard.index.tiempo_respuesta_whatsapp.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-tiempo-respuesta-whatsapp-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.total-leads />
        </template>

        <template v-else>
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col gap-1">
                    <p class="text-base font-semibold dark:text-gray-300">Tiempo de respuesta en WhatsApp</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400">
                        Desde el último mensaje del cliente hasta la respuesta
                    </p>
                </div>

                {{--
                    Mediana y p90, no promedio: con el agente en segundos y los
                    asesores en horas, el promedio lo domina la cola y no
                    describe a nadie.
                --}}
                <div class="grid grid-cols-2 gap-3">
                    <div
                        v-for="fila in filas"
                        :key="fila.clave"
                        class="rounded-md border border-gray-200 p-3 dark:border-gray-700"
                    >
                        <div class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full" :class="fila.color"></span>
                            <p class="text-sm font-semibold dark:text-gray-200">@{{ fila.titulo }}</p>
                        </div>

                        <p class="mt-2 text-2xl font-bold dark:text-white">@{{ fila.mediana }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">mediana</p>

                        <div class="mt-2 flex justify-between text-xs text-gray-600 dark:text-gray-400">
                            <span>9 de cada 10: <strong>@{{ fila.p90 }}</strong></span>
                            <span>@{{ fila.respuestas }} resp.</span>
                        </div>
                    </div>
                </div>

                {{--
                    Quién está esperando AHORA. Va arriba de todo porque es lo
                    único de la tarjeta sobre lo que se puede actuar hoy, y
                    porque era el punto ciego: el reporte emparejaba cada
                    mensaje con su respuesta, así que una conversación sin
                    contestar no existía para la métrica. Se detectó con un
                    cliente esperando 19 h mientras la mediana decía 0,2 min.
                --}}
                <div
                    v-if="esperando.casos"
                    class="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950/40"
                >
                    <div class="flex items-baseline justify-between">
                        <p class="text-sm font-semibold text-red-800 dark:text-red-300">
                            @{{ esperando.casos }} esperando respuesta
                        </p>
                        <p class="text-xs text-red-700 dark:text-red-400">
                            el que más lleva: <strong>@{{ enTexto(esperando.maximo) }}</strong>
                        </p>
                    </div>

                    <ul class="mt-2 space-y-0.5">
                        <li
                            v-for="caso in esperando.detalle"
                            :key="caso.conversacion"
                            class="flex justify-between text-xs text-red-800 dark:text-red-300"
                        >
                            <span class="truncate pr-2">@{{ caso.nombre }}</span>
                            <span class="whitespace-nowrap font-medium">@{{ enTexto(caso.minutos) }}</span>
                        </li>
                    </ul>

                    <p v-if="esperando.fuera_de_ventana" class="mt-2 text-[11px] text-red-700 dark:text-red-400">
                        Además @{{ esperando.fuera_de_ventana }} sin responder con la ventana de 24 h vencida:
                        a ésos sólo se les puede escribir con plantilla.
                    </p>
                </div>

                <div v-if="porAsesor.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-1.5 font-medium">Asesor</th>
                                <th class="py-1.5 text-right font-medium">Mediana</th>
                                <th class="py-1.5 text-right font-medium">9 de 10</th>
                                <th class="py-1.5 text-right font-medium">Resp.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="asesor in porAsesor"
                                :key="asesor.nombre"
                                class="border-b border-gray-100 last:border-0 dark:border-gray-800"
                            >
                                <td class="py-1.5 dark:text-gray-300">@{{ asesor.nombre }}</td>
                                <td class="py-1.5 text-right dark:text-gray-300">@{{ enTexto(asesor.mediana) }}</td>
                                <td class="py-1.5 text-right dark:text-gray-300">@{{ enTexto(asesor.p90) }}</td>
                                <td class="py-1.5 text-right dark:text-gray-300">@{{ asesor.respuestas }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="text-xs text-gray-500 dark:text-gray-400">
                    El desglose por asesor empieza a llenarse con los mensajes nuevos:
                    hasta ahora el CRM no guardaba quién respondía.
                </p>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-tiempo-respuesta-whatsapp', {
            template: '#v-dashboard-tiempo-respuesta-whatsapp-template',

            data() {
                return {
                    isLoading: true,
                    datos: null,
                };
            },

            computed: {
                filas() {
                    if (! this.datos) return [];

                    return [
                        {
                            clave: 'agente',
                            titulo: 'Agente IA',
                            color: 'bg-emerald-500',
                            mediana: this.enTexto(this.datos.agente.mediana),
                            p90: this.enTexto(this.datos.agente.p90),
                            respuestas: this.datos.agente.respuestas,
                        },
                        {
                            clave: 'asesor',
                            titulo: 'Asesor',
                            color: 'bg-amber-500',
                            mediana: this.enTexto(this.datos.asesor.mediana),
                            p90: this.enTexto(this.datos.asesor.p90),
                            respuestas: this.datos.asesor.respuestas,
                        },
                    ];
                },

                porAsesor() {
                    return this.datos?.por_asesor ?? [];
                },

                esperando() {
                    return this.datos?.esperando ?? { casos: 0, maximo: 0, mediana: 0, fuera_de_ventana: 0, detalle: [] };
                },
            },

            mounted() {
                this.get({});

                /*
                 * El filtro global del tablero manda los valores por este
                 * evento. Antes acá se leía this.$route.query, pero el tablero
                 * no monta vue-router: $route venía undefined y la tarjeta
                 * moría con "Cannot read properties of undefined".
                 */
                this.$emitter.on('reporting-filter-updated', this.get);
            },

            methods: {
                /**
                 * Minutos a algo legible.
                 *
                 * El rango va de 6 segundos a 18 horas, así que una sola unidad
                 * deja la mitad de los valores ilegibles: "0.1 min" no se lee
                 * como instantáneo y "1085.8 min" no se lee como casi un día.
                 */
                enTexto(minutos) {
                    if (minutos === null || minutos === undefined) return '—';
                    if (minutos < 1) return Math.round(minutos * 60) + ' s';
                    if (minutos < 60) return Math.round(minutos) + ' min';
                    if (minutos < 1440) return (minutos / 60).toFixed(1) + ' h';

                    return (minutos / 1440).toFixed(1) + ' d';
                },

                get(filtros) {
                    this.isLoading = true;

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", {
                            params: Object.assign({}, filtros || {}, { type: 'tiempo-respuesta-whatsapp' }),
                        })
                        .then(res => { this.datos = res.data.statistics; })
                        .catch(() => {})
                        .finally(() => { this.isLoading = false; });
                },
            },
        });
    </script>
@endPushOnce
