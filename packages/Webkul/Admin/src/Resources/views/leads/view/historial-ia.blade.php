<div class="p-4">
    <v-historial-ia
        :lead='@json([
            "person" => [
                "email" => ($lead->person?->emails[0]["value"] ?? ($lead->person?->email ?? null))
            ],
            "title" => $lead->title,
        ])'
    />

</div>

@pushOnce('scripts')
    <script type="text/x-template" id="v-historial-ia-template">
        <div class="flex items-center justify-between border-b border-gray-200 mb-2 pb-4 dark:border-gray-800">
            <div class="flex flex-col">
                <div class="text-base font-semibold text-gray-800 dark:text-gray-200">Historial IA</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">@{{ leadName }} · @{{ leadEmail ?? '-' }} </div>
            </div>
        </div>

        <div class="">
            <div v-if="error" class="rounded-md border border-red-300 bg-red-50 p-2 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300">@{{ error }}</div>

            <div v-if="isLoading" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <x-admin::spinner />
                <span>Cargando historial…</span>
            </div>

            <div v-else ref="chatContainer" class="flex h-[420px] flex-col gap-3 overflow-y-auto">
                <div v-if="!pairedRows.length" class="text-sm text-gray-600 dark:text-gray-300">Sin mensajes disponibles.</div>

                <template v-for="(row, idx) in pairedRows" :key="'row-' + idx">
                    <div class="flex gap-3 flex-col">
                        <div v-if="row.user" class="w-full flex justify-start user-card-box">
                            <div class="user-card rounded-md border border-gray-200 p-2 text-sm dark:border-gray-800 bg-gray-50 w-1/2">
                                <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">Usuario</div>
                                <div v-if="row.user?.fecha" class="text-[11px] text-gray-500 dark:text-gray-400">@{{ row.user.fecha }}</div>
                                <div class="mt-1 break-words text-gray-800 dark:text-gray-200" v-html="formatMessage(row.user?.mensaje ?? row.user?.raw ?? '')"></div>
                            </div>
                        </div>

                        <div v-if="row.ia" class="w-full flex justify-end ia-card-box">
                            <div class="ia-card rounded-md border border-gray-200 p-2 text-sm dark:border-gray-800 dark:text-white w-1/2">
                                <div class="text-xs font-semibold text-gray-700 dark:text-white">IA</div>
                                <div class="mt-1 break-words text-gray-800 dark:text-white" v-html="formatMessage(row.ia?.mensaje ?? row.ia?.raw ?? '')"></div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

