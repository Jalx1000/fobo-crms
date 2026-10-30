{{--
    Coberturas detectadas con la cédula (crear y editar paciente).

    Al completar el Carnet de identidad se consulta contra TODAS las
    aseguradoras con integración (solo lectura, 30 min en caché) y se listan
    las que tienen al paciente: si ese seguro no está elegido, «Agregar» lo
    suma como chip; nunca se agrega solo (el operador decide). También avisa
    qué aseguradoras no se pudieron consultar.

    Lee el formulario por el DOM, como smd-comparar: el CI llega tipeado o
    puesto por la comparación con SMD (que dispara change/blur).
--}}
<v-seguros-detectados verify-url="{{ route('admin.contacts.persons.verify_insurance_ci') }}"></v-seguros-detectados>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-seguros-detectados-template"
    >
        <div
            v-if="estado !== 'vacio'"
            class="mt-4 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800 dark:text-gray-300"
            aria-live="polite"
        >
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p class="font-semibold text-gray-800 dark:text-white">
                    Coberturas con el CI @{{ ci }}
                </p>

                <button
                    type="button"
                    class="text-xs font-semibold text-brandColor hover:underline disabled:opacity-50 max-lg:min-h-[44px]"
                    :disabled="estado === 'consultando'"
                    @click="consultar(true)"
                >
                    Consultar de nuevo
                </button>
            </div>

            <p v-if="estado === 'consultando'" class="text-gray-500 dark:text-gray-400">
                Consultando todas las aseguradoras…
            </p>

            <p v-else-if="estado === 'error'" class="text-red-700 dark:text-red-400">
                @{{ error }}
            </p>

            <template v-else>
                <ul v-if="encontrados.length" class="flex flex-col divide-y divide-gray-100 dark:divide-gray-800" role="list">
                    <li
                        v-for="resultado in encontrados"
                        :key="resultado.seguro_id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 dark:text-white">@{{ resultado.seguro_name }}</p>

                            <span
                                class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold"
                                :class="ESTADOS[resultado.status]?.clase"
                            >
                                @{{ ESTADOS[resultado.status]?.texto ?? resultado.status }}
                            </span>
                        </div>

                        <span v-if="cargado(resultado)" class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                            <span class="icon-tick text-base" aria-hidden="true"></span>
                            Ya está en Seguro
                        </span>

                        <button
                            v-else
                            type="button"
                            class="secondary-button max-lg:min-h-[44px]"
                            @click="agregar(resultado)"
                        >
                            Agregar
                        </button>
                    </li>
                </ul>

                <p v-else-if="sinRespuesta.length < resultados.length" class="text-gray-500 dark:text-gray-400">
                    No figura en ninguna aseguradora consultada.
                </p>

                <p v-if="sinRespuesta.length" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    No se pudo consultar: @{{ sinRespuesta.map((r) => r.seguro_name).join(', ') }}.
                    Probá «Consultar de nuevo» en unos minutos.
                </p>
            </template>
        </div>
    </script>

    <script type="module">
        // Clases literales (no armadas en runtime) para que Tailwind las incluya.
        const ESTADOS = {
            VIGENTE: { texto: 'Vigente', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
            EN_MORA: { texto: 'Pagos pendientes', clase: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300' },
            VENCIDO: { texto: 'Vencido', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' },
        };

        app.component('v-seguros-detectados', {
            template: '#v-seguros-detectados-template',

            props: ['verifyUrl'],

            data() {
                return {
                    ESTADOS,
                    estado: 'vacio', // vacio | consultando | listo | error
                    ci: '',
                    resultados: [],
                    error: '',
                    elegidos: [],
                };
            },

            computed: {
                encontrados() {
                    return this.resultados.filter((r) => ESTADOS[r.status]);
                },
                sinRespuesta() {
                    return this.resultados.filter((r) => r.status === 'INDETERMINADO');
                },
            },

            mounted() {
                document.addEventListener('change', this.alCambiarCi);
                document.addEventListener('focusout', this.alCambiarCi);

                // Los chips de Seguro cambian dentro de otro componente: se miran
                // sus inputs ocultos para saber qué ya está cargado.
                this.observador = new MutationObserver(() => this.leerElegidos());
                this.observador.observe(this.$el.parentElement ?? document.body, { childList: true, subtree: true });
                this.leerElegidos();

                // Editar: el paciente ya tiene CI.
                this.consultar();
            },

            beforeUnmount() {
                document.removeEventListener('change', this.alCambiarCi);
                document.removeEventListener('focusout', this.alCambiarCi);
                this.observador?.disconnect();
            },

            methods: {
                campoCi() {
                    return document.querySelector('[name="ci_paciente"]');
                },

                alCambiarCi(evento) {
                    if (evento.target === this.campoCi()) {
                        this.consultar();
                    }
                },

                leerElegidos() {
                    const elegidos = [...document.querySelectorAll('[name="seguro_paciente[]"]')]
                        .map((input) => Number(input.value));

                    // Solo si cambió: el observador también ve los re-render de este componente.
                    if (elegidos.join() !== this.elegidos.join()) {
                        this.elegidos = elegidos;
                    }
                },

                cargado(resultado) {
                    return resultado.opciones.some((id) => this.elegidos.includes(id));
                },

                async consultar(forzar = false) {
                    const ci = (this.campoCi()?.value ?? '').trim();

                    if (ci.replace(/\D/g, '').length < 5) {
                        return;
                    }

                    if (! forzar && ci === this.ci && this.estado !== 'error') {
                        return;
                    }

                    this.ci = ci;
                    this.estado = 'consultando';

                    try {
                        const { data } = await this.$axios.post(this.verifyUrl, { ci_paciente: ci });

                        // Si mientras tanto cambiaron el CI, esta respuesta ya no sirve.
                        if (ci !== this.ci) {
                            return;
                        }

                        this.resultados = data.results ?? [];
                        this.estado = 'listo';
                    } catch (error) {
                        this.error = error.response?.status === 429
                            ? 'Demasiadas consultas seguidas. Esperá un minuto.'
                            : (error.response?.data?.message || 'No se pudo consultar las aseguradoras.');
                        this.estado = 'error';
                    }
                },

                agregar(resultado) {
                    this.$emitter.emit('multiselect:agregar', {
                        name: 'seguro_paciente',
                        nombres: [resultado.seguro_name],
                        listo: (agregados) => {
                            this.leerElegidos();

                            if (! agregados.length) {
                                return;
                            }

                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: `${agregados[0]} agregado a Seguro.`,
                            });

                            // Crear paciente: rellena «Estado de seguro».
                            window.__odkVerifyInsurance?.();
                        },
                    });
                },
            },
        });
    </script>
@endPushOnce
