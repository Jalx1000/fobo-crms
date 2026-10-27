@php
    /**
     * Bloque «Cobertura» de la ficha: todo lo del seguro en un solo lugar.
     *
     * - CI y cada aseguradora cargada con SU estado (`person_insurance_statuses`),
     *   no el agregado `persons.insurance_status`, que no distingue cuál es cuál.
     * - «Verificar ahora» y «Editar» reusan el flujo del botón «Seguro»
     *   (insurance-verify: modales de resultado y de captura) por el $emitter.
     * - «Buscar en otras aseguradoras» consulta el CI contra todas (solo lectura)
     *   y ofrece «Agregar» las que tienen al paciente; recién al tocarlo se guarda.
     *
     * Estado inicial del servidor; se repinta cuando una verificación emite
     * `insurance-verified`.
     */
    $seguroAttribute = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
        ->findOneByField('code', 'seguro_paciente');

    $ciAttribute = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
        ->findOneByField('code', 'ci_paciente');

    $insurances = app(\Webkul\Admin\Services\InsuranceService::class)
        ->getPersonInsurances($person, $seguroAttribute);

    $statuses = $person->insuranceStatuses->keyBy('attribute_option_id');

    $initialSeguros = array_map(function ($insurance) use ($statuses) {
        $persisted = $statuses->get($insurance['id']);

        return $insurance + [
            'status'     => $persisted?->status,
            'message'    => $persisted?->message,
            'checked_at' => $persisted?->checked_at?->diffForHumans(),
        ];
    }, $insurances);

    $initialCi = $ciAttribute ? (string) $person->getCustomAttributeValue($ciAttribute) : '';
@endphp

{{-- Con etiqueta de cierre: en el HTML de la página un <v-... /> no se cierra solo y se tragaba «Acerca del paciente» y la organización. --}}
<v-person-insurance-statuses
    :initial='@json($initialSeguros)'
    initial-ci="{{ $initialCi }}"
    ci-url="{{ route('admin.contacts.persons.verify_insurance_ci') }}"
    update-url="{{ route('admin.contacts.persons.update_insurance', $person->id) }}"
></v-person-insurance-statuses>

