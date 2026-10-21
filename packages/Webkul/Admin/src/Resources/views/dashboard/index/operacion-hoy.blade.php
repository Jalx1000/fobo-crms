{!! view_render_event('admin.dashboard.index.operacion_hoy.before') !!}

<v-dashboard-operacion-hoy>
    <x-admin::shimmer.dashboard.index.over-all />
</v-dashboard-operacion-hoy>

{!! view_render_event('admin.dashboard.index.operacion_hoy.after') !!}

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-operacion-hoy-template">
        <template v-if="isLoading">
            <x-admin::shimmer.dashboard.index.over-all />
        </template>

        <template v-else>
            <div class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-2 p-4">
                    <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                        {{ ($personal ?? false) ? 'Lo que necesita mi atención ahora' : 'Qué necesita atención ahora' }}
                    </p>
                    {{--
                        Estas cifras ignoran el filtro de fechas del tablero, y
                        decirlo evita la pregunta obvia: «¿por qué no cambian
                        cuando cambio el mes?». Son estado presente, no período.
                    --}}
                    <span class="text-[11px] text-gray-400 dark:text-gray-500">
                        en este momento
                    </span>
                </div>

                <div class="grid gap-px bg-gray-200 dark:bg-gray-800 sm:grid-cols-2 lg:grid-cols-4">
                    <a v-for="t in tiles"
                       :key="t.etiqueta"
                       :href="t.url"
                       class="group flex flex-col gap-1 bg-white p-4 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor dark:bg-gray-900 dark:hover:bg-gray-800/60">
                        <span class="text-2xl font-bold tabular-nums"
                              :class="t.valor > 0 ? t.color : 'text-gray-300 dark:text-gray-600'">
                            @{{ t.valor }}
                        </span>
                        <span class="text-xs text-gray-600 group-hover:underline dark:text-gray-400">
                            @{{ t.etiqueta }}
                        </span>
                    </a>
                </div>

                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-gray-200 px-4 py-3 text-xs dark:border-gray-800">
                    <span class="text-gray-500 dark:text-gray-400">
                        Cartera: <b class="text-gray-700 dark:text-gray-200">@{{ cartera.total }}</b> inmuebles
                    </span>

                    <span v-for="(n, estado) in cartera.por_estado" :key="estado" class="text-gray-500 dark:text-gray-400">
                        @{{ estado }}: <b class="text-gray-700 dark:text-gray-200">@{{ n }}</b>
                    </span>

                    <span v-if="cartera.sin_fotos" class="font-medium text-amber-700 dark:text-amber-400">
                        @{{ cartera.sin_fotos }} sin fotos
                    </span>

                    <span v-if="porAsesor.length" class="text-gray-500 dark:text-gray-400">
                        Atendiendo:
                        <b v-for="(a, i) in porAsesor" :key="a.asesor" class="text-gray-700 dark:text-gray-200">
                            @{{ a.asesor }} (@{{ a.total }})<span v-if="i < porAsesor.length - 1">, </span>
                        </b>
                    </span>
                </div>
            </div>
        </template>
    </script>

    <script type="module">
        app.component('v-dashboard-operacion-hoy', {
            template: '#v-dashboard-operacion-hoy-template',

            data() {
                return {
                    isLoading: true,
                    op: {},
                    cartera: { total: 0, por_estado: {}, sin_fotos: 0 },
                };
            },

            computed: {
                /*
                 * El orden es el de urgencia, no el alfabético: lo primero es lo
                 * que deja a un cliente esperando.
                 */
                tiles() {
                    return [
                        {
                            valor: this.op.sin_responder ?? 0,
                            etiqueta: 'Conversaciones sin responder',
                            color: 'text-red-600 dark:text-red-400',
                            url: "{{ route('admin.whatsapp.index') }}",
                        },
                        {
                            valor: this.op.ventanas_por_cerrar ?? 0,
                            etiqueta: 'Ventanas de 24h por cerrarse',
                            color: 'text-amber-600 dark:text-amber-400',
                            url: "{{ route('admin.whatsapp.index') }}",
                        },
                        {
                            valor: this.op.derivaciones_libres ?? 0,
                            etiqueta: 'Derivaciones sin tomar',
                            color: 'text-red-600 dark:text-red-400',
                            url: "{{ route('admin.whatsapp.index') }}",
                        },
                        {
                            valor: this.op.ia_apagada ?? 0,
                            etiqueta: 'Con el agente IA apagado',
                            color: 'text-amber-600 dark:text-amber-400',
                            url: "{{ route('admin.whatsapp.index') }}",
                        },
                    ];
                },

                porAsesor() {
                    return this.op.por_asesor ?? [];
                },
            },

            mounted() {
                this.cargar();

                /*
                 * Se refresca solo cada minuto: es la única tarjeta del tablero
                 * que describe el presente, y un gerente deja esta pantalla
                 * abierta. Un minuto alcanza y no castiga la base.
                 */
                this.timer = setInterval(this.cargar, 60000);
            },

            beforeUnmount() {
                if (this.timer) clearInterval(this.timer);
            },

            methods: {
                cargar() {
                    const url = "{{ route('admin.dashboard.stats') }}";

                    Promise.all([
                        this.$axios.get(url, { params: { type: 'operacion-whatsapp' } }),
                        this.$axios.get(url, { params: { type: 'salud-cartera' } }),
                    ])
                        .then(([op, cartera]) => {
                            this.op = op.data?.statistics ?? {};
                            this.cartera = cartera.data?.statistics ?? this.cartera;
                            this.isLoading = false;
                        })
                        .catch(() => { this.isLoading = false; });
                },
            },
        });
    </script>
@endPushOnce
