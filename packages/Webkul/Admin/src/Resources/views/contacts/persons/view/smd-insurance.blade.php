<v-smd-insurance-tab
    :urls="{
        smdData:         '{{ $smdUrl }}',
        link:            '{{ $linkUrl }}',
        sync:            '{{ $syncUrl }}',
        search:          '{{ $searchUrl }}',
        insuranceStatus: '{{ $insuranceStatusUrl }}',
        verify:          '{{ $verifyUrl }}',
        clearCache:      '{{ $clearCacheUrl }}',
    }"
/>

@pushOnce('scripts')
    <script type="text/x-template" id="v-smd-insurance-tab-template">
        <div class="p-4 flex flex-col gap-4">
            <!-- Card Seguro médico -->
            <div class="rounded-xl border dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="px-5 py-3 border-b dark:border-gray-700 flex items-center justify-between">
                    <span class="text-sm font-semibold dark:text-white">Seguro médico</span>
                    <span
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                        :class="{
                            'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': insurance.status === 'VIGENTE',
                            'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400': insurance.status === 'EN_MORA',
                            'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': insurance.status === 'NO_ENCONTRADO',
                            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400': !insurance.status || !['VIGENTE','EN_MORA','NO_ENCONTRADO'].includes(insurance.status),
                        }"
                    >
                        @{{ insurance.status || 'Sin verificar' }}
                    </span>
                </div>
                <div class="px-5 py-4 flex flex-col gap-3">
                    <template v-if="insurance.loading">
                        <div class="flex flex-col gap-2">
                            <div class="h-4 bg-gray-100 dark:bg-gray-800 rounded animate-pulse w-2/3"></div>
                            <div class="h-4 bg-gray-100 dark:bg-gray-800 rounded animate-pulse w-1/2"></div>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex flex-col gap-2">
                            <div class="flex gap-2 text-sm">
                                <span class="text-gray-500 dark:text-gray-400 w-32">Aseguradora</span>
                                <span class="dark:text-gray-200">@{{ insurance.seguro_label || '—' }}</span>
                            </div>
                            <div class="flex gap-2 text-sm">
                                <span class="text-gray-500 dark:text-gray-400 w-32">C.I. Paciente</span>
                                <span class="dark:text-gray-200">@{{ insurance.ci || '—' }}</span>
                            </div>
                            <div v-if="insurance.checked_at" class="flex gap-2 text-sm">
                                <span class="text-gray-500 dark:text-gray-400 w-32">Verificado</span>
                                <span class="text-xs text-gray-400 dark:text-gray-500">@{{ insurance.checkedAgo }}</span>
                            </div>
                            <div v-if="insurance.message" class="text-xs px-3 py-2 rounded bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
                                @{{ insurance.message }}
                            </div>
                        </div>
                    </template>
                    <div class="flex items-center gap-3 pt-1">
                        <button
                            type="button"
                            class="text-sm px-4 py-2 rounded-lg primary-button text-white font-medium flex items-center gap-2 disabled:opacity-50"
                            @click="verifyInsurance"
                            :disabled="insurance.verifying"
                        >
                            <x-admin::spinner v-if="insurance.verifying" class="w-3.5 h-3.5" />
                            @{{ insurance.verifying ? 'Verificando...' : 'Verificar cobertura' }}
                        </button>
                        <button
                            v-if="insurance.status"
                            type="button"
                            class="text-xs text-red-500 hover:text-red-700 dark:text-red-400"
                            @click="clearCache"
                        >
                            Borrar caché
                        </button>
                    </div>
                </div>
            </div>

            <!-- Card Estado en SMD -->
            <div class="rounded-xl border dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div class="px-5 py-3 border-b dark:border-gray-700 flex items-center justify-between">
                    <span class="text-sm font-semibold dark:text-white">Estado en SMD</span>
                    <span
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                        :class="{
                            'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': smd.linked,
                            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400': !smd.linked,
                        }"
                    >
                        @{{ smd.linked ? 'Vinculado' : 'No vinculado' }}
                    </span>
                </div>
                <div class="px-5 py-4 flex flex-col gap-3">
                    <template v-if="smd.loading">
                        <div class="flex flex-col gap-2">
                            <div class="h-4 bg-gray-100 dark:bg-gray-800 rounded animate-pulse w-3/4"></div>
                            <div class="h-4 bg-gray-100 dark:bg-gray-800 rounded animate-pulse w-1/2"></div>
                        </div>
                    </template>

                    <!-- VINCULADO -->
                    <template v-else-if="smd.linked">
                        <div class="flex flex-col gap-2">
                            <div class="flex gap-2 text-sm">
                                <span class="text-gray-500 dark:text-gray-400 w-32">ID en SMD</span>
                                <span class="font-mono text-xs text-gray-600 dark:text-gray-400">@{{ smd.smd_id }}</span>
                            </div>
                            <template v-if="!smd.stale">
                                <div class="flex gap-2 text-sm">
                                    <span class="text-gray-500 dark:text-gray-400 w-32">Nombre SMD</span>
                                    <span class="dark:text-gray-200">@{{ smd.name || '—' }}</span>
                                </div>
                                <div class="flex gap-2 text-sm">
                                    <span class="text-gray-500 dark:text-gray-400 w-32">Teléfono</span>
                                    <span class="dark:text-gray-200">@{{ smd.phone || '—' }}</span>
                                </div>
                                <div class="flex gap-2 text-sm">
                                    <span class="text-gray-500 dark:text-gray-400 w-32">Seguro SMD</span>
                                    <span class="dark:text-gray-200">@{{ smd.insurance || '—' }}</span>
                                </div>
                            </template>
                            <template v-else>
                                <div class="text-xs text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-900/20 rounded px-3 py-2">
                                    No se pudo cargar los datos de SMD. El paciente puede haber sido eliminado en SMD.
                                </div>
                            </template>
                            <div class="pt-1">
                                <button
                                    type="button"
                                    class="text-sm px-4 py-2 rounded-lg border dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-800 dark:text-gray-300 flex items-center gap-2 disabled:opacity-50"
                                    @click="reverifyLink"
                                    :disabled="smd.reverifying"
                                >
                                    <x-admin::spinner v-if="smd.reverifying" class="w-3.5 h-3.5" />
                                    Re-verificar vínculo
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- NO VINCULADO -->
                    <template v-else>
                        <div class="flex flex-col gap-3">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Este paciente no está vinculado a SMD.</p>
                            <button
                                type="button"
                                class="text-sm px-4 py-2 rounded-lg primary-button text-white font-medium flex items-center gap-2 w-fit disabled:opacity-50"
                                @click="syncSmd"
                                :disabled="smd.syncing"
                            >
                                <x-admin::spinner v-if="smd.syncing" class="w-3.5 h-3.5" />
                                @{{ smd.syncing ? 'Sincronizando...' : 'Sincronizar con SMD' }}
                            </button>
                            <div class="border dark:border-gray-700 rounded-lg p-3 flex flex-col gap-2">
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Buscar y vincular manualmente</div>
                                <div class="flex gap-2">
                                    <input
                                        type="text"
                                        class="flex-1 rounded border px-3 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
                                        v-model="smd.searchTerm"
                                        placeholder="Teléfono o C.I."
                                        @keyup.enter="searchSmd"
                                    />
                                    <button
                                        type="button"
                                        class="px-3 py-1.5 rounded border dark:border-gray-700 text-sm hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-1 disabled:opacity-50"
                                        @click="searchSmd"
                                        :disabled="smd.searching"
                                    >
                                        <x-admin::spinner v-if="smd.searching" class="w-3 h-3" />
                                        <span v-else class="icon-search text-sm"></span>
                                        Buscar
                                    </button>
                                </div>
                                <div v-if="smd.searchResults && smd.searchResults.length > 0" class="flex flex-col gap-1 mt-1">
                                    <div
                                        v-for="result in smd.searchResults"
                                        :key="result.smd_id"
                                        class="flex items-center justify-between rounded border dark:border-gray-700 px-3 py-2 text-sm"
                                    >
                                        <div>
                                            <span class="font-medium dark:text-gray-200">@{{ result.name }}</span>
                                            <span class="text-gray-400 text-xs ml-2">@{{ result.phone }}</span>
                                            <span v-if="result.ci" class="text-gray-400 text-xs ml-1">· CI: @{{ result.ci }}</span>
                                        </div>
                                        <button
                                            type="button"
                                            class="text-xs px-2 py-1 rounded primary-button text-white"
                                            @click="linkSmd(result.smd_id)"
                                        >
                                            Vincular
                                        </button>
                                    </div>
                                </div>
                                <div v-if="smd.searchMessage" class="text-xs text-gray-500 dark:text-gray-400">
                                    @{{ smd.searchMessage }}
                                </div>
                            </div>
                        </div>
                    </template>

                    <div v-if="smd.error" class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded px-3 py-2">
                        @{{ smd.error }}
                    </div>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-smd-insurance-tab', {
            template: '#v-smd-insurance-tab-template',

            props: {
                urls: {
                    type:     Object,
                    required: true,
                },
            },

            data() {
                return {
                    insurance: {
                        loading:   true,
                        verifying: false,
                        status:    null,
                        ci:        null,
                        seguro_id: null,
                        seguro_label: null,
                        checked_at:   null,
                        checkedAgo:   null,
                        message:      null,
                    },
                    smd: {
                        loading:      true,
                        linked:       false,
                        smd_id:       null,
                        stale:        false,
                        name:         null,
                        phone:        null,
                        insurance:    null,
                        syncing:      false,
                        reverifying:  false,
                        searching:    false,
                        searchTerm:   '',
                        searchResults: null,
                        searchMessage: null,
                        error:         null,
                    },
                };
            },

            mounted() {
                Promise.all([this.loadInsuranceStatus(), this.loadSmdData()]);
            },

            methods: {
                async loadInsuranceStatus() {
                    this.insurance.loading = true;
                    try {
                        const { data } = await this.$axios.get(this.urls.insuranceStatus);
                        this.insurance.ci          = data.ci;
                        this.insurance.seguro_id   = data.seguro_id;
                        this.insurance.seguro_label = data.seguro_label;
                        this.insurance.status      = data.status;
                        this.insurance.checked_at  = data.checked_at;
                        this.insurance.checkedAgo  = data.checked_at ? this.timeAgo(data.checked_at) : null;
                        this.insurance.message     = data.message || null;
                    } finally {
                        this.insurance.loading = false;
                    }
                },

                async loadSmdData() {
                    this.smd.loading = true;
                    try {
                        const { data } = await this.$axios.get(this.urls.smdData);
                        this.smd.linked    = data.linked   ?? false;
                        this.smd.smd_id    = data.smd_id   ?? null;
                        this.smd.stale     = data.stale    ?? false;
                        this.smd.name      = data.name     ?? null;
                        this.smd.phone     = data.phone    ?? null;
                        this.smd.insurance = data.insurance ?? null;
                    } finally {
                        this.smd.loading = false;
                    }
                },

                async verifyInsurance() {
                    this.insurance.verifying = true;
                    try {
                        const { data } = await this.$axios.post(this.urls.verify);
                        this.insurance.status     = data.status  ?? null;
                        this.insurance.message    = data.message ?? null;
                        this.insurance.checked_at = new Date().toISOString();
                        this.insurance.checkedAgo = 'hace un momento';
                        const type = data.status === 'VIGENTE' ? 'success' : (data.status === 'EN_MORA' ? 'warning' : 'error');
                        this.$emitter.emit('add-flash', { type, message: data.message });
                    } catch (err) {
                        const msg = err.response?.data?.message || 'Error al verificar seguro.';
                        this.$emitter.emit('add-flash', { type: 'error', message: msg });
                    } finally {
                        this.insurance.verifying = false;
                    }
                },

                async clearCache() {
                    try {
                        await this.$axios.post(this.urls.clearCache);
                        this.insurance.status     = null;
                        this.insurance.message    = null;
                        this.insurance.checked_at = null;
                        this.insurance.checkedAgo = null;
                        this.$emitter.emit('add-flash', { type: 'success', message: 'Caché de seguro limpiada.' });
                    } catch {
                        this.$emitter.emit('add-flash', { type: 'error', message: 'No se pudo limpiar la caché.' });
                    }
                },

                async syncSmd() {
                    this.smd.syncing = true;
                    this.smd.error   = null;
                    try {
                        const { data } = await this.$axios.post(this.urls.sync);
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message || 'Sincronizado correctamente.' });
                        await this.loadSmdData();
                    } catch (err) {
                        this.smd.error = err.response?.data?.message || 'Error al sincronizar con SMD.';
                    } finally {
                        this.smd.syncing = false;
                    }
                },

                async reverifyLink() {
                    this.smd.reverifying = true;
                    this.smd.error = null;
                    try {
                        await this.loadSmdData();
                    } finally {
                        this.smd.reverifying = false;
                    }
                },

                async searchSmd() {
                    if (! this.smd.searchTerm.trim()) return;
                    this.smd.searching    = true;
                    this.smd.searchResults = null;
                    this.smd.searchMessage = null;
                    try {
                        const { data } = await this.$axios.get(this.urls.search, {
                            params: { q: this.smd.searchTerm },
                        });
                        if (data.found) {
                            this.smd.searchResults = [{
                                smd_id: data.smd_id,
                                name:   data.name,
                                phone:  data.phone,
                                ci:     data.ci,
                            }];
                        } else {
                            this.smd.searchMessage = data.message || 'No encontrado en SMD.';
                        }
                    } catch {
                        this.smd.searchMessage = 'Error al buscar en SMD.';
                    } finally {
                        this.smd.searching = false;
                    }
                },

                async linkSmd(smdId) {
                    this.smd.error = null;
                    try {
                        const { data } = await this.$axios.post(this.urls.link, { smd_patient_id: smdId });
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message || 'Vinculado correctamente.' });
                        await this.loadSmdData();
                    } catch (err) {
                        this.smd.error = err.response?.data?.message || 'Error al vincular con SMD.';
                    }
                },

                timeAgo(iso) {
                    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
                    if (diff < 60)    return 'hace menos de un minuto';
                    if (diff < 3600)  return `hace ${Math.floor(diff / 60)} min`;
                    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
                    return `hace ${Math.floor(diff / 86400)} días`;
                },

            },
        });
    </script>
@endPushOnce
