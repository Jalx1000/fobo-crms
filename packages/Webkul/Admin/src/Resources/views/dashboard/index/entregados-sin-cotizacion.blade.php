{{--
    Aviso «N pedidos entregados sin cotización» (D6 del spec tablero-consistente).
    Vendidos y valor de ventas salen de las cotizaciones: un entregado sin
    cotización suma 0. El aviso lista esos pedidos para que se completen.
    No se muestra mientras carga ni cuando no hay ninguno.
--}}
<v-dashboard-entregados-sin-cotizacion></v-dashboard-entregados-sin-cotizacion>

@pushOnce('scripts')
    <script type="text/x-template" id="v-dashboard-entregados-sin-cotizacion-template">
        <div
            v-if="aviso.total > 0"
            class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
            role="status"
        >
            <p class="text-sm font-semibold">
                @{{ aviso.total }} @{{ aviso.total === 1 ? 'pedido entregado sin cotización' : 'pedidos entregados sin cotización' }} — complétalos
            </p>

            <p class="mt-0.5 text-xs">
                No suman en productos vendidos ni en valor de ventas hasta que tengan su cotización.
            </p>

            <ul class="mt-2 flex flex-col gap-1 text-xs">
                <li v-for="pedido in aviso.pedidos" :key="pedido.id">
                    <a
                        :href="pedido.url"
                        class="font-semibold underline hover:no-underline"
                    >#@{{ pedido.id }}</a>
                    · @{{ pedido.title }}<template v-if="pedido.asesor"> · @{{ pedido.asesor }}</template>
                </li>
            </ul>
        </div>
    </script>

    <script type="module">
        app.component('v-dashboard-entregados-sin-cotizacion', {
            template: '#v-dashboard-entregados-sin-cotizacion-template',

            data() {
                return {
                    aviso: { total: 0, pedidos: [] },
                }
            },

            mounted() {
                this.getStats({});

                this.$emitter.on('reporting-filter-updated', this.getStats);
            },

            methods: {
                getStats(filters) {
                    const params = Object.assign({}, filters, { type: 'entregados-sin-cotizacion' });

                    this.$axios.get("{{ route('admin.dashboard.stats') }}", { params })
                        .then(response => {
                            this.aviso = response.data.statistics ?? { total: 0, pedidos: [] };
                        })
                        .catch(() => {
                            this.aviso = { total: 0, pedidos: [] };
                        });
                },
            }
        });
    </script>
@endPushOnce
