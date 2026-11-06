{{--
    El pedido del cliente, gestionado sin salir del chat: etapa, entrega, cancelación,
    asesor y ciudad. Va en su propia fila, fuera de la cabecera clicable: dentro de ella
    Enter sobre este botón abriría también el panel del cliente.
--}}
@pushOnce('scripts')
    @php
        // Afuera del @json: Blade corta su expresión por las comas y un array multilínea se rompe.
        $waPedidoRutas = [
            'pedidos' => route('admin.whatsapp.pedido.index', '__ID__'),
            'entrega' => route('admin.whatsapp.pedido.entrega', '__ID__'),
            'asesor'  => route('admin.whatsapp.pedido.asesor', '__ID__'),
            'ciudad'  => route('admin.whatsapp.pedido.ciudad', '__ID__'),
            'etapa'   => route('admin.leads.stage.update', '__ID__'),
        ];
    @endphp

    <style>
        .wa-pedido { position: relative; border-bottom: 1px solid #e5e7eb; background: #f9fafb; padding: 6px 16px; }
        .dark .wa-pedido { border-color: #1f2937; background: #111827; }

        .wa-pedido__chip {
            display: flex; align-items: center; gap: 6px; min-height: 32px; max-width: 100%;
            border: 1px solid #d1d5db; border-radius: 6px; background: #fff; padding: 2px 8px;
            font-size: 12px; color: #374151; cursor: pointer;
        }
        .dark .wa-pedido__chip { border-color: #374151; background: #1f2937; color: #e5e7eb; }
        .wa-pedido__chip:hover { border-color: var(--brand-color); }
        .wa-pedido__chip:focus-visible,
        .wa-pedido__btn:focus-visible,
        .wa-pedido__etapa:focus-visible,
        .wa-pedido__fila:focus-visible { outline: 2px solid var(--brand-color); outline-offset: 1px; }
        .wa-pedido__resumen { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wa-pedido__nota { font-size: 12px; color: #6b7280; }
        .dark .wa-pedido__nota { color: #9ca3af; }
        .wa-pedido__skeleton { height: 32px; width: 220px; max-width: 100%; border-radius: 6px; background: #e5e7eb; }
        .dark .wa-pedido__skeleton { background: #1f2937; }

        .wa-pedido__panel {
            position: absolute; z-index: 30; top: calc(100% - 2px); left: 16px; width: 380px;
            max-width: calc(100% - 32px); max-height: 340px; overflow-y: auto;
            border: 1px solid #e5e7eb; border-radius: 8px; background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
        }
        .dark .wa-pedido__panel { border-color: #374151; background: #111827; }
        .wa-pedido__seccion { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; }
        .dark .wa-pedido__seccion { border-color: #1f2937; }
        .wa-pedido__seccion:last-child { border-bottom: 0; }
        .wa-pedido__titulo { margin: 0 0 4px; font-size: 12px; font-weight: 600; color: #4b5563; }
        .dark .wa-pedido__titulo { color: #d1d5db; }

        .wa-pedido__fila {
            display: block; width: 100%; border: 0; border-radius: 4px; background: transparent;
            padding: 6px 8px; font-size: 12px; text-align: left; color: #1f2937; cursor: pointer;
        }
        .dark .wa-pedido__fila { color: #e5e7eb; }
        .wa-pedido__fila:hover { background: #f3f4f6; }
        .dark .wa-pedido__fila:hover { background: #1f2937; }
        .wa-pedido__fila[aria-current="true"] { font-weight: 600; background: #f3f4f6; }
        .dark .wa-pedido__fila[aria-current="true"] { background: #1f2937; }

        .wa-pedido__etapas { display: flex; flex-wrap: wrap; gap: 4px; }
        .wa-pedido__etapa {
            min-height: 32px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb;
            padding: 2px 10px; font-size: 12px; color: #374151; cursor: pointer;
        }
        .dark .wa-pedido__etapa { border-color: #374151; background: #1f2937; color: #e5e7eb; }
        .wa-pedido__etapa:hover { border-color: var(--brand-color); }
        /* Borde y ✓, no blanco sobre marca: el color de marca lo elige cada instalación. */
        .wa-pedido__etapa[aria-current="true"] { border-color: var(--brand-color); font-weight: 600; cursor: default; }
        .wa-pedido__etapa:disabled, .wa-pedido__btn:disabled { opacity: .5; cursor: default; }

        .wa-pedido__campo { display: flex; gap: 6px; }
        .wa-pedido__select, .wa-pedido__input {
            min-width: 0; flex: 1; min-height: 32px; border: 1px solid #d1d5db; border-radius: 6px;
            background: #fff; padding: 2px 6px; font-size: 12px; color: #1f2937;
        }
        .dark .wa-pedido__select, .dark .wa-pedido__input { border-color: #374151; background: #1f2937; color: #e5e7eb; }
        .wa-pedido__btn {
            min-height: 32px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;
            padding: 2px 10px; font-size: 12px; font-weight: 500; color: #374151; cursor: pointer;
        }
        .dark .wa-pedido__btn { border-color: #374151; background: #1f2937; color: #e5e7eb; }
        .wa-pedido__enlace { font-size: 12px; font-weight: 500; color: var(--brand-color); }

        @media (max-width: 1023px) {
            .wa-pedido__panel { left: 8px; right: 8px; width: auto; max-width: none; max-height: 60dvh; }
            .wa-pedido__chip { width: 100%; }
            .wa-pedido__etapa, .wa-pedido__btn, .wa-pedido__select, .wa-pedido__input, .wa-pedido__fila { min-height: 44px; }
        }
    </style>

    <script type="text/x-template" id="v-whatsapp-pedido-template">
        <div class="wa-pedido" ref="raiz" :aria-busy="guardando ? 'true' : 'false'">
            <div v-if="cargando" class="wa-pedido__skeleton" aria-label="Cargando pedidos"></div>

            <div v-else-if="error" class="flex items-center gap-2">
                <span class="wa-pedido__nota">No se pudieron cargar los pedidos.</span>
                <button type="button" class="wa-pedido__btn" @click="cargar">Reintentar</button>
            </div>

            <div v-else-if="!pedido" class="flex items-center gap-2">
                <span class="wa-pedido__nota">Sin pedidos.</span>
                <button type="button" class="wa-pedido__btn" @click="$emit('crear')">Crear pedido</button>
            </div>

            <template v-else>
                <button type="button" ref="chip" class="wa-pedido__chip"
                        aria-haspopup="true"
                        :aria-expanded="abierto ? 'true' : 'false'"
                        aria-controls="wa-pedido-panel"
                        :aria-label="'Gestionar pedido: ' + resumen"
                        @click="alternar">
                    <span class="icon-bookmark text-sm"></span>
                    <span class="wa-pedido__resumen">@{{ resumen }}</span>
                    <span class="icon-down-arrow shrink-0 text-sm"></span>
                </button>

                <div v-if="abierto" id="wa-pedido-panel" class="wa-pedido__panel wa-scroll">
                    <div v-if="pedidos.length > 1" class="wa-pedido__seccion">
                        <p class="wa-pedido__titulo">Pedido de este chat</p>
                        <button v-for="item in pedidos" :key="item.id" type="button"
                                class="wa-pedido__fila"
                                :aria-current="item.id === seleccionado ? 'true' : null"
                                @click="seleccionar(item.id)">
                            <span v-if="item.id === seleccionado" aria-hidden="true">✓ </span>
                            @{{ item.title }} · @{{ item.etapa.name }} · @{{ item.fecha }}
                            <span v-if="!item.abierto">(cerrado)</span>
                        </button>
                    </div>

                    <div v-if="!pedido.abierto" class="wa-pedido__seccion">
                        <p class="wa-pedido__nota">Este pedido está cerrado: @{{ pedido.etapa.name }}.</p>
                    </div>

                    <div v-else-if="puede.etapa" class="wa-pedido__seccion">
                        <p class="wa-pedido__titulo">Etapa</p>
                        <div class="wa-pedido__etapas">
                            <button v-for="etapa in pedido.etapas" :key="etapa.id" type="button"
                                    class="wa-pedido__etapa"
                                    :disabled="guardando"
                                    :aria-current="etapa.id === pedido.etapa.id ? 'true' : null"
                                    @click="elegirEtapa(etapa)">
                                <span v-if="etapa.id === pedido.etapa.id" aria-hidden="true">✓ </span>@{{ etapa.name }}
                            </button>
                        </div>
                    </div>

                    <template v-if="pedido.abierto && puede.asignar">
                        <div class="wa-pedido__seccion">
                            <label class="wa-pedido__titulo block" for="wa-pedido-asesor">Asesor</label>
                            <div class="wa-pedido__campo">
                                <select id="wa-pedido-asesor" v-model.number="asesorElegido" class="wa-pedido__select">
                                    <option :value="0" disabled>Elegir asesor</option>
                                    <option v-for="asesor in pedido.asesores" :key="asesor.id" :value="asesor.id">@{{ asesor.name }}</option>
                                </select>
                                <button type="button" class="wa-pedido__btn"
                                        :disabled="guardando || !asesorElegido || asesorElegido === pedido.asesor.id"
                                        @click="cambiarAsesor">Cambiar</button>
                            </div>
                        </div>

                        <div class="wa-pedido__seccion">
                            <label class="wa-pedido__titulo block" for="wa-pedido-ciudad">Ciudad</label>
                            <div class="wa-pedido__campo">
                                <select id="wa-pedido-ciudad" v-model.number="ciudadElegida" class="wa-pedido__select">
                                    <option :value="0" disabled>Elegir ciudad</option>
                                    <option v-for="ciudad in ciudades" :key="ciudad.id" :value="ciudad.id">@{{ ciudad.name }}</option>
                                </select>
                                <button type="button" class="wa-pedido__btn"
                                        :disabled="guardando || !ciudadElegida || ciudadElegida === pedido.ciudad.id"
                                        @click="cambiarCiudad">Cambiar</button>
                            </div>
                        </div>
                    </template>

                    <div class="wa-pedido__seccion">
                        <a :href="pedido.url" target="_blank" rel="noopener" class="wa-pedido__enlace">Abrir pedido</a>
                    </div>
                </div>
            </template>

            <Teleport to="body">
                <x-admin::modal ref="entregaModal">
                    <x-slot:header>
                        <h3 class="text-base font-semibold dark:text-white">Confirmar entrega</h3>
                    </x-slot>

                    <x-slot:content>
                        <div class="flex flex-col gap-3 text-sm dark:text-gray-200">
                            <div>
                                <label class="mb-1 block font-medium" for="wa-entrega-foto">Foto de la entrega (opcional)</label>
                                <input id="wa-entrega-foto" ref="foto" type="file" accept="image/*" class="wa-pedido__input w-full" @change="fotoElegida">
                            </div>
                            <div class="flex gap-3">
                                <div class="min-w-0 flex-1">
                                    <label class="mb-1 block font-medium" for="wa-entrega-dados">Bidones entregados</label>
                                    <input id="wa-entrega-dados" v-model.number="entrega.entregados" type="number" min="0" step="1" class="wa-pedido__input w-full">
                                </div>
                                <div class="min-w-0 flex-1">
                                    <label class="mb-1 block font-medium" for="wa-entrega-retirados">Bidones retirados</label>
                                    <input id="wa-entrega-retirados" v-model.number="entrega.retirados" type="number" min="0" step="1" class="wa-pedido__input w-full">
                                </div>
                            </div>
                        </div>
                    </x-slot>

                    <x-slot:footer>
                        <button type="button" class="primary-button" :disabled="guardando" @click="confirmarEntrega">
                            Marcar como entregado
                        </button>
                    </x-slot>
                </x-admin::modal>
            </Teleport>

            <Teleport to="body">
                <x-admin::modal ref="cancelarModal">
                    <x-slot:header>
                        <h3 class="text-base font-semibold dark:text-white">Cancelar pedido</h3>
                    </x-slot>

                    <x-slot:content>
                        <label class="mb-1 block text-sm font-medium dark:text-gray-200" for="wa-cancelar-motivo">Motivo</label>
                        <textarea id="wa-cancelar-motivo" v-model="motivo" rows="3" maxlength="255" class="wa-pedido__input w-full"></textarea>
                    </x-slot>

                    <x-slot:footer>
                        <button type="button" class="primary-button" :disabled="guardando || !motivo.trim()" @click="confirmarCancelacion">
                            Cancelar pedido
                        </button>
                    </x-slot>
                </x-admin::modal>
            </Teleport>
        </div>
    </script>

    <script type="module">
        const waPedidoRutas = @json($waPedidoRutas);

        const waPedidoRuta = (nombre, id) => waPedidoRutas[nombre].replace('__ID__', id);

        app.component('v-whatsapp-pedido', {
            template: '#v-whatsapp-pedido-template',

            props: {
                personId: { type: Number, required: true },
                conversationId: { type: Number, default: null },
                leadId: { type: Number, default: null },
                // Cambia cuando llega algo nuevo al chat: es el momento de releer.
                refreshKey: { type: [Number, String], default: 0 },
            },

            emits: ['crear'],

            data() {
                return {
                    cargando: true,
                    error: false,
                    guardando: false,
                    abierto: false,
                    pedidos: [],
                    seleccionado: null,
                    puede: { etapa: false, asignar: false },
                    ciudades: [],
                    asesorElegido: 0,
                    ciudadElegida: 0,
                    etapaCancelada: null,
                    motivo: '',
                    entrega: { entregados: 0, retirados: 0, foto: null },
                    consulta: 0,
                };
            },

            computed: {
                pedido() {
                    return this.pedidos.find(item => item.id === this.seleccionado) ?? null;
                },

                resumen() {
                    const pedido = this.pedido;

                    return pedido
                        ? [pedido.title, pedido.etapa.name, pedido.ciudad.name, pedido.asesor.name]
                            .filter(Boolean).join(' · ')
                        : '';
                },
            },

            watch: {
                refreshKey() {
                    // Una lectura vieja no puede pisar lo que el asesor está eligiendo.
                    if (!this.guardando && !this.abierto) this.cargar();
                },

                // La conversación se resuelve después de montar: ahí se sabe qué pedido tiene.
                conversationId() {
                    this.seleccionado = null;
                    this.cargar();
                },

                pedido() {
                    this.reiniciarElegidos();
                },
            },

            mounted() {
                this.cargar();

                document.addEventListener('pointerdown', this.clicAfuera);
                document.addEventListener('keydown', this.tecla);
            },

            beforeUnmount() {
                document.removeEventListener('pointerdown', this.clicAfuera);
                document.removeEventListener('keydown', this.tecla);
            },

            methods: {
                cargar() {
                    const consulta = ++this.consulta;

                    return this.$axios.get(waPedidoRuta('pedidos', this.personId), {
                            params: {
                                conversation_id: this.conversationId || undefined,
                                lead_id: this.leadId || undefined,
                            },
                        })
                        .then(res => {
                            if (consulta !== this.consulta) return;

                            const elegido = this.seleccionado;

                            this.pedidos = res.data.pedidos;
                            this.puede = res.data.puede;
                            this.ciudades = res.data.ciudades;
                            // El pedido que el asesor eligió sigue elegido al releer.
                            this.seleccionado = this.pedidos.some(item => item.id === elegido)
                                ? elegido
                                : res.data.seleccionado;
                            this.error = false;
                            this.reiniciarElegidos();
                        })
                        .catch(() => {
                            if (consulta === this.consulta && !this.pedidos.length) this.error = true;
                        })
                        .finally(() => {
                            if (consulta === this.consulta) this.cargando = false;
                        });
                },

                reiniciarElegidos() {
                    this.asesorElegido = this.pedido?.asesor.id ?? 0;
                    this.ciudadElegida = this.pedido?.ciudad.id ?? 0;
                },

                alternar() {
                    this.abierto = !this.abierto;

                    // Otro asesor o el kanban pudieron moverlo mientras estaba cerrado.
                    if (this.abierto) this.cargar();
                },

                seleccionar(id) {
                    this.seleccionado = id;
                },

                clicAfuera(event) {
                    if (this.abierto && !this.$refs.raiz?.contains(event.target)) this.abierto = false;
                },

                tecla(event) {
                    if (event.key !== 'Escape' || !this.abierto) return;

                    this.abierto = false;
                    this.$refs.chip?.focus();
                },

                // Todas las escrituras terminan igual: avisar y releer lo que quedó.
                guardar(peticion, mensajeDeError) {
                    if (this.guardando) return Promise.resolve();

                    this.guardando = true;

                    return peticion()
                        .then(res => {
                            this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });

                            return this.cargar();
                        })
                        .catch(err => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: err.response?.data?.message || mensajeDeError,
                            });

                            throw err;
                        })
                        .finally(() => { this.guardando = false; });
                },

                moverEtapa(etapa, extra = {}) {
                    return this.guardar(
                        () => this.$axios.put(waPedidoRuta('etapa', this.pedido.id), {
                            lead_pipeline_stage_id: etapa.id,
                            ...extra,
                        }),
                        'No se pudo cambiar la etapa.',
                    );
                },

                elegirEtapa(etapa) {
                    if (etapa.id === this.pedido.etapa.id) return;

                    if (etapa.tipo === 'entregado') {
                        this.entrega = { entregados: 0, retirados: 0, foto: null };
                        if (this.$refs.foto) this.$refs.foto.value = '';
                        this.abierto = false;
                        this.$refs.entregaModal.open();

                        return;
                    }

                    if (etapa.tipo === 'cancelado') {
                        this.etapaCancelada = etapa;
                        this.motivo = '';
                        this.abierto = false;
                        this.$refs.cancelarModal.open();

                        return;
                    }

                    this.moverEtapa(etapa).catch(() => {});
                },

                fotoElegida(event) {
                    this.entrega.foto = event.target.files?.[0] ?? null;
                },

                confirmarEntrega() {
                    const form = new FormData();

                    form.append('bidones_entregados', Number(this.entrega.entregados) || 0);
                    form.append('bidones_retirados', Number(this.entrega.retirados) || 0);
                    // Sólo si hay foto: append con null manda "null" y la validación lo rechaza.
                    if (this.entrega.foto) form.append('foto', this.entrega.foto);

                    this.guardar(
                        () => this.$axios.post(waPedidoRuta('entrega', this.pedido.id), form),
                        'No se pudo registrar la entrega.',
                    ).then(() => this.$refs.entregaModal.close()).catch(() => {});
                },

                confirmarCancelacion() {
                    this.moverEtapa(this.etapaCancelada, { lost_reason: this.motivo.trim() })
                        .then(() => this.$refs.cancelarModal.close())
                        .catch(() => {});
                },

                cambiarAsesor() {
                    const asesor = this.pedido.asesores.find(item => item.id === this.asesorElegido);
                    const id = this.pedido.id;

                    this.$emitter.emit('open-confirm-modal', {
                        title: 'Cambiar asesor',
                        message: `El cliente pasa a ${asesor?.name}, con sus pedidos abiertos y su conversación.`,
                        agree: () => this.guardar(
                            () => this.$axios.patch(waPedidoRuta('asesor', id), { user_id: this.asesorElegido }),
                            'No se pudo cambiar el asesor.',
                        ).catch(() => {}),
                    });
                },

                cambiarCiudad() {
                    const ciudad = this.ciudades.find(item => item.id === this.ciudadElegida);
                    const id = this.pedido.id;

                    this.$emitter.emit('open-confirm-modal', {
                        title: 'Cambiar ciudad',
                        message: `El pedido y la ficha del cliente pasan a ${ciudad?.name}. El asesor puede cambiar según el reparto de esa ciudad.`,
                        agree: () => this.guardar(
                            () => this.$axios.patch(waPedidoRuta('ciudad', id), { lead_pipeline_id: this.ciudadElegida }),
                            'No se pudo cambiar la ciudad.',
                        ).catch(() => {}),
                    });
                },
            },
        });
    </script>
@endPushOnce
