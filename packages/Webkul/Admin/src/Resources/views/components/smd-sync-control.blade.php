{{--
    Registro del componente Vue <v-smd-sync-control>.
    Sigue el patrón de doctor-day-calendar: define el template y el componente
    directamente (sin @pushOnce) para poder incluirse fuera de un text/x-template.
    El tag <v-smd-sync-control></v-smd-sync-control> se coloca donde va el control.
--}}
<script type="text/x-template" id="v-smd-sync-control-template">
    <!-- Sin bordes grises; en el celular textos cortos para que no se partan. -->
    <div class="ssc-wrap">
        <!-- Toggle Pausar / Reanudar sincronización automática -->
        <button
            type="button"
            class="ssc-btn"
            :class="paused ? 'ssc-btn--paused' : 'ssc-btn--on'"
            :disabled="pauseLoading"
            :title="paused
                ? '@lang('admin::app.activities.index.smd-sync.resume-hint')'
                : '@lang('admin::app.activities.index.smd-sync.pause-hint')'"
            @click="togglePause"
        >
            <span class="ssc-dot" :class="paused ? 'ssc-dot--paused' : 'animate-pulse ssc-dot--on'"></span>

            <template v-if="! paused">
                <span class="ssc-long">@lang('admin::app.activities.index.smd-sync.pause')</span>
                <span class="ssc-short">Pausar</span>
            </template>
            <template v-else>
                <span class="ssc-long">@lang('admin::app.activities.index.smd-sync.resume')</span>
                <span class="ssc-short">Reanudar</span>
            </template>
        </button>

        <!-- Sincronizar ahora (manual, siempre disponible) -->
        <button
            type="button"
            class="ssc-btn ssc-btn--sync"
            :disabled="isSyncing"
            @click="syncNow"
        >
            <span
                v-if="isSyncing"
                class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"
            ></span>

            <template v-if="! isSyncing">
                <span class="ssc-long">@lang('admin::app.activities.index.smd-sync.sync-now')</span>
                <span class="ssc-short">Sincronizar</span>
            </template>
            <span v-else>@lang('admin::app.activities.index.smd-sync.syncing')</span>
        </button>
    </div>
</script>

