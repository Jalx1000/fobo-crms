@props([
    'endpoint',
    'emptyHint' => 'Los archivos enviados por WhatsApp, adjuntos de email y los que subas como actividad aparecen acá.',
])

{{--
    Consolidated "Archivos" tab, shared by the person and lead views.

    The endpoint is what differs: a person returns their whole history (their
    activities plus every file of every lead they own), a lead returns only its
    own. Everything below is source-agnostic — it renders whatever the server
    normalized.
--}}
<v-entity-files
    endpoint="{{ $endpoint }}"
    empty-hint="{{ $emptyHint }}"
></v-entity-files>

@pushOnce('scripts')
    <script type="text/x-template" id="v-entity-files-template">
        <div class="flex flex-col gap-3 p-4">
            <!-- Filters + search -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition"
                    :class="filter === tab.id
                        ? 'bg-brandColor text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                    @click="filter = tab.id"
                >
                    @{{ tab.label }}
                    <span
                        class="rounded-full px-1.5 text-[10px]"
                        :class="filter === tab.id ? 'bg-white/25' : 'bg-gray-200 dark:bg-gray-700'"
                    >
                        @{{ countFor(tab.id) }}
                    </span>
                </button>

                <div class="ml-auto">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por nombre…"
                        class="w-48 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-800 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                    >
                </div>
            </div>

            <div v-if="isLoading" class="flex justify-center py-16">
                <x-admin::spinner />
            </div>

            <div
                v-else-if="error"
                class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300"
            >
                @{{ error }}
            </div>

            <!-- Empty: tell them apart. Nothing at all is a different situation
                 from "nothing matches this filter", and the fix differs too. -->
            <div
                v-else-if="! files.length"
                class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-gray-300 px-4 py-16 text-center dark:border-gray-700"
            >
                <span class="icon-file text-3xl text-gray-300 dark:text-gray-600"></span>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Todavía no hay archivos</p>
                <p class="max-w-xs text-xs text-gray-400">@{{ emptyHint }}</p>
            </div>

            <p
                v-else-if="! visibleFiles.length"
                class="rounded-lg border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-400 dark:border-gray-700"
            >
                Ningún archivo coincide con este filtro.
            </p>

            <template v-else>
                <!-- Images and videos read as a grid of thumbnails; documents as
                     a list, where the file name is what you actually scan for. -->
                {{-- Inline grid template rather than sm:/lg: column classes:
                     this panel sits in tabs of varying width, so sizing off the
                     CONTAINER beats guessing at viewport breakpoints — and it
                     cannot be dropped by a CSS purge. --}}
                <div
                    v-if="gridFiles.length"
                    class="grid gap-3"
                    style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr))"
                >
                    <div
                        v-for="file in gridFiles"
                        :key="file.id"
                        class="group relative overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                    >
                        <button
                            v-if="file.kind === 'image'"
                            type="button"
                            class="block w-full"
                            :title="file.name"
                            @click="openViewer(file)"
                        >
                            <img
                                :src="file.url"
                                :alt="file.name"
                                loading="lazy"
                                style="height: 8rem"
                                class="w-full bg-gray-100 object-cover transition group-hover:opacity-90 dark:bg-gray-800"
                            >
                        </button>

                        <video
                            v-else
                            :src="file.url"
                            controls
                            preload="metadata"
                            style="height: 8rem"
                            class="w-full bg-black object-cover"
                        ></video>

                        <div class="flex items-start justify-between gap-1 px-2 py-1.5">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-medium text-gray-700 dark:text-gray-200" :title="file.name">
                                    @{{ file.name }}
                                </p>
                                <p class="text-[10px] text-gray-400">
                                    <span :class="sourceClass(file.source)">@{{ file.source_label }}</span>
                                    · @{{ file.date_human }}
                                </p>
                            </div>

                            <a
                                :href="file.url"
                                :download="file.name"
                                target="_blank"
                                class="shrink-0 rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-brandColor dark:hover:bg-gray-700"
                                title="Descargar"
                                aria-label="Descargar"
                            >
                                <span class="icon-down-arrow text-base"></span>
                            </a>
                        </div>
                    </div>
                </div>

                <div
                    v-if="listFiles.length"
                    class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700"
                >
                    <a
                        v-for="file in listFiles"
                        :key="file.id"
                        :href="file.url"
                        target="_blank"
                        class="flex items-center gap-3 px-3 py-2.5 transition hover:bg-gray-50 dark:hover:bg-gray-800"
                    >
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100 dark:bg-gray-800"
                            :class="iconClass(file)"
                        ></span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-200">@{{ file.name }}</span>
                            <span class="text-xs text-gray-400">
                                <span :class="sourceClass(file.source)">@{{ file.source_label }}</span>
                                <template v-if="file.author"> · @{{ file.author }}</template>
                                · @{{ file.date_human }}
                                <template v-if="file.size"> · @{{ humanSize(file.size) }}</template>
                            </span>
                        </span>

                        <span class="icon-down-arrow shrink-0 text-lg text-gray-400"></span>
                    </a>
                </div>
            </template>

            <!-- Image viewer -->
            <Teleport to="body">
                <div
                    v-if="viewer.open"
                    class="fixed inset-0 z-[10005] flex items-center justify-center p-6"
                    style="background-color: rgba(0, 0, 0, .8)"
                    @click.self="viewer.open = false"
                >
                    <button
                        type="button"
                        class="absolute right-5 top-4 text-3xl text-white/80 transition hover:text-white"
                        aria-label="Cerrar"
                        @click="viewer.open = false"
                    >&times;</button>

                    <button
                        v-if="viewer.list.length > 1"
                        type="button"
                        class="absolute left-4 text-3xl text-white/70 transition hover:text-white"
                        aria-label="Anterior"
                        @click.stop="stepViewer(-1)"
                    >&lsaquo;</button>

                    <figure class="max-h-full max-w-full text-center">
                        <img :src="viewer.list[viewer.index].url" :alt="viewer.list[viewer.index].name" class="max-h-[80vh] max-w-full rounded-lg object-contain">
                        <figcaption class="mt-2 text-xs text-white/70">
                            @{{ viewer.list[viewer.index].name }} · @{{ viewer.list[viewer.index].date_human }}
                        </figcaption>
                    </figure>

                    <button
                        v-if="viewer.list.length > 1"
                        type="button"
                        class="absolute right-4 text-3xl text-white/70 transition hover:text-white"
                        aria-label="Siguiente"
                        @click.stop="stepViewer(1)"
                    >&rsaquo;</button>
                </div>
            </Teleport>
        </div>
    </script>

    <script type="module">
        app.component('v-entity-files', {
            template: '#v-entity-files-template',

            props: {
                endpoint: { type: String, required: true },
                emptyHint: { type: String, default: '' },
            },

            data() {
                return {
                    files: [],
                    isLoading: true,
                    error: null,
                    filter: 'all',
                    search: '',
                    tabs: [
                        { id: 'all', label: 'Todos' },
                        { id: 'image', label: 'Imágenes' },
                        { id: 'video', label: 'Videos' },
                        { id: 'document', label: 'Documentos' },
                        { id: 'audio', label: 'Audios' },
                    ],
                    viewer: { open: false, list: [], index: 0 },
                };
            },

            computed: {
                visibleFiles() {
                    const term = this.search.trim().toLowerCase();

                    return this.files.filter(file => {
                        if (this.filter !== 'all' && file.kind !== this.filter) return false;
                        if (term && ! file.name.toLowerCase().includes(term)) return false;

                        return true;
                    });
                },

                gridFiles() {
                    return this.visibleFiles.filter(f => f.kind === 'image' || f.kind === 'video');
                },

                listFiles() {
                    return this.visibleFiles.filter(f => f.kind !== 'image' && f.kind !== 'video');
                },
            },

            mounted() {
                this.get();

                // A file uploaded from the activity composer lands in the same
                // tab, so refresh instead of making the user reload the page.
                this.$emitter.on('on-activity-added', () => this.get());
            },

            methods: {
                get() {
                    this.$axios.get(this.endpoint)
                        .then(res => {
                            this.files = res.data?.files ?? [];
                            this.error = null;
                        })
                        .catch(() => { this.error = 'No se pudieron cargar los archivos.'; })
                        .finally(() => { this.isLoading = false; });
                },

                countFor(id) {
                    return id === 'all'
                        ? this.files.length
                        : this.files.filter(f => f.kind === id).length;
                },

                // Only images take part in the viewer, and only the ones
                // currently visible — paging into a filtered-out file would be
                // confusing.
                openViewer(file) {
                    const images = this.visibleFiles.filter(f => f.kind === 'image');
                    const index = images.findIndex(f => f.id === file.id);

                    if (index === -1) return;

                    this.viewer = { open: true, list: images, index };
                },

                stepViewer(delta) {
                    const total = this.viewer.list.length;

                    this.viewer.index = (this.viewer.index + delta + total) % total;
                },

                sourceClass(source) {
                    return {
                        whatsapp: 'font-medium text-emerald-600 dark:text-emerald-400',
                        email: 'font-medium text-sky-600 dark:text-sky-400',
                        activity: 'font-medium text-amber-600 dark:text-amber-400',
                    }[source] || '';
                },

                iconClass(file) {
                    if (file.kind === 'audio') return 'icon-microphone text-xl text-gray-500';

                    const extension = (file.name.split('.').pop() || '').toLowerCase();

                    if (extension === 'pdf') return 'icon-file text-xl text-red-500';
                    if (['xls', 'xlsx', 'csv'].includes(extension)) return 'icon-file text-xl text-emerald-600';
                    if (['doc', 'docx'].includes(extension)) return 'icon-file text-xl text-sky-600';

                    return 'icon-file text-xl text-gray-500';
                },

                humanSize(bytes) {
                    if (! bytes) return '';
                    if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB';

                    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
                },
            },
        });
    </script>
@endPushOnce
