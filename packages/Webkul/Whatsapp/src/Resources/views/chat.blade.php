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
            send-location-url="{{ route('admin.whatsapp.send-location') }}"
            templates-url="{{ route('admin.whatsapp.templates') }}"
            send-template-url="{{ route('admin.whatsapp.send-template') }}"
            agent-url-base="{{ url(config('app.admin_path').'/whatsapp/conversations') }}"
            quick-replies-url="{{ route('admin.whatsapp.quick-replies.index') }}"
            person-url-base="{{ url(config('app.admin_path').'/whatsapp/persons') }}"
            lead-url-base="{{ url(config('app.admin_path').'/whatsapp/leads') }}"
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

                        <!-- Celular: canal, asesor y etapa van plegados detrás de este botón. -->
                        <button type="button"
                                class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden"
                                :class="filtrosAbiertos ? 'bg-gray-100 dark:bg-gray-800' : ''"
                                :aria-expanded="filtrosAbiertos ? 'true' : 'false'"
                                aria-label="Filtros por canal, asesor y etapa"
                                @click="filtrosAbiertos = !filtrosAbiertos">
                            <span class="icon-filter text-2xl"></span>
                            <span v-if="filtrosActivos" class="absolute right-1 top-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-brandColor px-1 text-[10px] font-bold text-white">@{{ filtrosActivos }}</span>
                        </button>
                        </div>

                        <div class="wa-scroll flex gap-1.5 overflow-x-auto pb-0.5">
                            <button v-for="f in filters" :key="f.value" type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor max-lg:h-9 max-lg:px-3.5 max-lg:text-[13px]"
                                    :class="filter === f.value
                                        ? 'bg-brandColor text-white'
                                        : 'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'"
                                    @click="filter = f.value; load()">
                                @{{ f.label }}
                            </button>
                        </div>

                        <!-- channel filter: only worth showing once more than one
                             channel is connected, otherwise it is dead furniture -->
                        <div v-if="channels.length > 1" class="wa-scroll flex gap-1.5 overflow-x-auto pb-0.5"
                             :class="filtrosAbiertos ? '' : 'max-lg:hidden'">
                            <button type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition max-lg:h-8 max-lg:px-3 max-lg:text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="!channelFilter
                                        ? 'bg-gray-700 text-white dark:bg-gray-200 dark:text-gray-900'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400'"
                                    @click="channelFilter = null; load()">
                                Todos los canales
                            </button>
                            <button v-for="ch in channels" :key="ch.key" type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition max-lg:h-8 max-lg:px-3 max-lg:text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="channelFilter === ch.key
                                        ? channelChipActive(ch.key)
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400'"
                                    @click="channelFilter = ch.key; load()">
                                @{{ ch.label }}
                            </button>
                        </div>

                        <!-- asesor: según el reparto (derivación abierta, dueño del pedido o del contacto) -->
                        <div v-if="asesores.length" class="wa-scroll flex gap-1.5 overflow-x-auto pb-0.5"
                             :class="filtrosAbiertos ? '' : 'max-lg:hidden'">
                            <button type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition max-lg:h-8 max-lg:px-3 max-lg:text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="!asesorFilter
                                        ? 'bg-gray-700 text-white dark:bg-gray-200 dark:text-gray-900'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400'"
                                    @click="asesorFilter = null; load()">
                                Todos los asesores
                            </button>
                            <button v-for="a in asesores" :key="'as-' + a.id" type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition max-lg:h-8 max-lg:px-3 max-lg:text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="asesorFilter === a.id
                                        ? 'bg-gray-700 text-white dark:bg-gray-200 dark:text-gray-900'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400'"
                                    @click="asesorFilter = a.id; load()">
                                @{{ a.name }}
                                <span class="ml-0.5 opacity-70">(@{{ a.total }})</span>
                            </button>
                        </div>

                        <!-- stage filter: chips from the default pipeline -->
                        <div v-if="stages.length" class="wa-scroll flex gap-1.5 overflow-x-auto pb-0.5"
                             :class="filtrosAbiertos ? '' : 'max-lg:hidden'">
                            <button type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition max-lg:h-8 max-lg:px-3 max-lg:text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                    :class="!stageFilter
                                        ? 'bg-sky-600 text-white'
                                        : 'bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-300'"
                                    @click="stageFilter = null; load()">
                                Todas las etapas
                            </button>
                            <button v-for="s in stages" :key="s" type="button"
                                    class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium transition max-lg:h-8 max-lg:px-3 max-lg:text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
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

                                <div class="relative shrink-0">
                                    <img v-if="c.avatar_url" :src="c.avatar_url" alt=""
                                         class="h-11 w-11 rounded-full object-cover">
                                    <div v-else class="flex h-11 w-11 items-center justify-center rounded-full text-sm font-medium"
                                         :class="avatarColor(c.name)">
                                        @{{ initials(c.name) }}
                                    </div>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="flex min-w-0 items-baseline gap-1.5">
                                            <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">@{{ c.name }}</span>
                                            <!-- Where this conversation came from. Shown
                                                 only when the install has more than one
                                                 channel - with a single one it would be
                                                 noise on every row. -->
                                            <span v-if="channels.length > 1"
                                                  class="shrink-0 rounded-full px-1.5 py-px text-[10px] font-medium"
                                                  :class="channelChipActive(c.channel)"
                                                  :title="'Canal: ' + (c.channel_label || c.channel)">
                                                @{{ c.channel_label || c.channel }}
                                            </span>
                                        </span>
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

                                    <div v-if="c.stage || c.handoff || c.asesor || (!c.person_id && !c.lead_id)" class="mt-1 flex items-center gap-1.5">
                                        <!-- With nothing sent to the customer and no
                                             in-app notifications in Krayin, this badge
                                             is the team's real-time signal. -->
                                        <span v-if="isPooled(c)"
                                              class="shrink-0 rounded-full bg-red-100 px-1.5 py-px text-[10px] font-bold text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                              :title="'Sin asesor · ' + waitingLabel(c)">
                                            Sin asesor @{{ waitingLabel(c) }}
                                        </span>
                                        <span v-else-if="c.handoff === 'assigned'"
                                              class="shrink-0 rounded-full bg-amber-100 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                                              title="Derivada a un asesor">
                                            @{{ c.handoff_mine ? 'Mía' : 'Derivada' }}
                                        </span>
                                        <span v-if="c.asesor" class="shrink-0 truncate rounded-full bg-gray-100 px-1.5 py-px text-[10px] font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300" :title="'Asesor: ' + c.asesor">@{{ c.asesor }}</span>
                                        <span v-if="c.stage" class="truncate rounded-full bg-sky-100 px-1.5 py-px text-[10px] font-medium text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">@{{ c.stage }}</span>
                                        <span v-if="!c.person_id && !c.lead_id" class="rounded bg-amber-50 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Sin contacto</span>
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
                        :send-location-url="sendLocationUrl"
                        :templates-url="templatesUrl"
                        :send-template-url="sendTemplateUrl"
                        :agent-url-base="agentUrlBase"
                        :quick-replies-url="quickRepliesUrl"
                        :person-url-base="personUrlBase"
                        :lead-url-base="leadUrlBase"
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
                    sendLocationUrl: { type: String, required: true },
                    templatesUrl: { type: String, default: '' },
                    sendTemplateUrl: { type: String, default: '' },
                    agentUrlBase: { type: String, required: true },
                    quickRepliesUrl: { type: String, required: true },
                    personUrlBase: { type: String, required: true },
                    leadUrlBase: { type: String, required: true },
                    productsUrl: { type: String, required: true },
                    stagesUrl: { type: String, required: true },
                },

                data() {
                    return {
                        conversations: [],
                        selected: null,
                        // Celular: filas de canal, asesor y etapa desplegadas.
                        filtrosAbiertos: false,
                        search: '',
                        filter: 'all',
                        filters: [
                            { value: 'all', label: 'Todas' },
                            { value: 'unread', label: 'No leídas' },
                            // "Sin asesor" = derived, nobody claimed it.
                            // "Sin contacto" = no person/lead linked. Two very
                            // different things; the labels must not blur them.
                            // Spec tablero-por-asesor (D9, D12): las derivaciones sin
                            // asignar las asignan Administrador y Supervisores, y las
                            // conversaciones sin contacto las ve solo el Administrador.
                            @if (app(\Webkul\Admin\Helpers\Alcance::class)->veTotal())
                            { value: 'pool', label: 'Sin asesor' },
                            @endif
                            { value: 'mine', label: 'Míos' },
                            @if (app(\Webkul\Admin\Helpers\Alcance::class)->sinRestriccion())
                            { value: 'unassigned', label: 'Sin contacto' },
                            @endif
                        ],
                        stages: [],
                        stageFilter: null,
                        // Channels come from the backend registry, so the UI
                        // never assumes which ones this install has connected.
                        channels: [],
                        channelFilter: null,
                        asesores: [],
                        asesorFilter: null,
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
                            channel: this.selected.channel,
                            channelLabel: this.selected.channel_label,
                        };
                    },

                    // Cuántos filtros plegados están puestos (el número del botón en el celular).
                    filtrosActivos() {
                        return [this.channelFilter, this.asesorFilter, this.stageFilter].filter(Boolean).length;
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
                    // ?conversation=<id> is how the handoff email links straight
                    // to the thread instead of dumping the advisor on the list.
                    const deepLink = parseInt(new URLSearchParams(window.location.search).get('conversation'), 10);

                    this.load().then(() => {
                        if (!deepLink) return;

                        const match = this.conversations.find(c => c.id === deepLink);

                        // It may not be on the first page (or may be filtered
                        // out), so fall back to selecting it by id alone - the
                        // thread endpoint only needs that.
                        this.select(match ?? { id: deepLink, name: 'Conversación', person_id: null, lead_id: null, phone: null });
                    });

                    this.$axios.get(this.stagesUrl)
                        .then(res => { this.stages = res.data?.stages ?? []; })
                        .catch(() => {});
                    // Keep the list fresh (previews, unread badges) while open.
                    this.pollTimer = setInterval(() => this.load(true), 10000);
                },

                beforeUnmount() {
                    document.body.classList.remove('chat-abierto');
                    if (this.pollTimer) clearInterval(this.pollTimer);
                    if (this.searchTimer) clearTimeout(this.searchTimer);
                },

                methods: {
                    // One place to build the query, so a new filter never gets
                    // added to load() and forgotten in loadMore().
                    listParams(page) {
                        return {
                            search: this.search || null,
                            filter: this.filter,
                            stage: this.stageFilter,
                            channel: this.channelFilter,
                            asesor: this.asesorFilter,
                            page,
                        };
                    },

                    // Colour per channel, so the eye separates WhatsApp from
                    // Messenger without reading the label. Falls back to a
                    // neutral chip for any channel added later.
                    channelChipActive(channel) {
                        return {
                            cloud_api: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                            kommo:     'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
                            messenger: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
                        }[channel] ?? 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
                    },

                    // Derived and unclaimed: whoever takes it first owns it.
                    isPooled(c) {
                        return c.handoff === 'requested' && !c.handoff_mine;
                    },

                    // How long the customer has been waiting. Nobody told them
                    // anything, so the age is the whole point of the badge.
                    waitingLabel(c) {
                        if (!c.handoff_requested_at) return '';

                        const minutes = Math.floor((Date.now() - new Date(c.handoff_requested_at)) / 60000);
                        if (minutes < 1) return 'recién';
                        if (minutes < 60) return `${minutes}m`;

                        const hours = Math.floor(minutes / 60);
                        return hours < 24 ? `${hours}h` : `${Math.floor(hours / 24)}d`;
                    },

                    load(background = false) {
                        if (!background) this.listLoading = true;

                        // El refresco automático pide de una vez todo lo que ya
                        // estaba cargado (páginas × 30). Si pidiera solo la página
                        // 1, la lista volvería a 30 cada 10 s: la página se acorta
                        // y el asesor salta al tope (y pierde lo de "Cargar más").
                        const params = background && this.page > 1
                            ? { ...this.listParams(1), per_page: this.page * 30 }
                            : this.listParams(1);

                        return this.$axios.get(this.conversationsUrl, { params })
                            .then(res => {
                                this.conversations = res.data?.data ?? [];
                                this.channels = res.data?.channels ?? [];
                                this.asesores = res.data?.asesores ?? [];

                                if (background && this.page > 1) {
                                    // Se conserva la página: "Cargar más" sigue
                                    // desde donde estaba, en unidades de 30.
                                    this.lastPage = Math.max(1, Math.ceil((res.data?.total ?? 0) / 30));
                                    this.page = Math.min(this.page, this.lastPage);
                                } else {
                                    this.page = res.data?.current_page ?? 1;
                                    this.lastPage = res.data?.last_page ?? 1;
                                }
                            })
                            .catch(() => {})
                            .finally(() => { this.listLoading = false; });
                    },

                    loadMore() {
                        this.$axios.get(this.conversationsUrl, { params: this.listParams(this.page + 1) })
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