</script>

    <script type="module">
        app.component('v-historial-ia', {
            template: '#v-historial-ia-template',
            props: {
                lead: {
                    type: Object,
                    required: true
                }
            },
            data() {
                return {
                    messages: [],
                    isLoading: false,
                    error: null,
                };
            },
            computed: {
                leadName() {
                    return this.lead?.person?.name ?? this.lead?.title ?? 'Lead';
                },
                leadEmail() {
                    const p = this.lead?.person;
                    if (!p) return null;
                    if (Array.isArray(p.emails) && p.emails.length) {
                        const first = p.emails[0];
                        if (typeof first === 'string') return first;
                        if (typeof first?.value === 'string') return first.value;
                        if (typeof first?.email === 'string') return first.email;
                    }
                    if (typeof p.email === 'string') return p.email;
                    return null;
                },
                endpoint() {
                    return "{{ url('api/public/chat-history') }}";
                },
                pairedRows() {
                    const rows = [];
                    let pending = null;
                    const msgs = this.messages;
                    for (let i = 0; i < msgs.length; i++) {
                        const item = msgs[i];
                        const t = item.type ?? this.detectType(item.raw ?? '');
                        if (t === 'human') {
                            if (pending && !pending.user) {
                                pending.user = item;
                                rows.push(pending);
                                pending = null;
                            } else {
                                pending = {
                                    user: item,
                                    ia: null
                                };
                                if (msgs[i + 1] && (msgs[i + 1].type ?? this.detectType(msgs[i + 1].raw ?? '')) ===
                                    'ai') {
                                    pending.ia = msgs[i + 1];
                                    rows.push(pending);
                                    pending = null;
                                    i++;
                                }
                            }
                        } else {
                            if (pending && !pending.ia) {
                                pending.ia = item;
                                rows.push(pending);
                                pending = null;
                            } else {
                                pending = {
                                    user: null,
                                    ia: item
                                };
                                if (msgs[i + 1] && (msgs[i + 1].type ?? this.detectType(msgs[i + 1].raw ?? '')) ===
                                    'human') {
                                    pending.user = msgs[i + 1];
                                    rows.push(pending);
                                    pending = null;
                                    i++;
                                }
                            }
                        }
                    }
                    if (pending) rows.push(pending);
                    return rows;
                },
            },
            mounted() {
                this.fetchHistory();
            },
            methods: {
                fetchHistory() {
                    if (!this.leadEmail) {
                        this.error = 'No se encontró email del lead.';
                        return;
                    }
                    this.isLoading = true;
                    this.error = null;
                    this.$axios.get(this.endpoint, {
                            params: {
                                email: this.leadEmail
                            }
                        })
                        .then(res => {
                            const raw = res.data?.messages ?? [];
                            this.messages = this.normalizeMessages(raw);
                            this.$nextTick(() => {
                                const c = this.$refs.chatContainer;
                                if (c) c.scrollTop = c.scrollHeight;
                            });
                        })
                        .catch(() => {
                            this.error = 'No se pudo obtener el historial.';
                        })
                        .finally(() => {
                            this.isLoading = false;
                        });
                },
                formatMessage(s) {
                    if (!s) return '';
                    const str = String(s)
                        .replace(/\r\n/g, '\n')
                        .replace(/\r/g, '\n');
                    const safe = str
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                    return safe.replace(/\n/g, '<br>');
                },
                normalizeMessages(rawList) {
                    const out = [];
                    for (const item of rawList) {
                        let raw = typeof item === 'string' ? item : (item ? JSON.stringify(item) : '');
                        let content = raw;
                        let type = this.detectType(raw);

                        try {
                            const j = JSON.parse(raw);
                            if (j && typeof j === 'object') {
                                // Extraer contenido inicial (puede ser el JSON anidado en AI)
                                let extracted = j.content ?? j.text ?? j.message ?? raw;
                                
                                // Si es AI y el contenido parece un JSON anidado, intentar parsearlo
                                if (type === 'ai' && typeof extracted === 'string' && extracted.trim().startsWith('{')) {
                                    try {
                                        const inner = JSON.parse(extracted);
                                        content = inner?.output?.mensaje ?? inner?.mensaje ?? extracted;
                                    } catch (e) {
                                        content = extracted;
                                    }
                                } else {
                                    content = extracted;
                                }
                            }
                        } catch (e) {}

                        // Normalizar saltos de línea para que se respeten en la vista
                        if (typeof content === 'string') {
                            content = content
                                .replace(/\r\n/g, '\n')
                                .replace(/\r/g, '\n')
                                .replace(/\\r\\n/g, '\n')
                                .replace(/\\n/g, '\n')
                                .replace(/<br\s*\/>?/gi, '\n');
                        }

                        let fecha = null;
                        let mensaje = null;
                        const mFecha = content.match(/Hoy:\s*([^\n]+)/i);
                        if (mFecha) fecha = mFecha[1].trim();
                        
                        const ix = content.toLowerCase().indexOf('mensaje del usuario:');
                        if (ix >= 0) {
                            mensaje = content.substring(ix + 'Mensaje del Usuario:'.length).trim();
                        } else {
                            mensaje = content;
                        }

                        out.push({
                            raw: raw,
                            fecha,
                            mensaje,
                            type: type
                        });
                    }
                    return out;
                },
                detectType(raw) {
                    try {
                        const j = JSON.parse(raw);
                        const t = (typeof j?.type === 'string') ? j.type.toLowerCase() : '';
                        if (t === 'human' || t === 'user' || t === 'lead') return 'human';
                        if (t === 'ai' || t === 'assistant' || t === 'bot') return 'ai';
                    } catch (e) {}
                    if (raw.toLowerCase().includes('mensaje del usuario:')) return 'human';
                    return 'ai';
                },
            },
        });
    </script>
@endPushOnce
