<x-admin::layouts>
    <x-slot:title>
        WhatsApp
    </x-slot>

    <div class="px-4 py-3">
        <v-whatsapp-chat
            conversations-url="{{ route('admin.whatsapp.conversations') }}"
            link-person-url-base="{{ url(config('app.admin_path').'/whatsapp/conversations') }}"
            thread-url="{{ route('admin.whatsapp.thread') }}"
            send-url="{{ route('admin.whatsapp.send') }}"
            agent-url-base="{{ url(config('app.admin_path').'/whatsapp/conversations') }}"
            quick-replies-url="{{ route('admin.whatsapp.quick-replies.index') }}"
            person-url-base="{{ url(config('app.admin_path').'/whatsapp/persons') }}"
            products-url="{{ route('admin.whatsapp.products') }}"
            stages-url="{{ route('admin.whatsapp.stages') }}"
        >
            <div class="flex h-[calc(100vh-110px)] items-center justify-center rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <x-admin::spinner />
            </div>
        </v-whatsapp-chat>
    </div>

    @include('whatsapp::inbox-component')

    @pushOnce('scripts')
        <script type="text/x-template" id="v-whatsapp-chat-template">
            <div class="flex h-[calc(100vh-110px)] overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <!-- ────────── Conversation list ──────────
                     Mobile: master-detail — the list fills the screen until a
                     conversation is chosen; the thread replaces it with a back button. -->
                <div class="w-full flex-col border-r border-gray-200 dark:border-gray-800 lg:flex lg:w-[340px] lg:shrink-0"
                     :class="selected ? 'hidden' : 'flex'">
                    <!-- search + filters -->
                    <div class="flex flex-col gap-2 border-b border-gray-200 p-3 dark:border-gray-800">
                        <div class="relative">
                            <span class="icon-search absolute left-2.5 top-1/2 -translate-y-1/2 text-base text-gray-400"></span>
                            <input
                                v-model="search"
                                @input="debouncedLoad"
                                type="text"
                                placeholder="Buscar o empezar un chat"
                                class="w-full rounded-lg border border-transparent bg-gray-100 py-2 pl-8 pr-3 text-sm text-gray-800 transition-all focus:border-brandColor focus:bg-white focus:outline-none dark:bg-gray-800 dark:text-gray-200 dark:focus:bg-gray-900"
                            >
                        </div>

                        <div class="flex gap-1.5">
                            <button v-for="f in filters" :key="f.value" type="button"
                                    class="rounded-full px-3 py-1 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="filter === f.value
                                        ? 'bg-brandColor text-white'
                                        : 'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'"
                                    @click="filter = f.value; load()">
                                @{{ f.label }}
                            </button>
                        </div>

                        <!-- stage filter: chips from the default pipeline -->
                        <div v-if="stages.length" class="wa-scroll flex gap-1.5 overflow-x-auto pb-0.5">
                            <button type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="!stageFilter
                                        ? 'bg-sky-600 text-white'
                                        : 'bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-300'"
                                    @click="stageFilter = null; load()">
                                Todas las etapas
                            </button>
                            <button v-for="s in stages" :key="s" type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="stageFilter === s
                                        ? 'bg-sky-600 text-white'
                                        : 'bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-300'"
                                    @click="stageFilter = s; load()">
                                @{{ s }}
                            </button>
                        </div>
                    </div>

                    <!-- items -->
                    <div class="wa-scroll flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                        <div v-if="listLoading" class="flex justify-center py-12">
                            <x-admin::spinner />
                        </div>

                        <div v-else-if="!conversations.length" class="flex flex-col items-center gap-1 px-6 py-14 text-center">
                            <span class="icon-message text-3xl text-gray-300 dark:text-gray-600"></span>
                            <p class="text-sm text-gray-400">No hay conversaciones para este filtro</p>
                        </div>

                        <template v-else>
                            <div v-for="c in conversations" :key="c.id"
                                 class="group relative flex cursor-pointer items-center gap-3 px-3 py-3 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor dark:hover:bg-gray-800/60"
                                 :class="selected?.id === c.id ? 'bg-gray-100 dark:bg-gray-800' : ''"
                                 role="button"
                                 tabindex="0"
                                 @click="select(c)"
                                 @keydown.enter.prevent="select(c)">
                                <!-- selected accent -->
                                <span v-if="selected?.id === c.id" class="absolute inset-y-0 left-0 w-1 bg-brandColor"></span>

                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-medium"
                                     :class="avatarColor(c.name)">
                                    @{{ initials(c.name) }}
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">@{{ c.name }}</span>
                                        <span class="shrink-0 text-[11px]"
                                              :class="c.unread_count ? 'font-semibold text-brandColor' : 'text-gray-400'">
                                            @{{ relativeTime(c.last_message_at) }}
                                        </span>
                                    </div>

                                    <div class="mt-0.5 flex items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            <span v-if="c.ai_enabled" title="El agente IA atiende esta conversación"
                                                  class="shrink-0 rounded bg-emerald-100 px-1 text-[10px] font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">IA</span>
                                            <span class="truncate">@{{ c.preview || 'Sin mensajes' }}</span>
                                        </span>
                                        <span v-if="c.unread_count"
                                              class="flex h-[18px] min-w-[18px] shrink-0 items-center justify-center rounded-full bg-brandColor px-1 text-[10px] font-bold leading-none text-white">
                                            @{{ c.unread_count }}
                                        </span>
                                    </div>

                                    <div v-if="c.stage || (!c.person_id && !c.lead_id)" class="mt-1 flex items-center gap-1.5">
                                        <span v-if="c.stage" class="truncate rounded-full bg-sky-100 px-1.5 py-px text-[10px] font-medium text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">@{{ c.stage }}</span>
                                        <span v-if="!c.person_id && !c.lead_id" class="rounded bg-amber-50 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Sin asignar</span>
                                        <button v-if="!c.person_id && !c.lead_id" type="button"
                                                class="hidden rounded px-1.5 py-px text-[10px] font-medium text-brandColor hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor group-hover:inline-block dark:hover:bg-gray-800"
                                                title="Crear contacto en el CRM con este número"
                                                @click.stop="linkPerson(c)">
                                            + Crear contacto
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button v-if="page < lastPage" type="button"
                                    class="w-full py-3 text-center text-xs font-medium text-brandColor transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor dark:hover:bg-gray-800"
                                    @click="loadMore">
                                Cargar más conversaciones
                            </button>
                        </template>
                    </div>
                </div>

                <!-- ────────── Thread pane ────────── -->
                <div class="min-w-0 flex-1 flex-col lg:flex"
                     :class="selected ? 'flex' : 'hidden'">
                    <button v-if="selected" type="button"
                            aria-label="Volver a la lista de conversaciones"
                            class="flex items-center gap-1.5 border-b border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor lg:hidden dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-800"
                            @click="selected = null">
                        <span class="icon-left-arrow text-lg"></span> Conversaciones
                    </button>

                    <div class="min-h-0 flex-1">
                    <v-whatsapp-inbox
                        v-if="selected"
                        :key="selected.id"
                        :context="threadContext"
                        :use-mock="false"
                        :full-height="true"
                        :thread-url="threadUrl"
                        :send-url="sendUrl"
                        :agent-url-base="agentUrlBase"
                        :quick-replies-url="quickRepliesUrl"
                        :person-url-base="personUrlBase"
                        :products-url="productsUrl"
                    ></v-whatsapp-inbox>

                    <div v-else class="flex h-full flex-col items-center justify-center gap-3 bg-gray-50 dark:bg-gray-950">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <span class="icon-message text-3xl text-brandColor"></span>
                        </div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-300">WhatsApp del CRM</p>
                        <p class="max-w-xs text-center text-xs text-gray-400">Elegí una conversación de la izquierda para ver el historial y responder.</p>
                    </div>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-whatsapp-chat', {
                template: '#v-whatsapp-chat-template',

                props: {
                    conversationsUrl: { type: String, required: true },
                    linkPersonUrlBase: { type: String, required: true },
                    threadUrl: { type: String, required: true },
                    sendUrl: { type: String, required: true },
                    agentUrlBase: { type: String, required: true },
                    quickRepliesUrl: { type: String, required: true },
                    personUrlBase: { type: String, required: true },
                    productsUrl: { type: String, required: true },
                    stagesUrl: { type: String, required: true },
                },

                data() {
                    return {
                        conversations: [],
                        selected: null,
                        search: '',
                        filter: 'all',
                        filters: [
                            { value: 'all', label: 'Todas' },
                            { value: 'unread', label: 'No leídas' },
                            { value: 'unassigned', label: 'Sin asignar' },
                        ],
                        stages: [],
                        stageFilter: null,
                        page: 1,
                        lastPage: 1,
                        listLoading: false,
                        searchTimer: null,
                        pollTimer: null,
                    };
                },

                computed: {
                    // What the shared inbox component needs to load this thread.
                    threadContext() {
                        return {
                            conversationId: this.selected.id,
                            personId: this.selected.person_id,
                            leadId: this.selected.lead_id,
                            name: this.selected.name,
                            phone: this.selected.phone,
                        };
                    },
                },

                mounted() {
                    this.load();
                    this.$axios.get(this.stagesUrl)
                        .then(res => { this.stages = res.data?.stages ?? []; })
                        .catch(() => {});
                    // Keep the list fresh (previews, unread badges) while open.
                    this.pollTimer = setInterval(() => this.load(true), 10000);
                },

                beforeUnmount() {
                    if (this.pollTimer) clearInterval(this.pollTimer);
                    if (this.searchTimer) clearTimeout(this.searchTimer);
                },

                methods: {
                    load(background = false) {
                        if (!background) this.listLoading = true;

                        return this.$axios.get(this.conversationsUrl, {
                                params: { search: this.search || null, filter: this.filter, stage: this.stageFilter, page: 1 },
                            })
                            .then(res => {
                                this.conversations = res.data?.data ?? [];
                                this.page = res.data?.current_page ?? 1;
                                this.lastPage = res.data?.last_page ?? 1;
                            })
                            .catch(() => {})
                            .finally(() => { this.listLoading = false; });
                    },

                    loadMore() {
                        this.$axios.get(this.conversationsUrl, {
                                params: { search: this.search || null, filter: this.filter, stage: this.stageFilter, page: this.page + 1 },
                            })
                            .then(res => {
                                this.conversations.push(...(res.data?.data ?? []));
                                this.page = res.data?.current_page ?? this.page + 1;
                                this.lastPage = res.data?.last_page ?? this.lastPage;
                            })
                            .catch(() => {});
                    },

                    debouncedLoad() {
                        if (this.searchTimer) clearTimeout(this.searchTimer);
                        this.searchTimer = setTimeout(() => this.load(), 350);
                    },

                    select(conversation) {
                        this.selected = conversation;
                        conversation.unread_count = 0; // the thread load marks it read
                    },

                    linkPerson(conversation) {
                        this.$emitter.emit('open-confirm-modal', {
                            agree: () => {
                                this.$axios.post(`${this.linkPersonUrlBase}/${conversation.id}/link-person`)
                                    .then(res => {
                                        this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                                        this.load(true);
                                    })
                                    .catch(err => {
                                        this.$emitter.emit('add-flash', {
                                            type: 'error',
                                            message: err.response?.data?.message || 'No se pudo crear el contacto.',
                                        });
                                    });
                            },
                        });
                    },

                    initials(name) {
                        return (name || '?').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
                    },

                    // Same flat palette as Krayin's avatar component, but
                    // deterministic per name so a contact keeps its color.
                    avatarColor(name) {
                        const bg = ['bg-yellow-200', 'bg-red-200', 'bg-lime-200', 'bg-blue-200', 'bg-orange-200', 'bg-green-200', 'bg-pink-200', 'bg-yellow-400'];
                        const tx = ['text-yellow-900', 'text-red-900', 'text-lime-900', 'text-blue-900', 'text-orange-900', 'text-green-900', 'text-pink-900', 'text-yellow-900'];
                        let h = 0;
                        for (const ch of String(name || '?')) h = (h * 31 + ch.charCodeAt(0)) % 997;
                        return `${bg[h % bg.length]} ${tx[h % tx.length]}`;
                    },

                    relativeTime(iso) {
                        if (!iso) return '';
                        const d = new Date(iso);
                        const now = new Date();
                        const mins = Math.floor((now - d) / 60000);
                        if (mins < 1) return 'ahora';
                        if (mins < 60) return `${mins}m`;
                        if (mins < 1440 && d.getDate() === now.getDate()) {
                            return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
                        }
                        const yest = new Date(now); yest.setDate(now.getDate() - 1);
                        if (d.toDateString() === yest.toDateString()) return 'ayer';
                        return d.toLocaleDateString('es', { day: 'numeric', month: 'short' });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
