<v-insurance-activity
    :person-id="{{ $person->id }}"
    current-insurance="{{ $currentInsurance ?? '' }}"
/>

@pushOnce('scripts')
    <script type="text/x-template" id="v-insurance-activity-template">
        <div>
            <!-- Botón de Acción -->
            <div 
                class="flex cursor-pointer h-[74px] w-[84px] flex-col items-center justify-center gap-1 rounded-lg border border-transparent bg-orange-200 font-medium text-orange-800 transition-all hover:border-orange-400"
                @click="initVerification"
            >
                <span class="icon-success text-2xl text-orange-600"></span>
                <p class="text-xs font-medium text-orange-600 dark:text-gray-300">Seguro</p>
            </div>

            <!-- Modal de Confirmación de Borrado de Caché -->
            <x-admin::modal ref="clearCacheConfirmModal">
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">Confirmar</p>
                </x-slot:header>

                <x-slot:content>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        ¿Estás seguro de que deseas borrar los datos guardados de esta verificación? Esto forzará una nueva consulta a la aseguradora.
                    </p>
                </x-slot:content>

                <x-slot:footer>
                    <div class="flex gap-2 justify-end">
                        <button class="secondary-button" @click="$refs.clearCacheConfirmModal.close()">Cancelar</button>
                        <button class="danger-button" @click="doClearCache(); $refs.clearCacheConfirmModal.close()">Confirmar</button>
                    </div>
                </x-slot:footer>
            </x-admin::modal>

            <!-- Modal de Resultados: un detalle por aseguradora -->
            <x-admin::modal ref="resultsModal">
                <x-slot:header>
                    <div class="flex items-center justify-between w-full pr-8">
                        <p class="text-lg font-bold text-gray-800 dark:text-white">
                            Resultado de Verificación
                        </p>

                        <button
                            class="text-xs text-red-600 hover:underline flex items-center gap-1"
                            @click="clearCache"
                            title="Limpiar datos guardados para forzar nueva consulta"
                        >
                            <span class="icon-delete text-sm"></span>
                            Borrar Caché
                        </button>
                    </div>
                </x-slot:header>

                <x-slot:content>
                    <div class="flex flex-col gap-2">
                        <p v-if="cards.length > 1" class="text-sm text-gray-600 dark:text-gray-400">
                            El paciente tiene @{{ cards.length }} seguros. Resultado general:
                            <span class="font-semibold">@{{ result?.status }}</span>
                        </p>

                        <div
                            v-for="card in cards"
                            :key="card.key"
                            :style="cardStyle(card.palette)"
                            class="rounded-lg border px-4 py-3 text-sm"
                        >
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
                        </div>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <div class="flex justify-end gap-2">
                        <button class="secondary-button" @click="$refs.resultsModal.close()">Cerrar</button>
                        <button class="primary-button" @click="openCapture">Editar seguros</button>
                    </div>
                </x-slot:footer>
            </x-admin::modal>

            <!-- Modal de Captura de Datos -->
            <x-admin::modal ref="insuranceModal">
                <x-slot:header>
                    <div class="flex items-center justify-between w-full pr-8">
                        <p class="text-lg font-bold text-gray-800 dark:text-white">Verificación de Seguro</p>
                        
                        <button 
                            v-if="status !== 'CAMPOS_VACIOS'"
                            class="text-xs text-red-600 hover:underline flex items-center gap-1"
                            @click="clearCache"
                            title="Limpiar datos guardados para forzar nueva consulta"
                        >
                            <span class="icon-delete text-sm"></span>
                            Borrar Caché
                        </button>
                    </div>
                </x-slot:header>

                <x-slot:content>
                    <div class="flex flex-col gap-4">
                        <p v-if="status === 'CAMPOS_VACIOS'" class="text-sm text-gray-600 dark:text-gray-400">
                            Faltan datos para realizar la verificación. Por favor, complétalos a continuación:
                        </p>

                        <!-- CI -->
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">Carnet de Identidad</x-admin::form.control-group.label>
                            <input 
                                type="text" 
                                v-model="formData.ci_paciente"
                                class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                placeholder="Ej: 5856583"
                            >
                        </x-admin::form.control-group>

                        <!-- Seguros: el paciente puede tener más de uno -->
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">Empresas de Seguro</x-admin::form.control-group.label>

                            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                                Marca todas las que correspondan. Se verificará cada una por separado.
                            </p>

                            <div class="flex max-h-48 flex-col gap-1 overflow-y-auto rounded-md border p-2 dark:border-gray-800">
                                <label
                                    v-for="opt in options"
                                    :key="opt.id"
                                    class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                                >
                                    <input
                                        type="checkbox"
                                        :value="opt.id"
                                        v-model="formData.seguro_paciente"
                                        class="h-4 w-4 cursor-pointer rounded border-gray-300"
                                    >
                                    @{{ opt.name }}
                                </label>
                            </div>
                        </x-admin::form.control-group>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <div class="flex gap-2 justify-end">
                        <button class="secondary-button" @click="$refs.insuranceModal.toggle()">Cancelar</button>
                        <button
                            class="primary-button"
                            @click="saveAndUpdate"
                            :disabled="isSaving || !formData.ci_paciente || !formData.seguro_paciente.length"
                        >
                            <span v-if="isSaving">Guardando...</span>
                            <span v-else>Guardar y Verificar</span>
                        </button>
                    </div>
                </x-slot:footer>
            </x-admin::modal>
        </div>
    </script>

    <script type="module">
        /**
         * Paletas de severidad. Inline y no como clases Tailwind: estas clases se
         * eligen en runtime y el purge no las "ve", así que no llegan al CSS de prod.
         */
        const VERIFY_PALETTE = {
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

        function verifySeverity(result) {
            if (! result) return 'neutral';
            if (result.badge) return result.badge;
            return result.success ? 'neutral' : 'warning';
        }

        app.component('v-insurance-activity', {
            template: '#v-insurance-activity-template',
            props: ['personId', 'currentInsurance'],
            data() {
                return {
                    isLoading: false,
                    isSaving: false,
                    status: null,
                    options: [],
                    formData: {
                        ci_paciente: '',
                        // Multiselect: el paciente puede tener varias aseguradoras.
                        seguro_paciente: []
                    },
                    result: null,
                    isDark: false,
                    localInsuranceLabel: this.currentInsurance,
                    debounceTimer: null
                }
            },

            computed: {
                /**
                 * Normaliza la respuesta a una lista de tarjetas: `results` trae el detalle
                 * por aseguradora; si no viene, el resultado suelto es la tarjeta única.
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
                        palette: VERIFY_PALETTE[theme][verifySeverity(r)],
                        details: (r.data && typeof r.data === 'object')
                            ? Object.entries(r.data)
                                .filter(([, v]) => v !== null && v !== '' && v !== undefined)
                                .map(([label, value]) => ({ label, value }))
                            : [],
                    }));
                },
            },

            mounted() {
                // El bloque «Cobertura» de la ficha usa este mismo flujo (modales incluidos).
                this.$emitter.on('cobertura:verificar', () => this.initVerification());
                this.$emitter.on('cobertura:editar', () => this.openCapture());

                // Escuchar cambios en atributos globales para validación en tiempo real
                this.$emitter.on('attribute-updated', (data) => {
                    if (data.name === 'seguro_paciente') {
                        this.handleInsuranceChange(data.label);
                    }
                });
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

                // Muestra el detalle por aseguradora en vez de un flash de una línea.
                showResults(data) {
                    this.isDark = document.documentElement.classList.contains('dark');
                    this.result = data;
                    this.$refs.resultsModal.open();

                    // Repinta el panel de seguros de la ficha sin recargar la página.
                    this.$emitter.emit('insurance-verified', data);
                },

                /**
                 * Abre la captura precargando lo que el paciente ya tiene, para que
                 * editar seguros no obligue a re-marcarlos todos desde cero.
                 */
                openCapture() {
                    this.$refs.resultsModal.close();

                    this.$axios.get("{{ route('admin.contacts.persons.insurance_options') }}")
                        .then(res => {
                            this.options = res.data.options ?? res.data;
                        })
                        .catch(() => {});

                    this.$axios.get("{{ route('admin.contacts.persons.insurance_status', $person->id) }}")
                        .then(res => {
                            this.formData.ci_paciente = res.data.ci ?? '';
                            this.formData.seguro_paciente = (res.data.seguros ?? []).map(s => s.id);
                        })
                        .finally(() => {
                            this.$refs.insuranceModal.open();
                        });
                },

                // Mapea el badge del backend (success|warning|danger|null) al tipo de flash.
                // Fallback: si no hay badge, usar success/warning según el flag `success`.
                flashTypeFromBadge(data) {
                    const map = { success: 'success', warning: 'warning', danger: 'error' };
                    if (data && data.badge && map[data.badge]) return map[data.badge];
                    return data && data.success ? 'info' : 'warning';
                },

                // Auto-rellena el select "Estado de seguro" según el resultado (editable).
                applyEstadoSeguro(status) {
                    const map = {
                        VIGENTE:       'Vigente',
                        EN_MORA:       'Pagos pendientes',
                        VENCIDO:       'Vencido',
                        NO_REGISTRADO: 'No registrado',
                        SIN_SEGURO:    'No registrado',
                    };
                    const label = map[status];
                    if (! label) return;

                    const select = document.querySelector('[name="estado_seguro_paciente"]');
                    if (! select) return;

                    const norm = (t) => (t || '').trim().toLowerCase();
                    const option = [...select.options].find((o) => norm(o.textContent) === norm(label));
                    if (! option) return;

                    select.value = option.value;
                    select.dispatchEvent(new Event('input',  { bubbles: true }));
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                },

                handleInsuranceChange(newLabel) {
                    this.localInsuranceLabel = newLabel;
                    
                    if (this.debounceTimer) clearTimeout(this.debounceTimer);

                    this.debounceTimer = setTimeout(() => {
                        this.validateInsuranceRealtime(newLabel);
                    }, 500); // 500ms debounce
                },

                validateInsuranceRealtime(label) {
                    if (!label) return;

                    const cleanLabel = label.toLowerCase().trim();

                    // Regla 1: Paciente sin seguro
                    if (cleanLabel === 'no tiene') {
                        this.$emitter.emit('add-flash', { 
                            type: 'warning', 
                            message: 'Aviso: El paciente no tiene seguro. Se aplicarán tarifas de cliente particular.' 
                        });
                        return;
                    }

                    // Regla 2: Seguros con convenios específicos (Ejemplo de regla de negocio)
                    if (cleanLabel.includes('alianza')) {
                        this.$emitter.emit('add-flash', { 
                            type: 'info', 
                            message: 'Validación: Alianza requiere aprobación previa para tratamientos mayores.' 
                        });
                    }

                    // Disparar verificación automática si es una aseguradora válida
                    this.initVerification(true);
                },

                initVerification(isAuto = false) {
                    if (this.isLoading) return;

                    // Validación local: Si el seguro es "No tiene", no llamamos al backend
                    if (this.localInsuranceLabel && this.localInsuranceLabel.toLowerCase() === 'no tiene') {
                        if (!isAuto) {
                            this.$emitter.emit('add-flash', { 
                                type: 'warning', 
                                message: 'El paciente no cuenta con un seguro registrado en su perfil.' 
                            });
                        }
                        return;
                    }
                    
                    this.isLoading = true;
                    this.$axios.post("{{ route('admin.contacts.persons.verify_insurance', $person->id) }}")
                        .then(res => {
                            // La severidad la define el backend vía `badge` (success|warning|danger|null).
                            // No re-inferir por status para no mostrar, p.ej., un seguro VENCIDO en verde.
                            const type = this.flashTypeFromBadge(res.data);
                            this.$emitter.emit('add-flash', { type: type, message: res.data.message });

                            // El detalle por aseguradora no cabe en un flash: va al modal.
                            this.showResults(res.data);

                            this.applyEstadoSeguro(res.data.status);

                            const statuses = (res.data.results ?? [res.data]).map(r => r.status);

                            if (statuses.some(s => ['VIGENTE', 'EN_MORA'].includes(s))) {
                                this.$emitter.emit('refresh-activities');
                            }
                        })
                        .catch(err => {
                            if (err.response && err.response.status === 422) {
                                this.status = 'CAMPOS_VACIOS';
                                this.options = err.response.data.options;
                                this.$refs.insuranceModal.toggle();
                            } else if (!isAuto) {
                                this.$emitter.emit('add-flash', { 
                                    type: 'error', 
                                    message: err.response?.data?.message || 'Error al conectar con n8n.' 
                                });
                            }
                        })
                        .finally(() => {
                            this.isLoading = false;
                        });
                },

                saveAndUpdate() {
                    this.isSaving = true;
                    this.$axios.post("{{ route('admin.contacts.persons.update_insurance', $person->id) }}", this.formData)
                        .then(res => {
                            const count = res.data.results?.length ?? 1;

                            this.$emitter.emit('add-flash', {
                                type:    'success',
                                message: `Datos actualizados y ${count} seguro(s) verificado(s).`,
                            });

                            this.$refs.insuranceModal.close();
                            // El CI pudo cambiar en la captura: «Cobertura» lo muestra.
                            this.showResults({ ...res.data, ci: this.formData.ci_paciente });
                            this.$emitter.emit('refresh-activities');
                        })
                        .catch(err => {
                            this.$emitter.emit('add-flash', { 
                                type: 'error', 
                                message: err.response?.data?.message || 'Error al actualizar datos.' 
                            });
                        })
                        .finally(() => {
                            this.isSaving = false;
                        });
                },

                clearCache() {
                    this.$refs.clearCacheConfirmModal.open();
                },

                doClearCache() {
                    this.$axios.post("{{ route('admin.contacts.persons.clear_insurance_cache', $person->id) }}")
                        .then(res => {
                            this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                            this.$refs.resultsModal.close();
                            this.initVerification();
                        })
                        .catch(err => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: 'No se pudo limpiar la caché.'
                            });
                        });
                }
            }
        });
    </script>
@endPushOnce