<style>
    .ssc-wrap { display: flex; align-items: center; gap: 8px; }
    .ssc-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        height: 36px; padding: 0 12px; border: 0; border-radius: 8px;
        font-size: 14px; font-weight: 600; white-space: nowrap; transition: background-color .15s;
    }
    .ssc-btn:disabled { opacity: .6; }
    .ssc-btn:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .ssc-btn--on { background: #f0fdf4; color: #15803d; }
    .ssc-btn--on:hover { background: #dcfce7; }
    .ssc-btn--paused { background: #f3f4f6; color: #4b5563; }
    .ssc-btn--paused:hover { background: #e5e7eb; }
    .ssc-btn--sync { background: #e6f4f5; color: #0e7490; }
    .ssc-btn--sync:hover { background: #cfeaec; }
    .ssc-dot { display: inline-block; width: 8px; height: 8px; border-radius: 9999px; }
    .ssc-dot--on { background: #22c55e; }
    .ssc-dot--paused { background: #9ca3af; }
    .ssc-short { display: none; }

    .dark .ssc-btn--on { background: rgba(34, 197, 94, .12); color: #4ade80; }
    .dark .ssc-btn--paused { background: #1f2937; color: #9ca3af; }
    .dark .ssc-btn--sync { background: rgba(14, 116, 144, .2); color: #67e8f9; }

    @media (max-width: 1023px) {
        .ssc-wrap { width: 100%; }
        .ssc-wrap .ssc-btn { flex: 1 1 0; min-width: 0; }
        .ssc-long { display: none; }
        .ssc-short { display: inline; }
    }
    /* Escritorio: como los botones del admin (borde fino); el relleno es del celular. */
    @media (min-width: 1024px) {
        .ssc-btn { height: 36px; border: 1px solid; border-radius: 6px; background: #fff; }
        .ssc-btn--on { border-color: #86efac; color: #15803d; background: #fff; }
        .ssc-btn--on:hover { border-color: #4ade80; background: #fff; }
        .ssc-btn--paused { border-color: #d1d5db; color: #6b7280; background: #fff; }
        .ssc-btn--paused:hover { border-color: #9ca3af; background: #fff; }
        .ssc-btn--sync { border-color: #0e7490; color: #0e7490; background: #fff; }
        .ssc-btn--sync:hover { background: #ecfeff; }
        .dark .ssc-btn { background: #111827; }
        .dark .ssc-btn--on { border-color: #166534; color: #4ade80; }
        .dark .ssc-btn--paused { border-color: #374151; color: #9ca3af; }
        .dark .ssc-btn--sync { border-color: #155e75; color: #67e8f9; }
    }
</style>

<script type="module">
    app.component('v-smd-sync-control', {
        template: '#v-smd-sync-control-template',

        data() {
            return {
                isSyncing: false,
                pauseLoading: false,
                paused: false,

                syncUrl: "{{ route('admin.activities.smd.sync') }}",
                statusUrl: "{{ route('admin.activities.smd.sync.status') }}",
                pauseUrl: "{{ route('admin.activities.smd.pause') }}",
            };
        },

        mounted() {
            this.$axios.get(this.statusUrl)
                .then(({ data }) => {
                    this.paused = !! data.paused;

                    if (data.state === 'running') {
                        this.isSyncing = true;
                        this.pollSyncStatus();
                    } else if (! this.paused) {
                        // Auto-arranque al entrar (el backend respeta cooldown).
                        this.syncNow(true);
                    }
                })
                .catch(() => {});
        },

        methods: {
            /**
             * Dispara la sincronización. auto=true → arranque al entrar
             * (silencioso, respeta pausa/cooldown server-side).
             */
            syncNow(auto = false) {
                if (this.isSyncing) {
                    return;
                }

                this.isSyncing = true;

                this.$axios.post(this.syncUrl, { auto: auto === true })
                    .then(({ data }) => {
                        if (data.skipped) {
                            this.isSyncing = false;
                            return;
                        }

                        if (! auto) {
                            this.$emitter.emit('add-flash', { type: 'info', message: data.message });
                        }

                        this.pollSyncStatus();
                    })
                    .catch(error => {
                        const status = error.response?.status;
                        this.isSyncing = false;

                        if (status === 409) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: error.response.data.message });
                            this.isSyncing = true;
                            this.pollSyncStatus();
                        } else if (! auto) {
                            this.$emitter.emit('add-flash', {
                                type: 'warning',
                                message: 'No se pudo iniciar la sincronización con ShareMeData.',
                            });
                        }
                    });
            },

            /**
             * Pausa o reanuda la sincronización automática.
             */
            togglePause() {
                this.pauseLoading = true;

                this.$axios.post(this.pauseUrl, { paused: ! this.paused })
                    .then(({ data }) => {
                        this.paused = !! data.paused;
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                    })
                    .catch(() => {
                        this.$emitter.emit('add-flash', {
                            type: 'warning',
                            message: 'No se pudo cambiar el estado de la sincronización.',
                        });
                    })
                    .finally(() => {
                        this.pauseLoading = false;
                    });
            },

            /**
             * Sigue el estado del sync por polling hasta que finalice.
             */
            pollSyncStatus() {
                const startedAt = Date.now();
                const MAX_WAIT = 120000;
                const INTERVAL = 4000;

                const poll = () => {
                    this.$axios.get(this.statusUrl)
                        .then(({ data }) => {
                            this.paused = !! data.paused;

                            if (data.state === 'running' && (Date.now() - startedAt) < MAX_WAIT) {
                                setTimeout(poll, INTERVAL);
                                return;
                            }

                            this.isSyncing = false;

                            if (data.state === 'done') {
                                this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                                this.$emitter.emit('smd-sync-completed');
                            } else if (data.state === 'failed') {
                                this.$emitter.emit('add-flash', { type: 'error', message: data.message });
                            }
                        })
                        .catch(() => {
                            this.isSyncing = false;
                        });
                };

                poll();
            },
        },
    });
</script>