@pushOnce('scripts')
    <script type="text/x-template" id="v-person-insurance-statuses-template">
        <section
            class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-800"
            aria-labelledby="cobertura-titulo"
        >
            <div class="flex items-center justify-between gap-2">
                <h4 id="cobertura-titulo" class="font-semibold dark:text-white">Cobertura</h4>

                <span
                    v-if="seguros.length"
                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="ESTADOS[general]?.clase ?? GRIS"
                >
                    @{{ ESTADOS[general]?.texto ?? 'Sin verificar' }}
                </span>
            </div>

            <p class="text-sm text-gray-600 dark:text-gray-300">
                <span class="text-gray-500 dark:text-gray-400">Carnet de identidad:</span>
                @{{ ci || 'sin cargar' }}
            </p>

            <!-- Aseguradoras cargadas -->
            <ul v-if="seguros.length" class="flex flex-col divide-y divide-gray-200 dark:divide-gray-800" role="list">
                <li
                    v-for="seguro in seguros"
                    :key="seguro.id"
                    class="flex items-start justify-between gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">@{{ seguro.name }}</span>

                        <span v-if="seguro.message" class="text-xs text-gray-500 dark:text-gray-400">
                            @{{ seguro.message }}
                        </span>

                        <span v-if="seguro.checked_at" class="text-xs text-gray-400">
                            Verificado @{{ seguro.checked_at }}
                        </span>
                    </div>

                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="ESTADOS[seguro.status]?.clase ?? GRIS"
                    >
                        @{{ ESTADOS[seguro.status]?.texto ?? (seguro.status || 'Sin verificar') }}
                    </span>
                </li>
            </ul>

            <p v-else class="text-sm text-gray-500 dark:text-gray-400">Sin seguro cargado.</p>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="secondary-button max-lg:min-h-[44px]"
                    @click="$emitter.emit('cobertura:verificar')"
                >
                    Verificar ahora
                </button>

                <button
                    type="button"
                    class="secondary-button max-lg:min-h-[44px]"
                    @click="$emitter.emit('cobertura:editar')"
                >
                    Editar
                </button>

                <button
                    v-if="ci"
                    type="button"
                    class="secondary-button max-lg:min-h-[44px]"
                    :disabled="buscando"
                    @click="buscarOtras"
                >
                    @{{ buscando ? 'Buscando…' : 'Buscar en otras aseguradoras' }}
                </button>
            </div>

            <!-- Resultado de «Buscar en otras aseguradoras» -->
            <div v-if="otras" class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800" aria-live="polite">
                <ul v-if="otrasEncontradas.length" class="flex flex-col divide-y divide-gray-100 dark:divide-gray-800" role="list">
                    <li
                        v-for="resultado in otrasEncontradas"
                        :key="resultado.seguro_id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 dark:text-white">@{{ resultado.seguro_name }}</p>

                            <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold" :class="ESTADOS[resultado.status].clase">
                                @{{ ESTADOS[resultado.status].texto }}
                            </span>
                        </div>

                        <span v-if="cargado(resultado)" class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                            <span class="icon-tick text-base" aria-hidden="true"></span>
                            Ya está cargado
                        </span>

                        <button
                            v-else
                            type="button"
                            class="primary-button max-lg:min-h-[44px]"
                            :disabled="agregando"
                            @click="agregar(resultado)"
                        >
                            Agregar
                        </button>
                    </li>
                </ul>

                <p v-else-if="otrasSinRespuesta.length < otras.length" class="text-gray-500 dark:text-gray-400">
                    No figura en ninguna aseguradora consultada.
                </p>

                <p v-if="otrasSinRespuesta.length" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    No se pudo consultar: @{{ otrasSinRespuesta.map((r) => r.seguro_name).join(', ') }}.
                </p>
            </div>
        </section>
    </script>

    <script type="module">
        /**
         * Clases literales, no concatenadas: Tailwind purga lo que no puede "ver"
         * en el template, así que armar el nombre en runtime dejaría el color
         * fuera del CSS de producción.
         */
        const ESTADOS = {
            VIGENTE:       { texto: 'Vigente', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
            EN_MORA:       { texto: 'Pagos pendientes', clase: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300' },
            VENCIDO:       { texto: 'Vencido', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' },
            NO_REGISTRADO: { texto: 'No registrado', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' },
            SIN_SEGURO:    { texto: 'Sin seguro', clase: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' },
            INDETERMINADO: { texto: 'No se pudo verificar', clase: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' },
        };

        const GRIS = 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';

        // Mejor cobertura primero, igual que InsuranceService::STATUS_PRIORITY.
        const PRIORIDAD = ['VIGENTE', 'EN_MORA', 'VENCIDO', 'NO_REGISTRADO', 'SIN_SEGURO', 'INDETERMINADO'];

        app.component('v-person-insurance-statuses', {
            template: '#v-person-insurance-statuses-template',

            props: {
                initial:   { type: Array, default: () => [] },
                initialCi: { type: String, default: '' },
                ciUrl:     { type: String, required: true },
                updateUrl: { type: String, required: true },
            },

            data() {
                return {
                    ESTADOS,
                    GRIS,
                    seguros: this.initial,
                    ci: this.initialCi,
                    otras: null,
                    buscando: false,
                    agregando: false,
                };
            },

            computed: {
                general() {
                    return PRIORIDAD.find((estado) => this.seguros.some((s) => s.status === estado)) ?? null;
                },
                otrasEncontradas() {
                    return (this.otras ?? []).filter((r) => ['VIGENTE', 'EN_MORA', 'VENCIDO'].includes(r.status));
                },
                otrasSinRespuesta() {
                    return (this.otras ?? []).filter((r) => r.status === 'INDETERMINADO');
                },
            },

            mounted() {
                this.$emitter.on('insurance-verified', this.alVerificar);
            },

            beforeUnmount() {
                this.$emitter.off('insurance-verified', this.alVerificar);
            },

            methods: {
                alVerificar(payload) {
                    const results = Array.isArray(payload?.results) ? payload.results : (payload?.seguro_id ? [payload] : []);

                    if (! results.length) return;

                    this.seguros = results.map((r) => ({
                        id:         r.seguro_id,
                        name:       r.seguro_name,
                        status:     r.status,
                        message:    r.message,
                        checked_at: 'hace instantes',
                    }));

                    if (payload.ci) {
                        this.ci = payload.ci;
                    }
                },

                cargado(resultado) {
                    return resultado.opciones.some((id) => this.seguros.some((s) => Number(s.id) === id));
                },

                async buscarOtras() {
                    this.buscando = true;

                    try {
                        const { data } = await this.$axios.post(this.ciUrl, { ci_paciente: this.ci });

                        this.otras = data.results ?? [];
                    } catch (error) {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error.response?.status === 429
                                ? 'Demasiadas consultas seguidas. Esperá un minuto.'
                                : (error.response?.data?.message || 'No se pudo consultar las aseguradoras.'),
                        });
                    } finally {
                        this.buscando = false;
                    }
                },

                /**
                 * Guarda el seguro en la ficha (junto a los que ya tenía) y vuelve
                 * a verificar todos: el operador lo pidió con «Agregar».
                 */
                async agregar(resultado) {
                    this.agregando = true;

                    try {
                        const { data } = await this.$axios.post(this.updateUrl, {
                            ci_paciente:     this.ci,
                            seguro_paciente: [...new Set([...this.seguros.map((s) => Number(s.id)), resultado.seguro_id])],
                        });

                        this.$emitter.emit('insurance-verified', data);
                        this.$emitter.emit('refresh-activities');
                        this.$emitter.emit('add-flash', { type: 'success', message: `${resultado.seguro_name} agregado y verificado.` });
                    } catch (error) {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error.response?.data?.message || 'No se pudo agregar el seguro.',
                        });
                    } finally {
                        this.agregando = false;
                    }
                },
            },
        });
    </script>
@endPushOnce
