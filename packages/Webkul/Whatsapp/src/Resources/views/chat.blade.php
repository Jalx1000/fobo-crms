<x-admin::layouts>
    <x-slot:title>
        WhatsApp
    </x-slot>

    <div class="px-4 py-3 max-lg:p-0">
        <v-whatsapp-chat
            conversations-url="{{ route('admin.whatsapp.conversations') }}"
            link-person-url-base="{{ url(config('app.admin_path').'/whatsapp/conversations') }}"
            thread-url="{{ route('admin.whatsapp.thread') }}"
            send-url="{{ route('admin.whatsapp.send') }}"
            send-media-url="{{ route('admin.whatsapp.send-media') }}"
            templates-url="{{ route('admin.whatsapp.templates') }}"
            send-template-url="{{ route('admin.whatsapp.send-template') }}"
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
            <!-- Celular: pantalla completa entre el encabezado (62px) y la barra
                 inferior; con un chat abierto la barra se esconde (clase
                 «chat-abierto» en <body>) y el chat baja hasta el borde. -->
            <div class="flex h-[calc(100vh-110px)] overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 max-lg:fixed max-lg:inset-x-0 max-lg:top-[62px] max-lg:bottom-[calc(56px+env(safe-area-inset-bottom))] max-lg:h-auto max-lg:rounded-none max-lg:border-0 [.chat-abierto_&]:max-lg:bottom-0">
                <!-- ────────── Conversation list ──────────
                     Mobile: master-detail - the list fills the screen until a
                     conversation is chosen; the thread replaces it with a back button. -->
                <div class="w-full flex-col border-r border-gray-200 dark:border-gray-800 lg:flex lg:w-[340px] lg:shrink-0"
                     :class="selected ? 'hidden' : 'flex'">
                    <!-- search + filters -->
                    <div class="flex flex-col gap-2 border-b border-gray-200 p-3 dark:border-gray-800">
                        <div class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <span class="icon-search absolute left-2.5 top-1/2 -translate-y-1/2 text-base text-gray-400"></span>
                            <input
                                v-model="search"
                                @input="debouncedLoad"
                                type="text"
                                placeholder="Buscar o empezar un chat"
                                class="w-full rounded-lg border border-transparent bg-gray-100 py-2 pl-8 pr-3 text-sm text-gray-800 transition-all focus:border-brandColor focus:bg-white focus:outline-none dark:bg-gray-800 dark:text-gray-200 dark:focus:bg-gray-900 max-lg:h-11 max-lg:text-base"
                            >
                        </div>

                        <!-- Celular: etapa y «Mostrar» van plegados detrás de este botón. -->
                        <button type="button"
                                class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden"
                                :class="filtrosAbiertos ? 'bg-gray-100 dark:bg-gray-800' : ''"
                                :aria-expanded="filtrosAbiertos ? 'true' : 'false'"
                                aria-label="Más filtros"
                                @click="filtrosAbiertos = !filtrosAbiertos">
                            <span class="icon-filter text-2xl"></span>
                            <span v-if="filtrosActivos" class="absolute right-1 top-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-brandColor px-1 text-[10px] font-bold text-white">@{{ filtrosActivos }}</span>
                        </button>
                        </div>

                        <!-- Bandeja: tres pestañas que entran siempre en el ancho. Lo
                             que necesita a una persona («Atender») lleva su contador. -->
                        <div class="grid grid-cols-3 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800" role="group" aria-label="Bandeja">
                            <button v-for="t in tabs" :key="t.value" type="button"
                                    class="flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-2 py-1.5 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor max-lg:h-10 max-lg:text-[13px]"
                                    :class="filter === t.value
                                        ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-900 dark:text-white'
                                        : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'"
                                    :aria-pressed="filter === t.value ? 'true' : 'false'"
                                    @click="setFilter(t.value)">
                                @{{ t.label }}
                                <span v-if="t.count && counts[t.count]"
                                      class="rounded-full px-1.5 py-px text-[10px] font-bold"
                                      :class="t.value === 'attention' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-brandColor text-white'">
                                    @{{ counts[t.count] }}
                                </span>
                            </button>
                        </div>

                        <!-- Etapa y el resto de los filtros: desplegables, no filas de
                             chips que se cortan. En el celular van detrás del botón. -->
                        <div class="grid grid-cols-2 gap-2" :class="filtrosAbiertos ? '' : 'max-lg:hidden'">
                            <label class="flex min-w-0 flex-col gap-0.5 text-[11px] font-medium text-gray-500 dark:text-gray-400">
                                Etapa
                                <select :value="stageFilter || ''" @change="setStage($event.target.value)"
                                        class="h-9 w-full rounded-md border border-gray-200 bg-white px-2 text-xs text-gray-700 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 max-lg:h-10 max-lg:text-sm"
                                        :class="stageFilter ? 'border-brandColor font-semibold' : ''">
                                    <option value="">Todas</option>
                                    <option v-for="s in stages" :key="s" :value="s">@{{ s }}</option>
                                </select>
                            </label>
                            <label class="flex min-w-0 flex-col gap-0.5 text-[11px] font-medium text-gray-500 dark:text-gray-400">
                                Mostrar
                                <select :value="moreFilter" @change="setFilter($event.target.value || 'all')"
                                        class="h-9 w-full rounded-md border border-gray-200 bg-white px-2 text-xs text-gray-700 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 max-lg:h-10 max-lg:text-sm"
                                        :class="moreFilter ? 'border-brandColor font-semibold' : ''">
                                    <option value="">Todos los contactos</option>
                                    <option v-for="f in moreFilters" :key="f.value" :value="f.value">@{{ f.label }}</option>
                                </select>
                            </label>
                        </div>
                    </div>

                    <!-- items -->
                    <div class="wa-scroll flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                        <div v-if="listLoading" class="flex justify-center py-12">
                            <x-admin::spinner />
                        </div>

                        <!-- Si la lista no cargó, decirlo: vacía parecería «no hay conversaciones». -->
                        <div v-else-if="listError" class="flex flex-col items-center gap-2 px-6 py-14 text-center" role="alert">
                            <span class="icon-error text-3xl text-red-400" aria-hidden="true"></span>
                            <p class="text-sm text-gray-600 dark:text-gray-300">No se pudieron cargar las conversaciones.</p>
                            <button type="button" @click="load()"
                                    class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-semibold text-brandColor transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:bg-gray-800 max-lg:h-10 max-lg:px-4">
                                Reintentar
                            </button>
                        </div>

                        <div v-else-if="!conversations.length" class="flex flex-col items-center gap-1 px-6 py-14 text-center">
                            <span class="icon-message text-3xl text-gray-300 dark:text-gray-600"></span>
                            <p class="text-sm text-gray-400">No hay conversaciones para este filtro</p>
                        </div>

                        <template v-else>
                            <div v-for="c in conversations" :key="rowKey(c)"
                                 class="group relative flex cursor-pointer items-center gap-3 px-3 py-3 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor dark:hover:bg-gray-800/60"
                                 :class="isSelected(c) ? 'bg-gray-100 dark:bg-gray-800' : ''"
                                 role="button"
                                 tabindex="0"
                                 @click="select(c)"
                                 @keydown.enter.prevent="select(c)">
                                <!-- selected accent -->
                                <span v-if="isSelected(c)" class="absolute inset-y-0 left-0 w-1 bg-brandColor"></span>

                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-medium"
                                     :class="avatarColor(c.name)">
                                    @{{ initials(c.name) }}
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">@{{ c.name }}</span>
                                        <span class="shrink-0 text-[11px]"
                                              :class="c.unread_count && ! isSelected(c) ? 'font-semibold text-brandColor' : 'text-gray-400'">
                                            @{{ relativeTime(c.last_message_at) }}
                                        </span>
                                    </div>

                                    <div class="mt-0.5 flex items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            <!-- Quién tiene que responder: con texto, no solo color. -->
                                            <span v-if="statusBadge(c)" :title="statusBadge(c).title"
                                                  class="shrink-0 rounded px-1 text-[10px] font-bold"
                                                  :class="statusBadge(c).class">@{{ statusBadge(c).label }}</span>
                                            <!-- Recordatorio de cita ya enviado. -->
                                            <span v-if="c.reminded_at" :title="'Recordatorio enviado el ' + c.reminded_at"
                                                  class="shrink-0 rounded bg-sky-100 px-1 text-[10px] font-bold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">REC</span>
                                            <span class="truncate">@{{ c.preview || 'Sin mensajes' }}</span>
                                        </span>
                                        <span v-if="c.unread_count && ! isSelected(c)"
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
                            class="flex items-center gap-1.5 border-b border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition max-lg:h-11 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor lg:hidden dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-800"
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
                        :send-media-url="sendMediaUrl"
                        :templates-url="templatesUrl"
                        :send-template-url="sendTemplateUrl"
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
                    sendMediaUrl: { type: String, required: true },
                    templatesUrl: { type: String, default: '' },
                    sendTemplateUrl: { type: String, default: '' },
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
                        // Celular: fila de etapas desplegada.
                        filtrosAbiertos: false,
                        search: '',
                        filter: 'all',
                        tabs: [
                            { value: 'all', label: 'Todas' },
                            { value: 'unread', label: 'No leídas', count: 'unread' },
                            // Derivadas sin respuesta o que pidieron una persona.
                            { value: 'attention', label: 'Atender', count: 'attention' },
                        ],
                        moreFilters: [
                            { value: 'unassigned', label: 'Sin asignar' },
                            // Quién ya recibió el recordatorio de su cita.
                            { value: 'reminded', label: 'Con recordatorio' },
                            // Staff, not patients: listed from the doctors table
                            // rather than from existing conversations, so a
                            // doctor who never wrote is still reachable.
                            { value: 'doctors', label: 'Doctores' },
                        ],
                        // Totales de la bandeja que manda el servidor con la lista.
                        counts: { unread: 0, attention: 0 },
                        stages: [],
                        stageFilter: null,
                        page: 1,
                        lastPage: 1,
                        listLoading: false,
                        // La última carga visible falló (las de fondo no tapan la lista).
                        listError: false,
                        searchTimer: null,
                        pollTimer: null,
                        // Monotonic tag for list requests. The 10s poll and a
                        // filter/search click fire overlapping requests; without
                        // ordering, a slow older response can land last and
                        // overwrite the current filter's list. Each request
                        // captures this; a response whose tag is stale is dropped.
                        loadSeq: 0,
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

                    // Filtros plegados que están puestos (el número del botón en el
                    // celular). En Odontoking la Bandeja solo pliega la etapa.
                    filtrosActivos() {
                        return (this.stageFilter ? 1 : 0) + (this.moreFilter ? 1 : 0);
                    },

                    // El filtro del desplegable «Mostrar», si es el elegido.
                    moreFilter() {
                        return this.moreFilters.some(f => f.value === this.filter) ? this.filter : '';
                    },
                },

                watch: {
                    // Celular: con un chat abierto se esconde la barra inferior,
                    // como en WhatsApp, y la caja de texto queda en el borde.
                    selected(conversacion) {
                        document.body.classList.toggle('chat-abierto', !!conversacion);
                    },
                },

                mounted() {
                    this.load();
                    this.$axios.get(this.stagesUrl)
                        .then(res => { this.stages = res.data?.stages ?? []; })
                        .catch(() => {});
                    // Keep the list fresh (previews, unread badges) while open.
                    this.pollTimer = setInterval(() => this.poll(), 10000);
                },

                beforeUnmount() {
                    document.body.classList.remove('chat-abierto');
                    if (this.pollTimer) clearInterval(this.pollTimer);
                    if (this.searchTimer) clearTimeout(this.searchTimer);
                },

                methods: {
                    poll() {
                        // load() refetches page 1 and replaces the list. If the
                        // user scrolled and loaded more pages, doing that every
                        // 10s would drop those pages and snap the list to the
                        // top. Only auto-refresh while still on the first page;
                        // any filter/search click reloads on demand anyway.
                        if (this.page > 1) return;

                        this.load(true);
                    },

                    load(background = false) {
                        if (!background) this.listLoading = true;

                        // Newest request wins: a stale response (an in-flight
                        // poll from the previous filter) must not clobber the
                        // list the current filter just loaded.
                        const seq = ++this.loadSeq;

                        return this.$axios.get(this.conversationsUrl, {
                                params: { search: this.search || null, filter: this.filter, stage: this.stageFilter, page: 1 },
                            })
                            .then(res => {
                                if (seq !== this.loadSeq) return; // superseded
                                this.conversations = res.data?.data ?? [];
                                this.page = res.data?.current_page ?? 1;
                                this.lastPage = res.data?.last_page ?? 1;
                                // La lista de doctores no trae totales: se conservan.
                                if (res.data?.counts) this.counts = res.data.counts;
                                this.listError = false;
                            })
                            .catch(() => {
                                // Un poll de fondo que falla no borra la lista que ya se ve.
                                if (seq === this.loadSeq && ! background) this.listError = true;
                            })
                            .finally(() => { if (seq === this.loadSeq) this.listLoading = false; });
                    },

                    loadMore() {
                        // Appending shares the same guard: a background poll that
                        // lands mid-append would otherwise drop the new page.
                        const seq = ++this.loadSeq;
                        const page = this.page + 1;

                        this.$axios.get(this.conversationsUrl, {
                                params: { search: this.search || null, filter: this.filter, stage: this.stageFilter, page },
                            })
                            .then(res => {
                                if (seq !== this.loadSeq) return; // superseded
                                this.conversations.push(...(res.data?.data ?? []));
                                this.page = res.data?.current_page ?? page;
                                this.lastPage = res.data?.last_page ?? this.lastPage;
                            })
                            .catch(() => {});
                    },

                    debouncedLoad() {
                        if (this.searchTimer) clearTimeout(this.searchTimer);
                        this.searchTimer = setTimeout(() => this.load(), 350);
                    },

                    // A doctor who has never written has no conversation yet, so
                    // `id` is null for several rows at once: key and compare by
                    // the phone, which is what actually identifies the chat.
                    rowKey(c) {
                        return c.id || ('doc-' + (c.doctor_id ?? c.phone));
                    },

                    isSelected(c) {
                        if (!this.selected) return false;

                        return this.selected.id && c.id
                            ? this.selected.id === c.id
                            : this.selected.phone === c.phone;
                    },

                    setFilter(value) {
                        this.filter = value;
                        this.load();
                    },

                    setStage(value) {
                        this.stageFilter = value || null;
                        this.load();
                    },

                    statusBadge(c) {
                        return {
                            ia:         { label: 'IA', title: 'El agente IA atiende esta conversación', class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' },
                            human:      { label: 'Humano', title: 'La atiende una persona', class: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' },
                            derived:    { label: 'Derivada', title: 'Derivada a recepción: todavía nadie respondió', class: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' },
                            asks_human: { label: 'Pide recepcionista', title: 'El paciente pidió hablar con una persona y nadie le respondió', class: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' },
                        }[c.status] || null;
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
