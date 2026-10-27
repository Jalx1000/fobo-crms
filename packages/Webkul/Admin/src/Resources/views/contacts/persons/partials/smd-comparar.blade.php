{{--
    Comparación formulario ↔ ShareMeData (crear y editar paciente).

    smd-checker busca en SMD y emite `smd:encontrados` con los pacientes que
    coinciden. Este componente:
      - si hay varios (una familia comparte el teléfono), pide elegir uno;
      - muestra cada dato del formulario al lado del de SMD. Regla: SMD gana
        por defecto (las diferencias vienen marcadas) pero el operador elige;
        nada se pisa hasta «Usar datos marcados», y se puede deshacer;
      - los seguros de SMD que faltan se agregan como chips del multiselect y,
        con CI y seguro, se dispara la verificación (window.__odkVerifyInsurance).

    Lee y escribe los campos por el DOM, como smd-checker: el formulario lo
    arman los componentes de atributos y no expone su estado.
--}}
<v-smd-comparar></v-smd-comparar>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-smd-comparar-template"
    >
        <section
            v-if="pacientes.length"
            class="rounded-lg border border-gray-200 bg-white p-4 text-sm max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            aria-labelledby="smd-comparar-titulo"
        >
            <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h4 id="smd-comparar-titulo" class="text-base font-bold text-gray-800 dark:text-white">
                        Datos de ShareMeData
                    </h4>

                    <p class="text-xs text-gray-500 dark:text-gray-400" v-if="paciente">
                        Lo marcado reemplaza lo del formulario. Desmarcá lo que quieras conservar.
                    </p>

                    <p class="text-xs text-gray-500 dark:text-gray-400" v-else>
                        Hay @{{ pacientes.length }} pacientes en SMD con este dato. Elegí cuál es.
                    </p>
                </div>

                <button
                    type="button"
                    class="text-xs font-semibold text-brandColor hover:underline max-lg:min-h-[44px]"
                    v-if="paciente && pacientes.length > 1"
                    @click="elegir(null)"
                >
                    Cambiar paciente
                </button>
            </div>

            <!-- Varios pacientes: elegir uno -->
            <ul v-if="! paciente" class="flex flex-col gap-2" role="list">
                <li v-for="(opcion, posicion) in pacientes" :key="opcion.smd_id ?? posicion">
                    <button
                        type="button"
                        class="flex w-full min-h-[44px] flex-col items-start gap-0.5 rounded-lg border border-gray-200 px-3 py-2 text-left transition hover:border-brandColor focus:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:border-gray-800"
                        @click="elegir(opcion)"
                    >
                        <span class="font-semibold text-gray-800 dark:text-white">@{{ opcion.name || 'Sin nombre' }}</span>

                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            @{{ resumen(opcion) }}
                        </span>
                    </button>
                </li>
            </ul>

            <!-- Comparación campo por campo -->
            <template v-else>
                <div class="hidden grid-cols-[2rem_13rem_1fr_1fr] gap-x-3 border-b border-gray-200 pb-2 text-xs font-semibold text-gray-500 lg:grid dark:border-gray-800 dark:text-gray-400">
                    <span></span>
                    <span>Dato</span>
                    <span>En el formulario</span>
                    <span>En SMD</span>
                </div>

                <ul class="divide-y divide-gray-100 dark:divide-gray-800" role="list">
                    <li
                        v-for="fila in filas"
                        :key="fila.key"
                        class="grid grid-cols-[2rem_1fr] items-start gap-x-3 gap-y-1 py-2.5 lg:grid-cols-[2rem_13rem_1fr_1fr] lg:items-center"
                    >
                        <!-- Casilla: solo si hay algo que cambiar -->
                        <span class="row-span-3 flex h-6 items-center lg:row-span-1">
                            <input
                                type="checkbox"
                                class="h-5 w-5 cursor-pointer accent-brandColor"
                                :id="'smd-usar-' + fila.key"
                                v-model="marcados[fila.key]"
                                v-if="fila.estado !== 'igual'"
                            >

                            <span
                                class="icon-tick text-xl text-green-600"
                                aria-hidden="true"
                                v-else
                            ></span>
                        </span>

                        <label
                            :for="fila.estado !== 'igual' ? 'smd-usar-' + fila.key : null"
                            class="flex flex-wrap items-center gap-1.5 font-medium text-gray-800 dark:text-white"
                        >
                            @{{ fila.label }}

                            <span
                                class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                :class="{
                                    'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300': fila.estado === 'igual',
                                    'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300': fila.estado === 'nuevo',
                                    'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300': fila.estado === 'distinto',
                                }"
                                v-text="etiquetaEstado[fila.estado]"
                            ></span>
                        </label>

                        <span class="text-gray-600 [overflow-wrap:anywhere] dark:text-gray-400" :class="{ 'line-through opacity-70': marcados[fila.key] && fila.estado === 'distinto' }">
                            <span class="text-xs text-gray-400 lg:hidden">Formulario: </span>@{{ fila.actual || '—' }}
                        </span>

                        <span class="font-medium text-gray-800 [overflow-wrap:anywhere] dark:text-white">
                            <span class="text-xs font-normal text-gray-400 lg:hidden">SMD: </span>@{{ fila.smd }}<span v-if="fila.detalle" class="text-xs font-normal text-gray-500"> (@{{ fila.detalle }})</span>
                        </span>
                    </li>
                </ul>

                <div class="mt-3 flex flex-wrap items-center gap-2" aria-live="polite">
                    <button
                        type="button"
                        class="primary-button max-lg:min-h-[44px] max-lg:flex-1 max-lg:justify-center"
                        :disabled="! cantidadMarcados"
                        @click="usarMarcados"
                    >
                        Usar datos marcados (@{{ cantidadMarcados }})
                    </button>

                    <button
                        type="button"
                        class="secondary-button max-lg:min-h-[44px]"
                        v-if="deshacer"
                        @click="deshacerCambios"
                    >
                        Deshacer
                    </button>

                    <span class="text-xs text-gray-500 dark:text-gray-400" v-if="! cantidadMarcados && ! deshacer">
                        El formulario ya coincide con SMD.
                    </span>
                </div>
            </template>
        </section>
    </script>

    <script type="module">
        // Sin tildes, mayúsculas ni espacios de más: «Membresía» = «membresia».
        const normalizar = (texto) => String(texto ?? '')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();

        const soloDigitos = (texto) => String(texto ?? '').replace(/\D/g, '');

        // Años cumplidos a partir de 'YYYY-MM-DD'.
        function edadDesde(nacimiento) {
            const fecha = new Date(nacimiento);

            if (! nacimiento || isNaN(fecha.getTime())) {
                return null;
            }

            const hoy = new Date();
            let edad = hoy.getFullYear() - fecha.getFullYear();
            const mes = hoy.getMonth() - fecha.getMonth();

            if (mes < 0 || (mes === 0 && hoy.getDate() < fecha.getDate())) {
                edad--;
            }

            return edad >= 0 && edad < 130 ? String(edad) : null;
        }

        // Datos simples: selector del campo del formulario y cómo sacar el valor de SMD.
        const CAMPOS = [
            { key: 'name', label: 'Nombre', selector: '[name="name"]', smd: (p) => p.name },
            { key: 'phone', label: 'Teléfono', selector: '[name="contact_numbers[0][value]"]', smd: (p) => p.phone, comparar: soloDigitos },
            { key: 'email', label: 'Correo', selector: '[name="emails[0][value]"]', smd: (p) => p.email },
            { key: 'ci', label: 'Carnet de identidad', selector: '[name="ci_paciente"]', smd: (p) => p.ci, comparar: soloDigitos },
            { key: 'edad', label: 'Edad', selector: '[name="job_title"]', smd: (p) => edadDesde(p.birthday), detalle: (p) => p.birthday ? 'nac. ' + p.birthday : null },
        ];

        function escribir(selector, valor) {
            const input = document.querySelector(selector);

            if (! input) {
                return;
            }

            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, valor);

            ['input', 'change', 'blur'].forEach((evento) => input.dispatchEvent(new Event(evento, { bubbles: true })));
        }

        // Nombres de los seguros cargados como chips en el multiselect.
        function segurosDelFormulario() {
            return [...document.querySelectorAll('[name="seguro_paciente[]"]')]
                .map((input) => input.closest('span')?.innerText?.trim())
                .filter(Boolean);
        }

        app.component('v-smd-comparar', {
            template: '#v-smd-comparar-template',

            data() {
                return {
                    pacientes: [],
                    paciente: null,
                    filas: [],
                    marcados: {},
                    deshacer: null,
                    etiquetaEstado: { igual: 'Igual', nuevo: 'Nuevo', distinto: 'Distinto' },
                };
            },

            computed: {
                cantidadMarcados() {
                    return this.filas.filter((fila) => fila.estado !== 'igual' && this.marcados[fila.key]).length;
                },
            },

            mounted() {
                this.$emitter.on('smd:encontrados', this.recibir);
            },

            beforeUnmount() {
                this.$emitter.off('smd:encontrados', this.recibir);
            },

            methods: {
                recibir({ pacientes = [] } = {}) {
                    this.pacientes = pacientes;
                    this.deshacer = null;

                    this.elegir(pacientes.length === 1 ? pacientes[0] : null);
                },

                elegir(paciente) {
                    this.paciente = paciente;

                    this.comparar();
                },

                resumen(paciente) {
                    return [
                        paciente.ci && 'CI ' + paciente.ci,
                        paciente.phone && 'Tel. ' + paciente.phone,
                        paciente.birthday && 'Nac. ' + paciente.birthday,
                    ].filter(Boolean).join(' · ');
                },

                /**
                 * Arma las filas con el valor actual del formulario y el de SMD.
                 * Regla del CRM: SMD gana por defecto -lo nuevo y lo distinto
                 * vienen marcados- pero el operador elige qué usar.
                 */
                comparar() {
                    const paciente = this.paciente;

                    if (! paciente) {
                        this.filas = [];
                        this.marcados = {};

                        return;
                    }

                    const filas = [];

                    CAMPOS.forEach((campo) => {
                        const smd = campo.smd(paciente);
                        const input = document.querySelector(campo.selector);

                        if (! smd || ! input) {
                            return;
                        }

                        const actual = input.value.trim();
                        const igualar = campo.comparar ?? normalizar;

                        filas.push({
                            key: campo.key,
                            label: campo.label,
                            selector: campo.selector,
                            actual,
                            smd: String(smd),
                            detalle: campo.detalle?.(paciente) ?? null,
                            estado: ! actual ? 'nuevo' : (igualar(actual) === igualar(smd) ? 'igual' : 'distinto'),
                        });
                    });

                    // Seguros: solo se agregan los que faltan (nunca se quitan los del formulario).
                    const smdSeguros = paciente.insurances?.length ? paciente.insurances : (paciente.insurance ? [paciente.insurance] : []);

                    if (smdSeguros.length) {
                        const cargados = segurosDelFormulario();
                        const faltan = smdSeguros.filter((seguro) => ! cargados.some((c) => normalizar(c) === normalizar(seguro)));

                        filas.push({
                            key: 'seguros',
                            label: smdSeguros.length > 1 ? 'Seguros' : 'Seguro',
                            actual: cargados.join(', '),
                            smd: smdSeguros.join(', '),
                            faltan,
                            detalle: faltan.length && cargados.length ? 'se agrega ' + faltan.join(', ') : null,
                            estado: ! faltan.length ? 'igual' : (cargados.length ? 'distinto' : 'nuevo'),
                        });
                    }

                    this.filas = filas;
                    this.marcados = Object.fromEntries(filas.map((fila) => [fila.key, fila.estado !== 'igual']));
                },

                usarMarcados() {
                    const elegidas = this.filas.filter((fila) => fila.estado !== 'igual' && this.marcados[fila.key]);
                    const anteriores = [];

                    elegidas.filter((fila) => fila.selector).forEach((fila) => {
                        anteriores.push({ selector: fila.selector, valor: fila.actual });

                        escribir(fila.selector, fila.smd);
                    });

                    const terminar = (segurosAgregados = [], noEncontrados = []) => {
                        this.deshacer = { anteriores, segurosAgregados };

                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: 'Datos de SMD aplicados: ' + elegidas.map((fila) => fila.label).join(', ') + '.',
                        });

                        if (noEncontrados.length) {
                            this.$emitter.emit('add-flash', {
                                type: 'warning',
                                message: 'SMD indica ' + noEncontrados.join(', ') + ', que no está entre las opciones de Seguro: elegilo a mano.',
                            });
                        }

                        this.comparar();

                        // Con CI y seguro, «Estado de seguro» se rellena solo (crear paciente).
                        const conCi = document.querySelector('[name="ci_paciente"]')?.value?.trim();

                        if (conCi && segurosDelFormulario().length && typeof window.__odkVerifyInsurance === 'function') {
                            window.__odkVerifyInsurance();
                        }
                    };

                    const seguros = elegidas.find((fila) => fila.key === 'seguros');

                    if (! seguros?.faltan?.length) {
                        terminar();

                        return;
                    }

                    this.$emitter.emit('multiselect:agregar', {
                        name: 'seguro_paciente',
                        nombres: seguros.faltan,
                        listo: terminar,
                    });
                },

                deshacerCambios() {
                    const { anteriores, segurosAgregados } = this.deshacer;

                    anteriores.forEach(({ selector, valor }) => escribir(selector, valor));

                    const listo = () => {
                        this.deshacer = null;

                        this.comparar();
                    };

                    if (! segurosAgregados.length) {
                        listo();

                        return;
                    }

                    this.$emitter.emit('multiselect:quitar', { name: 'seguro_paciente', nombres: segurosAgregados, listo });
                },
            },
        });
    </script>
@endPushOnce
