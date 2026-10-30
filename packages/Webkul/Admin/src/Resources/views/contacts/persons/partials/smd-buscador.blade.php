{{--
    Buscador de ShareMeData arriba del formulario (crear y editar paciente).

    Antes de cargar nada, recepción busca por CI, teléfono, nombre o correo y
    ve si la persona ya existe en SMD (y si ya tiene ficha en el CRM, para no
    duplicarla). «Usar estos datos» pasa el paciente a la comparación
    (smd-comparar, evento `smd:encontrados`): ahí el operador elige qué datos
    van al formulario.

    Variables: $mode 'create' | 'edit', $personId (solo edit).
--}}
<v-smd-buscador
    search-url="{{ route('admin.contacts.persons.search_smd') }}"
    mode="{{ $mode }}"
    :person-id="{{ (int) ($personId ?? 0) }}"
></v-smd-buscador>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-smd-buscador-template"
    >
        <section
            class="box-shadow rounded-lg border border-gray-200 bg-white p-4 text-sm max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            aria-labelledby="smd-buscador-titulo"
        >
            <h4 id="smd-buscador-titulo" class="text-base font-bold text-gray-800 dark:text-white">
                Buscar en ShareMeData
            </h4>

            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                @{{ mode === 'create' ? 'Antes de cargar, fijate si la persona ya existe.' : 'Buscá a la persona en SMD para comparar sus datos.' }}
            </p>

            <div class="flex gap-2" role="search">
                <label for="smd-buscador-termino" class="sr-only">CI, teléfono, nombre o correo</label>

                <input
                    id="smd-buscador-termino"
                    v-model="termino"
                    type="search"
                    autocomplete="off"
                    enterkeyhint="search"
                    placeholder="CI, teléfono, nombre o correo"
                    class="min-w-0 flex-1 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 max-lg:min-h-[44px] dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    {{-- Enter busca; sin .prevent enviaría el formulario del paciente. --}}
                    @keydown.enter.prevent="buscar"
                >

                <button
                    type="button"
                    class="primary-button max-lg:min-h-[44px]"
                    :disabled="estado === 'buscando'"
                    @click="buscar"
                >
                    @{{ estado === 'buscando' ? 'Buscando…' : 'Buscar' }}
                </button>
            </div>

            <div class="mt-3" aria-live="polite">
                <p v-if="estado === 'corto'" class="text-gray-500 dark:text-gray-400">
                    Escribí al menos 3 caracteres.
                </p>

                <p v-else-if="estado === 'error'" class="text-red-700 dark:text-red-400">
                    @{{ error }}
                </p>

                <p v-else-if="estado === 'nadie'" class="rounded-md bg-gray-50 px-3 py-2 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <strong>«@{{ buscado }}» no está en ShareMeData.</strong>
                    @{{ mode === 'create' ? 'Podés cargarlo como paciente nuevo.' : '' }}
                </p>

                <template v-else-if="estado === 'listo'">
                    <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                        @{{ pacientes.length === 1 ? 'Existe en ShareMeData:' : `${pacientes.length} pacientes en ShareMeData con «${buscado}»:` }}
                    </p>

                    <ul class="flex flex-col gap-2" role="list">
                        <li
                            v-for="(paciente, posicion) in pacientes"
                            :key="paciente.smd_id ?? posicion"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800"
                        >
                            <div class="min-w-0 [overflow-wrap:anywhere]">
                                <p class="font-semibold text-gray-800 dark:text-white">@{{ paciente.name || 'Sin nombre' }}</p>

                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="paciente.ci">CI @{{ paciente.ci }}</span>
                                    <span v-if="paciente.phone"> · Tel. @{{ paciente.phone }}</span>
                                    <span v-if="paciente.birthday"> · Nac. @{{ paciente.birthday }}</span>
                                </p>

                                <p v-if="paciente.crm" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                                    @{{ esEstaFicha(paciente) ? 'Es esta ficha del CRM.' : 'Ya tiene ficha en el CRM.' }}
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <a
                                    v-if="paciente.crm && ! esEstaFicha(paciente)"
                                    :href="paciente.crm.url"
                                    class="secondary-button max-lg:min-h-[44px]"
                                >
                                    Abrir ficha
                                </a>

                                {{-- Crear: si ya tiene ficha, se abre esa en vez de duplicarla. --}}
                                <button
                                    v-if="mode !== 'create' || ! paciente.crm"
                                    type="button"
                                    class="secondary-button max-lg:min-h-[44px]"
                                    @click="usar(paciente)"
                                >
                                    Usar estos datos
                                </button>
                            </div>
                        </li>
                    </ul>
                </template>
            </div>
        </section>
    </script>

    <script type="module">
        app.component('v-smd-buscador', {
            template: '#v-smd-buscador-template',

            props: ['searchUrl', 'mode', 'personId'],

            data() {
                return {
                    termino: '',
                    buscado: '',
                    estado: 'inicio', // inicio | corto | buscando | listo | nadie | error
                    pacientes: [],
                    error: '',
                };
            },

            methods: {
                async buscar() {
                    const termino = this.termino.trim();

                    if (termino.length < 3) {
                        this.estado = 'corto';

                        return;
                    }

                    this.buscado = termino;
                    this.estado = 'buscando';

                    try {
                        const { data } = await this.$axios.get(this.searchUrl, { params: { q: termino } });

                        // Si mientras tanto buscaron otra cosa, esta respuesta ya no sirve.
                        if (termino !== this.buscado) {
                            return;
                        }

                        if (data.error) {
                            this.error = data.message;
                            this.estado = 'error';

                            return;
                        }

                        this.pacientes = data.found ? (data.patients ?? [data]) : [];
                        this.estado = this.pacientes.length ? 'listo' : 'nadie';
                    } catch (error) {
                        this.error = error.response?.status === 429
                            ? 'Demasiadas búsquedas seguidas. Esperá un minuto.'
                            : (error.response?.data?.message || 'No se pudo buscar en ShareMeData.');
                        this.estado = 'error';
                    }
                },

                esEstaFicha(paciente) {
                    return this.mode === 'edit' && paciente.crm?.id === this.personId;
                },

                usar(paciente) {
                    this.$emitter.emit('smd:encontrados', { pacientes: [paciente] });

                    // La comparación está justo debajo del buscador: se la trae a la vista.
                    setTimeout(() => document.getElementById('smd-comparar-titulo')
                        ?.closest('section')
                        ?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
                },
            },
        });
    </script>
@endPushOnce
