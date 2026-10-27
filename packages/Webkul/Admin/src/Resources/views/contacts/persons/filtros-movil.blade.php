{{--
    Celular (debajo de lg), igual que en Citas: el buscador queda siempre a la
    vista y el rango de fechas de registro vive en este panel lateral, detrás
    del botón «Filtros».

    Va en el slot de la barra del datagrid, que se compila en el scope de
    v-datagrid y no llega a v-persons: por eso es un componente propio que
    habla con v-persons por $emitter. v-persons sigue siendo el único que
    aplica el rango (cookie global_date_range compartida con Tablero y Citas,
    URL y recarga de la tabla):

      - persons:rango-movil     panel → v-persons  (lo que se eligió)
      - persons:rango-aplicado  v-persons → panel  (el rango vigente)

    «Filtros por columna» abre el panel de siempre de la tabla
    (datagrid:filtros-por-columna); su botón «Filtro» se esconde en el celular.

    Un período rápido o «Limpiar» cierran el panel para mostrar el resultado;
    Desde/Hasta no, porque se eligen de a dos.
--}}
@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-persons-filtros-movil-template"
    >
        <x-admin::drawer
            ref="panel"
            position="right"
            width="340px"
            class="lg:hidden [&>:nth-child(3)]:max-sm:!w-[88%]"
        >
            <x-slot:toggle>
                <button
                    type="button"
                    class="relative flex h-10 shrink-0 items-center gap-1.5 rounded-lg border bg-white px-3 text-sm font-medium text-gray-700 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                >
                    <span class="icon-filter text-xl" aria-hidden="true"></span>

                    @lang('admin::app.leads.index.filtros-movil.titulo')

                    <span
                        class="flex h-5 min-w-[20px] items-center justify-center rounded-full bg-brandColor px-1 text-[11px] font-bold text-white"
                        v-if="activos"
                        v-text="activos"
                    ></span>
                </button>
            </x-slot>

            <x-slot:header class="p-3.5">
                <p class="text-lg font-semibold dark:text-white">
                    @lang('admin::app.leads.index.filtros-movil.titulo')
                </p>
            </x-slot>

            <x-slot:content class="!p-4">
                <div class="flex flex-col gap-5 text-sm text-gray-700 dark:text-gray-300">
                    <!-- Período: los mismos rápidos del escritorio -->
                    <div class="flex flex-col gap-1.5">
                        <span class="font-semibold">@lang('admin::app.contacts.persons.index.filtros-movil.registrados')</span>

                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                class="flex h-11 items-center justify-center rounded-lg border text-center text-sm font-medium transition-all"
                                :class="rango.quick === periodo.valor
                                    ? 'border-brandColor bg-brandColor text-white'
                                    : 'bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300'"
                                :aria-pressed="rango.quick === periodo.valor"
                                v-for="periodo in periodos"
                                @click="elegirPeriodo(periodo.valor)"
                                v-text="periodo.etiqueta"
                            ></button>
                        </div>
                    </div>

                    <!-- Rango personalizado: el selector de fecha del propio celular -->
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex min-w-0 flex-col gap-1.5">
                            <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.desde')</span>

                            <input
                                type="date"
                                v-model="rango.from"
                                @change="elegirFechas"
                                class="h-11 w-full min-w-0 rounded-lg border bg-white px-2 text-base dark:border-gray-800 dark:bg-gray-900"
                            >
                        </label>

                        <label class="flex min-w-0 flex-col gap-1.5">
                            <span class="font-semibold">@lang('admin::app.leads.index.filtros-movil.hasta')</span>

                            <input
                                type="date"
                                v-model="rango.to"
                                @change="elegirFechas"
                                class="h-11 w-full min-w-0 rounded-lg border bg-white px-2 text-base dark:border-gray-800 dark:bg-gray-900"
                            >
                        </label>
                    </div>

                    <!-- Filtros por columna: el panel de siempre de la tabla (datagrid/toolbar/filter) -->
                    <button
                        type="button"
                        class="flex h-11 items-center justify-between rounded-lg border bg-white px-3 text-sm font-medium dark:border-gray-800 dark:bg-gray-900"
                        @click="abrirFiltrosPorColumna"
                    >
                        @lang('admin::app.leads.index.filtros-movil.por-columna')

                        <span class="flex items-center gap-2">
                            <span
                                class="flex h-5 min-w-[20px] items-center justify-center rounded-full bg-brandColor px-1 text-[11px] font-bold text-white"
                                v-if="columnasFiltradas"
                                v-text="columnasFiltradas"
                            ></span>

                            <span class="icon-right-arrow text-lg" aria-hidden="true"></span>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex h-11 items-center justify-center rounded-lg text-sm font-semibold text-rose-600"
                        v-if="rango.from || rango.to"
                        @click="limpiar"
                    >
                        @lang('admin::app.leads.index.filtros-movil.limpiar')
                    </button>
                </div>
            </x-slot>
        </x-admin::drawer>
    </script>

    <script type="module">
        app.component('v-persons-filtros-movil', {
            template: '#v-persons-filtros-movil-template',

            data() {
                const guardado = window.OdontoDateRange.read();

                return {
                    rango: {
                        from: guardado.from,
                        to: guardado.to,
                        quick: window.OdontoDateRange.normalizeQuick(guardado.quick),
                    },

                    columnasFiltradas: 0,

                    periodos: [
                        { valor: 7, etiqueta: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.last-7-days') }}' },
                        { valor: 30, etiqueta: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.last-30-days') }}' },
                        { valor: 90, etiqueta: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.last-90-days') }}' },
                        { valor: 'month', etiqueta: '{{ trans('admin::app.leads.index.kanban.toolbar.date-range.this-month') }}' },
                    ],
                };
            },

            computed: {
                activos() {
                    return (this.rango.from || this.rango.to ? 1 : 0) + this.columnasFiltradas;
                },
            },

            mounted() {
                this.$emitter.on('persons:rango-aplicado', this.reflejar);

                this.$emitter.on('datagrid:columnas-filtradas', this.contarColumnas);
            },

            beforeUnmount() {
                this.$emitter.off('persons:rango-aplicado', this.reflejar);

                this.$emitter.off('datagrid:columnas-filtradas', this.contarColumnas);
            },

            methods: {
                contarColumnas(cantidad) {
                    this.columnasFiltradas = cantidad;
                },

                abrirFiltrosPorColumna() {
                    this.$refs.panel.close();

                    this.$emitter.emit('datagrid:filtros-por-columna');
                },

                reflejar(rango) {
                    this.rango = { ...rango };
                },

                enviar(rango) {
                    this.$emitter.emit('persons:rango-movil', rango);
                },

                elegirPeriodo(quick) {
                    const { from, to } = window.OdontoDateRange.resolve(quick);

                    this.enviar({ from, to, quick });

                    this.$refs.panel.close();
                },

                elegirFechas() {
                    this.enviar({ from: this.rango.from, to: this.rango.to, quick: '' });
                },

                limpiar() {
                    this.enviar({ from: '', to: '', quick: '' });

                    this.$refs.panel.close();
                },
            },
        });
    </script>
@endPushOnce
