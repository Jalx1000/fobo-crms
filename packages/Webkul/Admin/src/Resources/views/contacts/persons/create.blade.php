<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.contacts.persons.create.title')
    </x-slot>

    {!! view_render_event('admin.persons.create.form.before') !!}

    <x-admin::form
        :action="route('admin.contacts.persons.store')"
        enctype="multipart/form-data"
    >
        {{-- Celular: de borde a borde, sin el gris entre bloques (como la cita). --}}
        <div class="flex flex-col gap-4 max-lg:gap-0" id="person-create-form">

            <!-- Header -->
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    {!! view_render_event('admin.persons.create.breadcrumbs.before') !!}
                    <x-admin::breadcrumbs name="contacts.persons.create" />
                    {!! view_render_event('admin.persons.create.breadcrumbs.after') !!}
                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.contacts.persons.index" />

                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.contacts.persons.create.title')
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-x-3 max-lg:flex-wrap max-lg:gap-y-2">
                    {{-- Chip de estado SMD --}}
                    <div id="smd-status-chip" class="hidden items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-all duration-200">
                        <span id="smd-status-dot" class="h-2 w-2 rounded-full"></span>
                        <span id="smd-status-text"></span>
                    </div>

                    {!! view_render_event('admin.persons.create.create_button.before') !!}
                    <button type="submit" class="primary-button">
                        @lang('admin::app.contacts.persons.create.save-btn')
                    </button>
                    {!! view_render_event('admin.persons.create.create_button.after') !!}
                </div>
            </div>

            <!-- Form fields -->
            {{-- El aviso de SMD también de borde a borde; sus clases las reescribe smd-checker, por eso desde acá. --}}
            <div class="flex flex-col gap-4 max-lg:gap-0 max-lg:[&>#smd-result-banner]:-mx-3 max-lg:[&>#smd-result-banner]:rounded-none max-lg:[&>#smd-result-banner]:border-x-0 max-lg:[&>#smd-result-banner]:px-3">
                {!! view_render_event('admin.persons.create.form_controls.before') !!}

                <!-- Sección 1: Datos del Paciente -->
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                    <div class="mb-4 flex items-center justify-between">
                        <h4 class="text-base font-bold dark:text-white">Datos del Paciente</h4>
                        {{-- Botón SMD del partial --}}
                        @include('admin::contacts.persons.partials.smd-checker', [
                            'searchUrl' => route('admin.contacts.persons.search_smd'),
                            'mode'      => 'create',
                        ])
                    </div>

                    @php
                        // Orden de captura (buenas prácticas): primero identifica al paciente
                        // (Nombre, Edad) y luego sus datos de contacto.
                        $patientFieldOrder = ['name', 'job_title', 'contact_numbers', 'emails'];

                        $patientAttributes = app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                            ['code', 'IN', $patientFieldOrder],
                            'entity_type' => 'persons',
                        ])->sortBy(fn ($attribute) => array_search($attribute->code, $patientFieldOrder))->values();
                    @endphp

                    <x-admin::attributes
                        :custom-attributes="$patientAttributes"
                        :custom-validations="[
                            'name'      => ['min:2', 'max:100'],
                            'job_title' => ['max:100'],
                        ]"
                    />
                </div>

                <!-- Banner resultado SMD -->
                <div id="smd-result-banner" class="rounded-lg border px-4 py-3 text-sm transition-all duration-300">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span id="smd-banner-icon" class="mt-0.5 shrink-0"></span>
                            <div>
                                <p id="smd-banner-title" class="font-semibold"></p>
                                <p id="smd-banner-detail" class="mt-0.5 text-xs opacity-80"></p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                id="smd-autofill-btn"
                                type="button"
                                class="hidden rounded-md bg-white px-3 py-1.5 text-xs font-semibold shadow-sm ring-1 ring-inset transition hover:bg-gray-50 focus:outline-none max-lg:min-h-[44px] max-lg:text-sm"
                                onclick="smdAutofill()"
                            >Autocompletar</button>
                            <button type="button" class="text-xs opacity-60 hover:opacity-100 focus:outline-none max-lg:flex max-lg:h-11 max-lg:w-11 max-lg:items-center max-lg:justify-center" onclick="smdDismiss()" aria-label="Cerrar">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sección 2: Datos del Seguro -->
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                    <div class="mb-4 flex items-center justify-between">
                        <h4 class="text-base font-bold dark:text-white">Datos del Seguro</h4>

                        {{-- Botón verificar seguro (modo quick: sin person_id) --}}
                        <v-insurance-quick
                            verify-url="{{ route('admin.contacts.persons.verify_insurance_quick') }}"
                        />
                    </div>

                    @php
                        // Orden: primero CI y Seguro (se capturan/eligen), y al final
                        // Estado de seguro, que el botón "Verificar Seguro" auto-rellena.
                        $insuranceFieldOrder = ['ci_paciente', 'seguro_paciente', 'estado_seguro_paciente'];

                        $insuranceAttributes = app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                            ['code', 'IN', $insuranceFieldOrder],
                            'entity_type' => 'persons',
                        ])->sortBy(fn ($attribute) => array_search($attribute->code, $insuranceFieldOrder))->values();
                    @endphp

                    <x-admin::attributes :custom-attributes="$insuranceAttributes" />

                    {{-- Panel persistente con el resultado de la verificación de seguro --}}
                    <v-insurance-result class="mt-4"></v-insurance-result>
                </div>

                <!-- Sección 3: Equipo de Atención -->
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                    <h4 class="mb-4 text-base font-bold dark:text-white">Equipo de Atención</h4>

                    <x-admin::attributes
                        :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                            ['code', 'IN', ['person_doctor', 'user_id']],
                            'entity_type' => 'persons',
                        ])"
                    />
                </div>

                {!! view_render_event('admin.persons.create.form_controls.after') !!}
            </div>
        </div>
    </x-admin::form>

    {!! view_render_event('admin.persons.create.form.after') !!}

    @pushOnce('scripts')
        <script type="text/x-template" id="v-organization-template">
            <div>
                <x-admin::attributes
                    :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                        ['code', 'IN', ['organization_id']],
                        'entity_type' => 'persons',
                    ])"
                />
                <template v-if="organizationName">
                    <x-admin::form.control-group.control
                        type="hidden"
                        name="organization_name"
                        v-model="organizationName"
                    />
                </template>
            </div>
        </script>

        <script type="module">
            app.component('v-organization', {
                template: '#v-organization-template',
                data() { return { organizationName: null }; },
                methods: {
                    handleLookupAdded(event) { this.organizationName = event?.name || null; },
                },
            });

            // Mapa compartido: severidad -> tipo de flash de Krayin.
            const insuranceFlashType = {
                success: 'success',
                warning: 'warning',
                danger:  'error',
                neutral: 'info',
            };

            // Resuelve la severidad usando el `badge` del backend como fuente de verdad.
            function insuranceSeverity(result) {
                if (! result) return 'neutral';
                if (result.badge) return result.badge; // success | warning | danger
                return result.success ? 'neutral' : 'warning';
            }

            // Aplana el `data` crudo de la aseguradora en pares label/valor mostrables.
            function insuranceDetails(data) {
                if (! data || typeof data !== 'object') return [];

                return Object.entries(data)
                    .filter(([, value]) => value !== null && value !== '' && value !== undefined)
                    .map(([label, value]) => ({ label, value }));
            }

            // Botón "Verificar Seguro" (modo quick, sin person_id).
            // Emite el resultado por el $emitter para que v-insurance-result lo pinte.
            app.component('v-insurance-quick', {
                props: ['verifyUrl'],
                data() {
                    return { loading: false };
                },
                mounted() {
                    // Permite que el autocompletar de SMD dispare la verificación de seguro
                    // (para que "Estado de seguro" se rellene solo).
                    window.__odkVerifyInsurance = () => this.verify();
                },
                beforeUnmount() {
                    if (window.__odkVerifyInsurance) delete window.__odkVerifyInsurance;
                },
                template: `
                    <button
                        type="button"
                        @click="verify"
                        :disabled="loading"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border max-lg:min-h-[44px] max-lg:text-sm border-orange-300 bg-orange-50 px-3 py-1.5 text-xs font-medium text-orange-700 transition-colors duration-150 hover:bg-orange-100 focus:outline-none focus:ring-2 focus:ring-orange-300 disabled:opacity-50 dark:border-orange-700 dark:bg-orange-900/30 dark:text-orange-300"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
                        </svg>
                        <span v-if="loading">Verificando...</span>
                        <span v-else>Verificar Seguro</span>
                    </button>
                `,
                methods: {
                    async verify() {
                        const ci     = document.querySelector('[name="ci_paciente"]')?.value?.trim();

                        /**
                         * seguro_paciente es multiselect: el componente de chips renderiza
                         * inputs ocultos `seguro_paciente[]`. Se verifican TODOS los
                         * elegidos; el backend devuelve uno por aseguradora (`results`).
                         */
                        const seguros = [...document.querySelectorAll('[name="seguro_paciente[]"]')]
                            .map((input) => input.value)
                            .filter(Boolean);

                        if (! ci || ! seguros.length) {
                            this.$emitter.emit('add-flash', {
                                type:    'warning',
                                message: 'Completa el Carnet de Identidad y el Seguro antes de verificar.',
                            });
                            return;
                        }

                        this.loading = true;
                        this.$emitter.emit('insurance-result', null); // limpia panel previo mientras carga

                        try {
                            const { data } = await this.$axios.post(this.verifyUrl, {
                                ci_paciente:     ci,
                                seguro_paciente: seguros.length === 1 ? seguros[0] : seguros,
                            });

                            this.$emitter.emit('insurance-result', data);
                            this.applyEstadoSeguro(data.status);
                            this.$emitter.emit('add-flash', {
                                type:    insuranceFlashType[insuranceSeverity(data)] || 'info',
                                message: data.message,
                            });
                        } catch (err) {
                            const msg = err.response?.data?.message || 'Error al verificar el seguro.';
                            const result = { badge: 'danger', status: 'ERROR', message: msg, data: null, success: false };
                            this.$emitter.emit('insurance-result', result);
                            this.$emitter.emit('add-flash', { type: 'error', message: msg });
                        } finally {
                            this.loading = false;
                        }
                    },

                    // Auto-rellena el select "Estado de seguro" según el resultado de la
                    // verificación. Sigue siendo editable por si hay que corregirlo a mano.
                    applyEstadoSeguro(status) {
                        // status del backend -> etiqueta de la opción del select
                        const map = {
                            VIGENTE:       'Vigente',
                            EN_MORA:       'Pagos pendientes',
                            VENCIDO:       'Vencido',
                            NO_REGISTRADO: 'No registrado',
                            SIN_SEGURO:    'No registrado',
                        };
                        const label = map[status];
                        if (! label) return; // INDETERMINADO/ERROR: no tocar el campo

                        const select = document.querySelector('[name="estado_seguro_paciente"]');
                        if (! select) return;

                        const norm = (t) => (t || '').trim().toLowerCase();
                        const option = [...select.options].find((o) => norm(o.textContent) === norm(label));
                        if (! option) return;

                        select.value = option.value;
                        select.dispatchEvent(new Event('input',  { bubbles: true }));
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    },
                },
            });

            // Paletas de severidad. Se usan como estilos inline porque estas clases de
            // color de Tailwind se generan en runtime y no siempre están en el CSS
            // compilado (el purge no puede "verlas"). Inline garantiza el color correcto.
            const INSURANCE_PALETTE = {
                light: {
                    success: { bg: '#f0fdf4', border: '#bbf7d0', text: '#166534', dot: '#22c55e' },
                    warning: { bg: '#fefce8', border: '#fef08a', text: '#854d0e', dot: '#eab308' },
                    danger:  { bg: '#fef2f2', border: '#fecaca', text: '#991b1b', dot: '#ef4444' },
                    neutral: { bg: '#f9fafb', border: '#e5e7eb', text: '#374151', dot: '#9ca3af' },
                },
                dark: {
                    success: { bg: 'rgba(20,83,45,0.30)',  border: '#166534', text: '#86efac', dot: '#22c55e' },
                    warning: { bg: 'rgba(113,63,18,0.30)', border: '#854d0e', text: '#fde047', dot: '#eab308' },
                    danger:  { bg: 'rgba(127,29,29,0.30)', border: '#991b1b', text: '#fca5a5', dot: '#ef4444' },
                    neutral: { bg: '#1f2937',              border: '#374151', text: '#9ca3af', dot: '#9ca3af' },
                },
            };

            // Panel persistente con el resultado de la verificación de seguro.
            app.component('v-insurance-result', {
                data() {
                    return { result: null, isDark: false }; // { badge, status, message, data }
                },
                mounted() {
                    this.$emitter.on('insurance-result', (payload) => {
                        // Captura el tema en el momento de mostrar el resultado.
                        this.isDark = document.documentElement.classList.contains('dark');
                        this.result = payload;
                    });
                },
                computed: {
                    /**
                     * Un paciente puede tener varias aseguradoras y cada una se verifica
                     * por separado. Normalizamos a una lista de tarjetas: `results` cuando
                     * el backend devolvió el detalle, o el resultado suelto como tarjeta única.
                     */
                    cards() {
                        if (! this.result) return [];

                        const list = Array.isArray(this.result.results) && this.result.results.length
                            ? this.result.results
                            : [this.result];

                        const theme = this.isDark ? 'dark' : 'light';

                        return list.map((r, i) => ({
                            key:     r.seguro_id ?? i,
                            name:    r.seguro_name || null,
                            status:  r.status,
                            message: r.message,
                            palette: INSURANCE_PALETTE[theme][insuranceSeverity(r)],
                            details: insuranceDetails(r.data),
                        }));
                    },

                    // Con una sola aseguradora la cabecera repetiría la tarjeta: se omite.
                    showSummary() {
                        return this.cards.length > 1;
                    },

                    summaryPalette() {
                        const theme = this.isDark ? 'dark' : 'light';
                        return INSURANCE_PALETTE[theme][insuranceSeverity(this.result)];
                    },
                },
                methods: {
                    cardStyle(palette) {
                        return {
                            backgroundColor: palette.bg,
                            borderColor:     palette.border,
                            color:           palette.text,
                        };
                    },
                    dotStyle(palette) {
                        return { backgroundColor: palette.dot };
                    },
                },
                template: `
                    <div v-if="result" class="flex flex-col gap-2 transition-all duration-300">
                        <!-- Cabecera: veredicto agregado del paciente -->
                        <div
                            v-if="showSummary"
                            :style="cardStyle(summaryPalette)"
                            class="flex items-center justify-between gap-4 rounded-lg border px-4 py-2.5 text-sm"
                        >
                            <div class="flex items-center gap-2.5">
                                <span :style="dotStyle(summaryPalette)" class="h-2 w-2 shrink-0 rounded-full"></span>
                                <p class="font-semibold">
                                    @{{ cards.length }} seguros verificados - resultado general: @{{ result.status }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 text-xs opacity-60 transition hover:opacity-100 focus:outline-none"
                                @click="result = null"
                                aria-label="Cerrar"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                            </button>
                        </div>

                        <!-- Una tarjeta por aseguradora -->
                        <div
                            v-for="card in cards"
                            :key="card.key"
                            :style="cardStyle(card.palette)"
                            class="rounded-lg border px-4 py-3 text-sm"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-2.5">
                                    <span :style="dotStyle(card.palette)" class="mt-1.5 h-2 w-2 shrink-0 rounded-full"></span>
                                    <div>
                                        <p class="font-semibold">
                                            <span v-if="card.name">@{{ card.name }} - </span>@{{ card.status }}
                                        </p>
                                        <p class="mt-0.5 opacity-90">@{{ card.message }}</p>

                                        <dl v-if="card.details.length" class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
                                            <div v-for="item in card.details" :key="item.label" class="flex gap-1.5 text-xs">
                                                <dt class="font-medium opacity-70">@{{ item.label }}:</dt>
                                                <dd>@{{ item.value }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                                <button
                                    v-if="! showSummary"
                                    type="button"
                                    class="shrink-0 text-xs opacity-60 transition hover:opacity-100 focus:outline-none"
                                    @click="result = null"
                                    aria-label="Cerrar"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                `,
            });
        </script>
    @endPushOnce
</x-admin::layouts>
