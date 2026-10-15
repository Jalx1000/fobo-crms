@pushOnce('scripts')
    <style>
        /* `height` es lo que gobierna la barra HORIZONTAL: sin ella las filas de
           filtros quedaban con la barra gruesa del sistema o sin barra visible. */
        .wa-scroll::-webkit-scrollbar { width: 6px; height: 6px; }

        /* Filas de filtros: barra SIEMPRE visible (no `auto`, que la esconde
           hasta que hace falta) y arrastrable con el cursor. */
        .wa-scroll-x {
            overflow-x: scroll;
            scrollbar-width: thin;
            cursor: grab;
            overscroll-behavior-x: contain;
        }
        .wa-scroll-x:active { cursor: grabbing; }
        .wa-scroll-x::-webkit-scrollbar { height: 8px; }
        .wa-scroll-x::-webkit-scrollbar-thumb { background: rgba(0,0,0,.25); border-radius: 4px; }
        .dark .wa-scroll-x::-webkit-scrollbar-thumb { background: rgba(255,255,255,.25); }
        .wa-scroll-x::-webkit-scrollbar-track { background: rgba(0,0,0,.05); border-radius: 4px; }
        .wa-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,.18); border-radius: 3px; }
        .dark .wa-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); }
        /* Cuando el chat va embebido dentro de otro modal (ej. detalle de cita),
           sus modales propios deben quedar por encima de ese modal anfitrión. */
        .wa-nested-modal .fixed.inset-0 { z-index: 10060 !important; }
    </style>

    <script type="text/x-template" id="v-whatsapp-inbox-template">
        <div class="relative flex overflow-hidden"
             :class="fullHeight ? 'h-full' : 'rounded-lg border border-gray-200 dark:border-gray-800'">
        <!-- chat column -->
        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="-m-1 flex items-center gap-3 rounded-md p-1"
                     :class="context.personId ? 'cursor-pointer transition hover:bg-gray-200/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-800' : ''"
                     :title="context.personId ? 'Ver detalle del cliente' : null"
                     :role="context.personId ? 'button' : null"
                     :tabindex="context.personId ? 0 : null"
                     @click="openProfile"
                     @keydown.enter="openProfile">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-medium"
                         :class="avatarColor(context.name)">
                        @{{ initials }}
                    </div>
                    <div class="flex flex-col">
                        <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">@{{ context.name }}</div>
                        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span v-if="context.phone">@{{ context.phone }}</span>
                            <span v-else class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">Sin número</span>

                            <!-- 24h WhatsApp window -->
                            <span v-if="window.applies && window.open"
                                  class="rounded-full bg-emerald-100 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                                  title="Tiempo restante para enviar texto libre">
                                @{{ windowLabel }} restantes
                            </span>
                            <span v-else-if="window.applies && !window.open"
                                  class="rounded-full bg-red-100 px-1.5 py-0.5 text-[11px] font-medium text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                  title="Fuera de la ventana de 24h">
                                Ventana cerrada
                            </span>
                        </div>
                    </div>
                </div>

                <!-- AI agent switch. Disabled outright in test mode: the switch
                     would store a preference the backend ignores, so the honest
                     UI is to block it and say why. -->
                <label class="flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-300"
                       :class="agentToggling || agentTestBlocked ? 'opacity-50 pointer-events-none' : ''"
                       :title="agentTestBlocked
                           ? 'El agente está en modo prueba y este número no está en la lista habilitada'
                           : 'Activa o desactiva el agente IA en esta conversación'">
                    <span>Agente IA</span>
                    <span v-if="agentTestBlocked"
                          class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                        modo prueba
                    </span>
                    <span class="relative inline-flex h-5 w-9 items-center">
                        <input type="checkbox" :checked="aiEnabled" @change="toggleAgent($event.target.checked)" class="peer sr-only">
                        <span class="h-5 w-9 rounded-full bg-gray-300 transition peer-checked:bg-brandColor dark:bg-gray-600"></span>
                        <span class="absolute left-0.5 h-4 w-4 rounded-full bg-white transition peer-checked:translate-x-4"></span>
                    </span>
                </label>
            </div>

            <!-- Messages -->
            <div class="relative" :class="fullHeight ? 'flex min-h-0 flex-1 flex-col' : ''">
            <div ref="scroll" @scroll="onScroll" class="wa-scroll flex flex-col gap-1.5 overflow-y-auto bg-[#efeae2] px-4 py-3 dark:bg-[#0b141a]"
                 :class="fullHeight ? 'flex-1' : 'h-[440px]'">
                <div v-if="isLoading" class="m-auto flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <x-admin::spinner /> <span>Cargando conversación…</span>
                </div>

                <div v-else-if="!messages.length" class="m-auto text-sm text-gray-500 dark:text-gray-400">
                    Sin mensajes todavía.
                </div>

                <template v-else v-for="(msg, i) in messages" :key="msg.id">
                    <!-- day separator: Hoy / Ayer / 15 jul 2026 -->
                    <div v-if="i === 0 || messages[i - 1].date !== msg.date" class="my-1 flex justify-center">
                        <span class="rounded-full bg-black/10 px-3 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                            @{{ dayLabel(msg.date) }}
                        </span>
                    </div>

                    <!-- internal team note: amber, full-width, never sent to the customer -->
                    <div v-if="msg.kind === 'note'" class="my-0.5 flex justify-center">
                        <div class="w-[92%] rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-900 shadow-sm dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                            <div class="mb-0.5 flex items-center gap-1 font-semibold">
                                <span class="icon-note text-sm"></span>
                                Nota interna<span v-if="msg.author"> · @{{ msg.author }}</span> · @{{ msg.time }}
                            </div>
                            <div class="whitespace-pre-wrap break-words">@{{ msg.text }}</div>
                        </div>
                    </div>

                    <div v-else class="flex" :class="msg.direction === 'outbound' ? 'justify-end' : 'justify-start'">
                        <div
                            class="max-w-[78%] rounded-lg px-2.5 py-1.5 text-sm shadow-sm"
                            :class="msg.direction === 'outbound'
                                ? 'bg-[#d9fdd3] text-gray-800 dark:bg-emerald-800 dark:text-gray-50'
                                : 'bg-white text-gray-800 dark:bg-gray-700 dark:text-gray-50'"
                        >
                            <!-- reply quote -->
                            <div v-if="msg.replyTo" class="mb-1 border-l-2 border-brandColor bg-black/5 px-2 py-1 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                <div class="font-semibold">@{{ msg.replyTo.author }}</div>
                                <div class="truncate">@{{ msg.replyTo.preview }}</div>
                            </div>

                            <!-- author label -->
                            <div v-if="msg.direction === 'outbound'" class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                 :class="msg.sender === 'ia' ? 'text-emerald-600 dark:text-emerald-300' : 'text-sky-600 dark:text-sky-300'">
                                @{{ msg.sender === 'ia' ? 'IA' : 'Agente' }}
                            </div>

                            <!-- body by type -->
                            <div v-if="msg.type === 'text'" class="whitespace-pre-wrap break-words" v-html="formatText(msg.text)"></div>

                            <div v-else-if="msg.type === 'image'">
                                <img :src="msg.media.url" class="max-h-56 rounded-md" alt="imagen">
                                <div v-if="msg.text" class="mt-1 whitespace-pre-wrap break-words">@{{ msg.text }}</div>
                            </div>

                            <div v-else-if="msg.type === 'sticker'">
                                <img :src="msg.media.url" class="h-28 w-28 object-contain" alt="sticker">
                            </div>

                            <div v-else-if="msg.type === 'audio'" class="min-w-[220px]">
                                <audio controls :src="msg.media.url" class="w-full"></audio>
                            </div>

                            <div v-else-if="msg.type === 'video'">
                                <video controls :src="msg.media.url" class="max-h-56 rounded-md"></video>
                            </div>

                            <a v-else-if="msg.type === 'document'" :href="msg.media.url" target="_blank"
                               class="flex items-center gap-2 rounded-md bg-black/5 px-2 py-2 dark:bg-white/10">
                                <span class="icon-file text-xl"></span>
                                <span class="flex flex-col">
                                    <span class="font-medium">@{{ msg.media.filename || 'Documento' }}</span>
                                    <span class="text-[11px] text-gray-500 dark:text-gray-300">@{{ msg.media.meta }}</span>
                                </span>
                            </a>

                            <div v-else-if="msg.type === 'location'" class="min-w-[200px]">
                                <div class="flex items-center gap-2 rounded-md bg-black/5 px-2 py-2 dark:bg-white/10">
                                    <span class="icon-map text-xl"></span>
                                    <span class="flex flex-col">
                                        <span class="font-medium">Ubicación</span>
                                        <span class="text-[11px] text-gray-500 dark:text-gray-300">@{{ msg.location.lat }}, @{{ msg.location.lng }}</span>
                                    </span>
                                </div>
                            </div>

                            <div v-else-if="msg.type === 'contact'" class="min-w-[200px]">
                                <div class="flex items-center gap-2 rounded-md bg-black/5 px-2 py-2 dark:bg-white/10">
                                    <span class="icon-user text-xl"></span>
                                    <span class="flex flex-col">
                                        <span class="font-medium">@{{ msg.contact.name }}</span>
                                        <span class="text-[11px] text-gray-500 dark:text-gray-300">@{{ msg.contact.phone }}</span>
                                    </span>
                                </div>
                            </div>

                            <div v-else-if="msg.type === 'template'" class="whitespace-pre-wrap break-words">
                                <span class="mb-1 inline-block rounded bg-black/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide dark:bg-white/15">Plantilla</span>
                                <div v-html="formatText(msg.text)"></div>
                            </div>

                            <!-- formulario de WhatsApp enviado: texto + el botón que ve el paciente -->
                            <div v-else-if="msg.type === 'flow'" class="min-w-[200px]">
                                <span class="mb-1 inline-block rounded bg-black/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide dark:bg-white/15">Formulario</span>
                                <div class="whitespace-pre-wrap break-words" v-html="formatText(msg.text)"></div>
                                <div class="mt-1.5 flex items-center justify-center gap-1 rounded-md border-t border-black/10 pt-1.5 text-[13px] font-medium text-sky-600 dark:border-white/15 dark:text-sky-300">
                                    <span class="icon-list text-base"></span>
                                    @{{ msg.flow?.cta || 'Completar datos' }}
                                </div>
                            </div>

                            <!-- respuesta del formulario: el resumen que arma el CRM -->
                            <div v-else-if="msg.type === 'flow_reply'" class="whitespace-pre-wrap break-words">
                                <span class="mb-1 inline-block rounded bg-black/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide dark:bg-white/15">Formulario completado</span>
                                <div>@{{ msg.text || 'Envió el formulario' }}</div>
                            </div>

                            <!-- botones de plantilla / respuestas de lista: el título elegido -->
                            <div v-else-if="(msg.type === 'button' || msg.type === 'interactive') && msg.text" class="whitespace-pre-wrap break-words">@{{ msg.text }}</div>

                            <div v-else-if="msg.text" class="whitespace-pre-wrap break-words" v-html="formatText(msg.text)"></div>

                            <div v-else class="italic text-gray-500">Tipo no soportado</div>

                            <!-- why it failed: the agent is who needs this, not the log -->
                            <div v-if="msg.status === 'failed' && msg.error"
                                 class="mt-1 rounded border border-red-300 bg-red-50 px-2 py-1 text-[11px] text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                                <span class="font-semibold">No se envió:</span> @{{ msg.error }}
                            </div>

                            <!-- meta row -->
                            <div class="mt-0.5 flex items-center justify-end gap-1 text-[10px] text-gray-500 dark:text-gray-300">
                                <span>@{{ msg.time }}</span>
                                <span v-if="msg.direction === 'outbound'"
                                      :class="msg.status === 'read' ? 'text-sky-500' : (msg.status === 'failed' ? 'text-red-500' : '')">
                                    @{{ statusTick(msg.status) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- new messages arrived while scrolled up -->
            <button v-if="newBelow" type="button"
                    aria-label="Ir a los mensajes nuevos"
                    class="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-1 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-brandColor shadow-md transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:bg-gray-800"
                    @click="jumpToBottom">
                <span class="icon-down-arrow text-base"></span> Mensajes nuevos
            </button>
            </div>

            <!-- Composer -->
            <div class="border-t border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                <!-- reply preview -->
                <div v-if="replyingTo" class="mb-2 flex items-center justify-between rounded-md border-l-2 border-brandColor bg-black/5 px-2 py-1 text-xs dark:bg-white/10">
                    <div class="min-w-0">
                        <div class="font-semibold text-brandColor">Respondiendo a @{{ replyingTo.author }}</div>
                        <div class="truncate text-gray-600 dark:text-gray-300">@{{ replyingTo.preview }}</div>
                    </div>
                    <button type="button" aria-label="Cancelar respuesta"
                            class="ml-2 rounded text-gray-500 transition hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:text-gray-200"
                            @click="replyingTo = null">✕</button>
                </div>

                <!-- Mensaje / Nota interna: the note works even with the agent on
                     or the window closed - it never reaches the customer -->
                <div v-if="conversationId && !useMock" class="mb-2 flex gap-1 text-xs">
                    <button type="button" @click="composerMode = 'mensaje'"
                            class="rounded-full px-3 py-1 font-medium transition"
                            :class="composerMode === 'mensaje' ? 'bg-brandColor text-white' : 'bg-gray-200 text-gray-600 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300'">
                        Mensaje
                    </button>
                    <button type="button" @click="composerMode = 'nota'"
                            class="rounded-full px-3 py-1 font-medium transition"
                            :class="composerMode === 'nota' ? 'bg-amber-500 text-white' : 'bg-gray-200 text-gray-600 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300'">
                        Nota interna
                    </button>
                </div>

                <template v-if="composerMode === 'nota' && conversationId && !useMock">
                    <div v-if="noteError" class="mb-2 rounded-md bg-red-50 px-3 py-1.5 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">
                        @{{ noteError }}
                    </div>
                    <div class="flex items-end gap-2">
                        <textarea
                            ref="noteInput"
                            v-model="noteDraft"
                            rows="1"
                            placeholder="Nota interna para el equipo (el cliente no la ve)…"
                            title="Enter guarda · Shift+Enter salto de línea"
                            @input="autoGrow"
                            @keydown.enter.exact.prevent="sendNote"
                            @keydown.enter.ctrl.exact.prevent="sendNote"
                            @keydown.enter.meta.exact.prevent="sendNote"
                            class="max-h-28 flex-1 resize-none rounded-2xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-gray-800 focus:border-amber-500 focus:outline-none dark:border-amber-700 dark:bg-amber-900/20 dark:text-gray-100"
                        ></textarea>
                        <button type="button" @click="sendNote" :disabled="!noteDraft.trim() || noteSaving"
                                aria-label="Guardar nota interna"
                                title="Guardar nota"
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-500 text-white transition hover:bg-amber-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-700 disabled:opacity-40">
                            <span class="icon-note text-xl"></span>
                        </button>
                    </div>
                </template>

                <template v-else>

                <div v-if="!context.phone" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    Este contacto no tiene número de WhatsApp. Asigna un teléfono para poder chatear.
                </div>

                <div v-else-if="aiEnabled" class="flex items-center gap-2 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
                    <span class="rounded bg-emerald-200 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-800 dark:text-emerald-100">IA</span>
                    <span>El agente IA está atendiendo esta conversación. <button class="font-semibold underline" @click="toggleAgent(false)">Desactivalo</button> para escribir a mano.</span>
                </div>

                <div v-else-if="window.applies && !window.open" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">
                    <div class="flex items-center justify-between gap-3">
                        <span>Ventana de 24h cerrada. WhatsApp no permite texto libre hasta que el cliente vuelva a escribir.</span>
                        <button v-if="canSend('template')" type="button" @click="openTemplates"
                                class="shrink-0 rounded-md bg-red-600 px-2.5 py-1 font-semibold text-white transition hover:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                            Enviar plantilla
                        </button>
                    </div>
                </div>

                <!-- Template picker. Teleported to <body> because the inbox is
                     embedded inside panels (lead, person, calendar) whose
                     ancestors create containing blocks: a plain `fixed` overlay
                     would be trapped in the sidebar column instead of covering
                     the viewport. -->
                <Teleport to="body">
                <div v-if="showTemplates" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 p-4" @click.self="closeTemplates">
                    <!-- Fluid up to a readable maximum: a fixed width either
                         overflows a phone or wastes the desktop viewport. -->
                    <div style="max-width:500px;" class="flex max-h-[85vh] w-full flex-col rounded-lg bg-white shadow-xl sm:max-w-lg dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                                @{{ selectedTemplate ? selectedTemplate.name : 'Elegí una plantilla' }}
                            </h3>
                            <button type="button" @click="closeTemplates" aria-label="Cerrar"
                                    class="rounded text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200">✕</button>
                        </div>

                        <div class="wa-scroll min-h-0 flex-1 overflow-y-auto p-4">
                            <!-- list -->
                            <template v-if="! selectedTemplate">
                                <p v-if="! templatesLoaded" class="text-sm text-gray-500">Cargando…</p>

                                <p v-else-if="! templates.length" class="text-sm text-gray-500">
                                    @{{ templatesReason || 'No hay plantillas aprobadas disponibles.' }}
                                </p>

                                <button v-for="tpl in templates" :key="tpl.name + tpl.language" type="button"
                                        @click="selectTemplate(tpl)"
                                        class="mb-2 block w-full rounded-lg border border-gray-200 p-3 text-left transition hover:border-brandColor dark:border-gray-700">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">@{{ tpl.name }}</span>
                                        <span class="flex shrink-0 items-center gap-1">
                                            <!-- MARKETING templates are billed per message -->
                                            <span v-if="tpl.category === 'MARKETING'"
                                                  class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                                @{{ tpl.category }}
                                            </span>
                                            <span class="text-[10px] uppercase text-gray-400">@{{ tpl.language }}</span>
                                        </span>
                                    </div>
                                    <p class="mt-1 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">@{{ tpl.body }}</p>
                                    <p v-if="tpl.variables" class="mt-1 text-[11px] text-gray-400">
                                        @{{ tpl.variables }} variable(s)
                                    </p>
                                </button>
                            </template>

                            <!-- fill in + preview -->
                            <template v-else>
                                <div v-for="(v, i) in templateVars" :key="'v-' + i" class="mb-3">
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                        Variable @{{ i + 1 }}
                                    </label>
                                    <input v-model="templateVars[i]" type="text"
                                           :placeholder="selectedTemplate.examples?.[i] || ''"
                                           class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                </div>

                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Vista previa</p>
                                <div class="whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                    <span v-if="selectedTemplate.header" class="mb-1 block font-semibold">@{{ selectedTemplate.header }}</span>
                                    @{{ templatePreview() }}
                                    <span v-if="selectedTemplate.footer" class="mt-1 block text-xs text-gray-400">@{{ selectedTemplate.footer }}</span>
                                    <span v-if="selectedTemplate.buttons?.length" class="mt-2 flex flex-wrap gap-1">
                                        <span v-for="b in selectedTemplate.buttons" :key="b"
                                              class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-500 dark:border-gray-600">@{{ b }}</span>
                                    </span>
                                </div>
                            </template>

                            <p v-if="templateError" class="mt-3 rounded border border-red-300 bg-red-50 px-2 py-1 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                                @{{ templateError }}
                            </p>
                        </div>

                        <div v-if="selectedTemplate" class="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                            <button type="button" @click="selectedTemplate = null"
                                    class="text-sm text-gray-500 transition hover:text-gray-700 dark:hover:text-gray-300">
                                ← Volver
                            </button>
                            <button type="button" @click="sendTemplate"
                                    :disabled="! templateReady() || templateSending"
                                    class="rounded-md bg-brandColor px-4 py-1.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-40">
                                @{{ templateSending ? 'Enviando…' : 'Enviar' }}
                            </button>
                        </div>
                    </div>
                </div>
                </Teleport>

                <!-- Not a v-else: the picker above is teleported away, so there is
                     no adjacent v-if to chain to. The composer still hides while
                     the picker is open. -->
                <template v-if="! showTemplates">
                    <!-- reminder to re-enable the agent when the human is done -->
                    <div v-if="agentNote" class="mb-2 flex items-center justify-between rounded-md bg-amber-50 px-3 py-1.5 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        <span>Desactivaste el agente. Acordate de <button class="font-semibold underline" @click="toggleAgent(true)">reactivarlo</button> cuando termines.</span>
                        <button type="button" aria-label="Descartar aviso"
                                class="ml-2 rounded text-amber-600 transition hover:text-amber-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                                @click="agentNote = false">✕</button>
                    </div>

                <div class="relative flex items-end gap-2" :class="agentLocked ? 'opacity-60' : ''">
                    <!-- "/" picker: canned replies matching what follows the slash -->
                    <div v-if="qrMatches.length" ref="qrList"
                         class="absolute bottom-12 left-0 z-10 max-h-48 w-80 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800">
                        <button v-for="(r, idx) in qrMatches" :key="r.id"
                                class="flex w-full flex-col items-start px-3 py-1.5 text-left"
                                :class="idx === qrIndex ? 'bg-gray-100 dark:bg-gray-700' : ''"
                                @mouseenter="qrIndex = idx"
                                @click="insertQuickReply(r)">
                            <span class="font-mono text-xs font-semibold text-brandColor">/@{{ r.shortcut }} <span v-if="r.title" class="ml-1 font-sans font-normal text-gray-500">@{{ r.title }}</span></span>
                            <span class="w-full truncate text-xs text-gray-600 dark:text-gray-300">@{{ r.content }}</span>
                        </button>
                        <div class="border-t border-gray-100 px-3 py-1 text-[10px] text-gray-400 dark:border-gray-700">↑↓ navegar · Enter seleccionar</div>
                    </div>

                    <!-- "/" typed but nothing matches -->
                    <div v-else-if="draft.startsWith('/') && draft.length > 1"
                         class="absolute bottom-12 left-0 z-10 w-80 rounded-md border border-gray-200 bg-white px-3 py-2 text-xs text-gray-500 shadow-lg dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                        Sin respuestas rápidas para «@{{ draft.slice(1) }}».
                        <button type="button" class="font-semibold text-brandColor underline" @click="openQuickReplies">Crear una</button>
                    </div>

                    <!-- emoji picker: only when the gateway can deliver them (Cloud API) -->
                    <div v-if="canSend('emoji')" class="js-pop relative">
                        <button type="button" @click="showEmoji = !showEmoji"
                                :disabled="agentLocked"
                                aria-label="Insertar emoji"
                                title="Insertar emoji"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor disabled:cursor-not-allowed disabled:hover:bg-transparent dark:hover:bg-gray-700">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="9"/>
                                <path stroke-linecap="round" d="M8.5 14.5a4.5 4.5 0 007 0M9 9.5h.01M15 9.5h.01"/>
                            </svg>
                        </button>
                        <div v-if="showEmoji"
                             class="absolute bottom-11 left-0 z-10 grid w-72 grid-cols-8 gap-0.5 rounded-md border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <button v-for="e in emojis" :key="e" type="button"
                                    class="rounded p-1 text-xl transition hover:bg-gray-100 dark:hover:bg-gray-700"
                                    @click="insertEmoji(e)">
                                @{{ e }}
                            </button>
                        </div>
                    </div>

                    <button type="button" @click="openQuickReplies"
                            :disabled="agentLocked"
                            aria-label="Respuestas rápidas"
                            title="Respuestas rápidas (escribí / para insertarlas)"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor disabled:cursor-not-allowed disabled:hover:bg-transparent dark:hover:bg-gray-700">
                        <span class="icon-bookmark text-xl"></span>
                    </button>

                    <!-- Only rendered when the active provider can actually send attachments -->
                    <!-- recording replaces the whole composer row while it runs -->
                    <div v-if="recording" class="flex flex-1 items-center gap-3 rounded-full bg-red-50 px-4 py-2 dark:bg-red-900/30">
                        <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-red-600"></span>
                        <span class="text-sm font-medium tabular-nums text-red-700 dark:text-red-300">
                            Grabando… @{{ recElapsed() }}
                        </span>
                        <div class="ml-auto flex items-center gap-1">
                            <button type="button" @click="stopRecording(true)"
                                    aria-label="Descartar grabación"
                                    class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 dark:hover:bg-gray-700">
                                <span class="icon-delete text-xl"></span>
                            </button>
                            <button type="button" @click="stopRecording(false)"
                                    aria-label="Enviar nota de voz"
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-brandColor text-white transition hover:opacity-90">
                                <span class="icon-send text-xl"></span>
                            </button>
                        </div>
                    </div>

                    <button v-if="canRecord && ! recording && ! uploading" type="button"
                            @click="startRecording"
                            :disabled="agentLocked"
                            aria-label="Grabar nota de voz"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-700">
                        <span class="icon-microphone text-xl"></span>
                    </button>

                    <div v-if="canAttach && ! recording" class="js-pop relative">
                        <input ref="fileInput" type="file" class="hidden" @change="uploadFile">
                        <button type="button" @click="showAttach = !showAttach"
                                :disabled="uploading || agentLocked"
                                aria-label="Adjuntar archivo"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent dark:hover:bg-gray-700">
                            <span v-if="uploading" class="icon-loader animate-spin text-xl"></span>
                            <span v-else class="icon-attachment text-xl"></span>
                        </button>
                        <div v-if="showAttach" class="absolute bottom-11 left-0 z-10 w-40 rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <button v-if="canSend('image')" class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" @click="pickFile('image')">
                                <span class="icon-image"></span> Imagen
                            </button>
                            <button v-if="canSend('document')" class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" @click="pickFile('document')">
                                <span class="icon-file"></span> Documento
                            </button>
                            <button v-if="canSend('audio')" class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" @click="pickFile('audio')">
                                <span class="icon-microphone"></span> Audio
                            </button>
                        </div>
                        <div v-if="uploadError"
                             class="absolute bottom-11 left-0 z-10 w-64 rounded-md border border-red-300 bg-red-50 px-2 py-1 text-[11px] text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                            @{{ uploadError }}
                        </div>
                    </div>

                    <!-- Templates are reachable at any time, not only once the 24h
                         window shuts: an agent may want to send an approved one
                         (a reminder, a confirmation) mid-conversation. -->
                    <button v-if="canSend('template') && ! recording" type="button" @click="openTemplates"
                            :disabled="uploading || agentLocked"
                            aria-label="Enviar plantilla"
                            title="Enviar plantilla aprobada"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor disabled:opacity-50 dark:hover:bg-gray-700">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="2"/>
                            <path d="M3 9h18M9 9v12"/>
                        </svg>
                    </button>

                    <textarea
                        v-if="! recording"
                        ref="msgInput"
                        v-model="draft"
                        rows="1"
                        :disabled="agentLocked"
                        :placeholder="agentLocked
                            ? 'El agente IA está respondiendo - desactivalo para escribir'
                            : 'Escribe un mensaje… (/ para respuestas rápidas)'"
                        title="Enter envía · Shift+Enter salto de línea"
                        @input="autoGrow"
                        @keydown.enter.exact.prevent="send"
                        @keydown.enter.ctrl.exact.prevent="send"
                        @keydown.enter.meta.exact.prevent="send"
                        @keydown.down="qrMove($event, 1)"
                        @keydown.up="qrMove($event, -1)"
                        class="max-h-28 min-w-0 flex-1 resize-none rounded-2xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brandColor focus:outline-none disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900"
                    ></textarea>

                    <button v-if="! recording" type="button" @click="send" :disabled="!draft.trim() || agentLocked"
                            aria-label="Enviar mensaje"
                            title="Enviar"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brandColor text-white transition hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor disabled:cursor-not-allowed disabled:opacity-40">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor" aria-hidden="true">
                            <path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a1 1 0 00-1.38 1.2L4.4 11 12 12l-7.6 1-2.38 6.2a1 1 0 001.38 1.2z"/>
                        </svg>
                    </button>
                </div>
                </template>

                </template>
            </div>

            </div>
            <!-- /chat column -->

            <!-- Client detail sidebar (WhatsApp-Web style) -->
            <aside v-if="profileOpen"
                   class="absolute inset-y-0 right-0 z-10 flex w-full max-w-[320px] flex-col border-l border-gray-200 bg-white shadow-xl lg:static lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                <!-- sidebar header -->
                <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white">Detalle del cliente</h3>
                    <button type="button" aria-label="Cerrar detalle"
                            class="flex h-7 w-7 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-800"
                            @click="profileOpen = false">
                        <span class="icon-cross-large text-lg"></span>
                    </button>
                </div>

                <div v-if="profileLoading" class="flex flex-1 items-center justify-center py-14">
                    <x-admin::spinner />
                </div>

                <template v-else-if="profile">
                    <!-- identity -->
                    <div class="flex flex-col items-center gap-1.5 border-b border-gray-200 px-3 py-4 text-center dark:border-gray-800">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full text-xl font-medium"
                             :class="avatarColor(profile.name)">
                            @{{ initials }}
                        </div>
                        <p class="text-base font-bold text-gray-900 dark:text-white">@{{ profile.name }}</p>
                        <p v-if="profile.job_title || profile.organization" class="text-xs text-gray-500 dark:text-gray-400">
                            @{{ [profile.job_title, profile.organization].filter(Boolean).join(' · ') }}
                        </p>
                        <p v-if="profile.owner" class="text-[11px] text-gray-400">Asesor: @{{ profile.owner }}</p>
                        <div v-if="profile.tags.length" class="flex flex-wrap justify-center gap-1">
                            <span v-for="tag in profile.tags" :key="tag.name"
                                  class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                  :style="tag.color ? `background:${tag.color}22;color:${tag.color}` : ''"
                                  :class="tag.color ? '' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'">
                                @{{ tag.name }}
                            </span>
                        </div>

                        <!-- follow-up status, right under the name -->
                        <div v-if="profile.followup.awaiting_reply"
                             class="mt-1 w-full rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-[11px] text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                            <span class="font-semibold"><span class="icon-error text-xs" aria-hidden="true"></span> El cliente espera respuesta</span>
                            <span v-if="profile.followup.last_message_human"> - escribió @{{ profile.followup.last_message_human }}</span>
                        </div>
                        <div v-else-if="profile.followup.last_message_human"
                             class="mt-1 w-full rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[11px] text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">
                            <span class="font-semibold">Al día</span> - @{{ profile.followup.last_sender }} respondió @{{ profile.followup.last_message_human }}
                        </div>
                    </div>

                    <!-- tabs -->
                    <div class="flex shrink-0 gap-0.5 overflow-x-auto border-b border-gray-200 px-1.5 dark:border-gray-800">
                        <button v-for="t in visibleProfileTabs" :key="t.id" type="button"
                                class="whitespace-nowrap px-2 py-2 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor"
                                :class="profileTab === t.id
                                    ? 'border-b-2 border-brandColor !text-brandColor'
                                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                @click="profileTab = t.id">
                            @{{ t.label }}
                        </button>
                    </div>

                    <!-- tab content -->
                    <div class="wa-scroll min-h-0 flex-1 overflow-y-auto p-3">
                        <!-- Info: follow-up summary -->
                        <template v-if="profileTab === 'info'">
                            <div v-if="window.applies && !window.open"
                                 class="mb-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                                Ventana de 24h cerrada - solo podés responder cuando el cliente vuelva a escribir.
                            </div>

                            <!-- Cobertura: resumen; el detalle por aseguradora vive en la pestaña Seguro -->
                            <template v-if="insuranceSummary">
                                <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Cobertura</p>

                                <button type="button"
                                        class="mb-3 block w-full rounded-lg border border-gray-200 p-2.5 text-left transition hover:border-brandColor dark:border-gray-800"
                                        @click="profileTab = 'seguro'">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">
                                            <template v-if="insuranceSummary.vigente">@{{ insuranceSummary.vigente.name }}</template>
                                            <template v-else>@{{ insuranceSummary.total }} seguro@{{ insuranceSummary.total === 1 ? '' : 's' }} registrado@{{ insuranceSummary.total === 1 ? '' : 's' }}</template>
                                        </span>

                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                              :class="insuranceStatusClass(insuranceSummary.status)">
                                            @{{ insuranceSummary.status || 'SIN VERIFICAR' }}
                                        </span>
                                    </div>

                                    <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                                        <template v-if="insuranceSummary.total > 1">
                                            @{{ insuranceSummary.total }} aseguradoras · ver detalle
                                        </template>
                                        <template v-else-if="insuranceSummary.pendiente">
                                            Sin verificar todavía
                                        </template>
                                        <template v-else>Ver detalle</template>
                                    </p>
                                </button>
                            </template>

                            <!-- active lead -->
                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Pedido en curso</p>
                            <a v-if="profile.active_lead" :href="profile.active_lead.url" target="_blank"
                               class="group mb-3 block rounded-lg border border-gray-200 p-2.5 transition hover:border-brandColor dark:border-gray-800">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium text-gray-800 group-hover:text-brandColor dark:text-gray-200">@{{ profile.active_lead.title }}</span>
                                    <span v-if="profile.active_lead.value" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">$@{{ profile.active_lead.value }}</span>
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                    <span v-if="profile.active_lead.stage" class="rounded-full bg-sky-100 px-2 py-px font-medium text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">@{{ profile.active_lead.stage }}</span>
                                    <span>abierto hace @{{ profile.active_lead.days_open }} día@{{ profile.active_lead.days_open === 1 ? '' : 's' }}</span>
                                </div>
                            </a>
                            <button v-else type="button"
                                    class="mb-3 block w-full rounded-lg border border-dashed border-gray-300 px-3 py-3 text-center text-xs text-gray-400 transition hover:border-brandColor hover:text-brandColor dark:border-gray-700"
                                    @click="profileTab = 'pedidos'; leadFormOpen = true">
                                Sin pedido en curso - crear uno
                            </button>

                            <!-- next scheduled activity -->
                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Próxima actividad</p>
                            <div v-if="profile.next_activity" class="mb-3 flex items-center gap-2 rounded-lg border border-gray-200 px-2.5 py-2 text-sm text-gray-700 dark:border-gray-800 dark:text-gray-300">
                                <span class="icon-calendar text-lg text-brandColor"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">@{{ profile.next_activity.title || profile.next_activity.type }}</span>
                                    <span class="text-[11px] text-gray-400">@{{ profile.next_activity.date }}</span>
                                </span>
                            </div>
                            <p v-else class="mb-3 rounded-lg border border-dashed border-gray-300 px-3 py-2.5 text-center text-xs text-gray-400 dark:border-gray-700">
                                Sin actividades programadas.
                            </p>

                            <div class="mb-3 grid grid-cols-3 gap-2">
                                <div class="rounded-lg border border-gray-200 p-2 text-center dark:border-gray-800">
                                    <p class="text-base font-bold text-gray-900 dark:text-white">@{{ profile.whatsapp.messages }}</p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400">Mensajes</p>
                                </div>
                                <div class="rounded-lg border border-gray-200 p-2 text-center dark:border-gray-800">
                                    <p class="text-base font-bold text-gray-900 dark:text-white">@{{ profile.leads.length }}</p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400">Pedidos</p>
                                </div>
                                <div class="rounded-lg border border-gray-200 p-2 text-center dark:border-gray-800">
                                    <p class="truncate text-base font-bold text-brandColor">$@{{ profile.leads_total }}</p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400">Valor</p>
                                </div>
                            </div>

                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Contacto</p>
                            <div class="mb-3 flex flex-col divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                <div v-for="phone in profile.contact_numbers" :key="'ph-' + phone" class="flex items-center gap-2 px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    <span class="icon-call text-base text-brandColor"></span> @{{ phone }}
                                </div>
                                <div v-for="email in profile.emails" :key="'em-' + email" class="flex items-center gap-2 px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    <span class="icon-mail text-base text-brandColor"></span> <span class="truncate">@{{ email }}</span>
                                </div>
                                <div v-if="profile.created_at" class="flex items-center gap-2 px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    <span class="icon-calendar text-base text-brandColor"></span> Cliente desde @{{ profile.created_at }}
                                </div>
                            </div>

                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Actividad en WhatsApp</p>
                            <div class="flex flex-col divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                <div class="flex items-center justify-between px-2.5 py-1.5 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Primer contacto</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">@{{ profile.whatsapp.first_contact || '-' }}</span>
                                </div>
                                <div class="flex items-center justify-between px-2.5 py-1.5 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Último mensaje</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">@{{ profile.whatsapp.last_inbound || '-' }}</span>
                                </div>
                                <div class="flex items-center justify-between px-2.5 py-1.5 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Canal</span>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium capitalize text-gray-700 dark:bg-gray-800 dark:text-gray-300">@{{ gateway || 'WhatsApp' }}</span>
                                </div>
                            </div>
                        </template>

                        <!-- Seguro: cobertura por aseguradora, editable sin salir del inbox -->
                        <template v-else-if="profileTab === 'seguro'">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                    Cobertura del paciente
                                </p>

                                <button v-if="!insuranceForm.editing" type="button"
                                        class="text-[11px] font-medium text-brandColor hover:underline"
                                        @click="startEditInsurance">
                                    @{{ profileInsurances.length ? 'Editar' : 'Agregar' }}
                                </button>
                            </div>

                            <!-- Vista: una tarjeta por aseguradora -->
                            <template v-if="!insuranceForm.editing">
                                <p v-if="!profileInsurances.length"
                                   class="rounded-lg border border-dashed border-gray-300 px-3 py-3 text-center text-xs text-gray-400 dark:border-gray-700">
                                    Sin seguros registrados - agregá uno para verificar cobertura.
                                </p>

                                <div v-else class="flex flex-col gap-2">
                                    <div v-for="ins in profileInsurances" :key="'wa-ins-' + ins.id"
                                         class="rounded-lg border border-gray-200 p-2.5 dark:border-gray-800">
                                        <div class="flex items-start justify-between gap-2">
                                            <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">
                                                @{{ ins.name }}
                                            </span>
                                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                  :class="insuranceStatusClass(ins.status)">
                                                @{{ ins.status || 'SIN VERIFICAR' }}
                                            </span>
                                        </div>

                                        <p v-if="ins.message" class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                                            @{{ ins.message }}
                                        </p>

                                        <p v-if="ins.checked_at" class="mt-0.5 text-[11px] text-gray-400">
                                            Verificado @{{ ins.checked_at }}
                                        </p>

                                        <!-- Datos crudos de la aseguradora (coaseguro, vigencia, etc.) -->
                                        <dl v-if="insuranceDetails(ins).length"
                                            class="mt-2 flex flex-col gap-0.5 border-t border-gray-100 pt-2 dark:border-gray-800">
                                            <div v-for="d in insuranceDetails(ins)" :key="'wa-d-' + ins.id + '-' + d.label"
                                                 class="flex items-start justify-between gap-2 text-[11px]">
                                                <dt class="shrink-0 text-gray-400">@{{ d.label }}</dt>
                                                <dd class="truncate text-right text-gray-700 dark:text-gray-300">@{{ d.value }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </template>

                            <!-- Editor inline: CI + aseguradoras, guarda y verifica todas -->
                            <div v-else class="flex flex-col gap-3 rounded-lg border border-gray-200 p-2.5 dark:border-gray-800">
                                <div class="flex flex-col gap-1">
                                    <label class="text-[11px] font-medium text-gray-500 dark:text-gray-400">
                                        Carnet de Identidad
                                    </label>
                                    <input type="text" v-model="insuranceForm.ci" placeholder="Ej: 5856583"
                                           class="w-full rounded-md border border-gray-200 px-2.5 py-1.5 text-sm text-gray-800 transition hover:border-gray-400 focus:border-brandColor focus:outline-none dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                                </div>

                                <div class="flex flex-col gap-1">
                                    <label class="text-[11px] font-medium text-gray-500 dark:text-gray-400">
                                        Seguros del paciente
                                    </label>
                                    <p class="text-[11px] text-gray-400">Marcá todos los que correspondan; se verifica cada uno.</p>

                                    <div class="mt-1 flex max-h-40 flex-col gap-0.5 overflow-y-auto rounded-md border border-gray-200 p-1.5 dark:border-gray-800">
                                        <label v-for="opt in insuranceOptionList" :key="'wa-opt-' + opt.id"
                                               class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                            <input type="checkbox" :value="opt.id" v-model="insuranceForm.seguro_ids"
                                                   class="h-3.5 w-3.5 cursor-pointer rounded border-gray-300">
                                            @{{ opt.name }}
                                        </label>
                                    </div>
                                </div>

                                <p v-if="insuranceForm.error"
                                   class="rounded-md bg-red-50 px-2.5 py-1.5 text-[11px] text-red-700 dark:bg-red-900/30 dark:text-red-300">
                                    @{{ insuranceForm.error }}
                                </p>

                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            class="flex items-center gap-1.5 rounded-md bg-brandColor px-3 py-1.5 text-xs font-medium text-white transition disabled:opacity-50"
                                            :disabled="insuranceForm.saving || !insuranceForm.ci || !insuranceForm.seguro_ids.length"
                                            @click="saveInsurance">
                                        <x-admin::spinner v-if="insuranceForm.saving" class="h-3 w-3" />
                                        <span>@{{ insuranceForm.saving ? 'Verificando...' : 'Guardar y verificar' }}</span>
                                    </button>

                                    <button type="button"
                                            class="rounded-md px-3 py-1.5 text-xs text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                                            :disabled="insuranceForm.saving"
                                            @click="insuranceForm.editing = false">
                                        Cancelar
                                    </button>
                                </div>
                            </div>
                        </template>

                        <!-- Imágenes -->
                        <template v-else-if="profileTab === 'imagenes'">
                            <div v-if="profile.images.length" class="grid grid-cols-3 gap-1.5">
                                <a v-for="(img, i) in profile.images" :key="'img-' + i" :href="img.url" target="_blank" :title="img.date">
                                    <img :src="img.url" class="h-20 w-full rounded-md object-cover transition hover:opacity-80" alt="imagen compartida">
                                </a>
                            </div>
                            <p v-else class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-gray-700">
                                Sin imágenes compartidas en la conversación.
                            </p>
                        </template>

                        <!-- Documentos -->
                        <template v-else-if="profileTab === 'documentos'">
                            <div v-if="profile.documents.length" class="flex flex-col divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                <a v-for="(doc, i) in profile.documents" :key="'doc-' + i" :href="doc.url" target="_blank"
                                   class="flex items-center gap-2 px-2.5 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <span class="icon-file text-lg text-gray-400"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm text-gray-800 dark:text-gray-200">@{{ doc.name }}</span>
                                        <span class="text-[11px] text-gray-400">@{{ doc.date }}</span>
                                    </span>
                                </a>
                            </div>
                            <p v-else class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-gray-700">
                                Sin documentos compartidos.
                            </p>
                        </template>

                        <!-- Enlaces -->
                        <template v-else-if="profileTab === 'enlaces'">
                            <div v-if="profile.links.length" class="flex flex-col divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                <a v-for="(link, i) in profile.links" :key="'lnk-' + i" :href="link.url" target="_blank" rel="noopener"
                                   class="block px-2.5 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <span class="block truncate text-sm text-brandColor underline">@{{ link.url }}</span>
                                    <span class="text-[11px] text-gray-400">@{{ link.date }}</span>
                                </a>
                            </div>
                            <p v-else class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-gray-700">
                                Sin enlaces compartidos.
                            </p>
                        </template>

                        <!-- Productos -->
                        <template v-else-if="profileTab === 'productos'">
                            <div v-if="profile.products.length" class="flex flex-col divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                <div v-for="(prod, i) in profile.products" :key="'prod-' + i" class="px-2.5 py-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">@{{ prod.name }}</span>
                                        <span v-if="prod.amount" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">$@{{ prod.amount }}</span>
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-2 text-[11px] text-gray-400">
                                        <span>x@{{ prod.quantity }}</span>
                                        <span v-if="prod.price">· $@{{ prod.price }} c/u</span>
                                        <span class="truncate">· @{{ prod.lead_title }}</span>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-gray-700">
                                Sin productos en los pedidos de este cliente.
                            </p>
                        </template>

                        <!-- Pedidos (leads) -->
                        <template v-else-if="profileTab === 'pedidos'">
                            <div class="mb-1.5 flex items-center justify-between">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                    Pedidos (@{{ profile.leads.length }})
                                </p>
                                <button type="button"
                                        class="text-xs font-semibold text-brandColor transition hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor"
                                        @click="leadFormOpen = !leadFormOpen">
                                    + Nuevo
                                </button>
                            </div>

                            <div v-if="leadFormOpen" class="mb-3 flex flex-col gap-2 rounded-lg border border-gray-200 p-2.5 dark:border-gray-800">
                                <div>
                                    <label class="mb-1.5 flex items-center gap-1 text-sm font-normal text-gray-800 dark:text-white required">Título</label>
                                    <input v-model="leadForm.title" placeholder="Ej: Cotización tarjetas"
                                           class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                </div>
                                <div>
                                    <label class="mb-1.5 flex items-center gap-1 text-sm font-normal text-gray-800 dark:text-white">Valor (opcional)</label>
                                    <input v-model="leadForm.value" type="number" min="0" step="0.01" placeholder="0.00"
                                           class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="secondary-button !py-1 text-sm" @click="leadFormOpen = false">Cancelar</button>
                                    <button type="button" class="primary-button !py-1 text-sm" :disabled="!leadForm.title.trim() || leadSaving" @click="createLead">
                                        @{{ leadSaving ? 'Creando…' : 'Crear' }}
                                    </button>
                                </div>
                                <p class="text-[10px] text-gray-400">Pipeline por defecto, primera etapa, fuente "WhatsApp".</p>
                            </div>

                            <div v-if="profile.leads.length" class="flex flex-col gap-2">
                                <a v-for="lead in profile.leads" :key="lead.id" :href="lead.url" target="_blank"
                                   class="group rounded-lg border border-gray-200 p-2.5 transition hover:border-brandColor hover:shadow-sm dark:border-gray-800 dark:hover:border-brandColor">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-gray-800 group-hover:text-brandColor dark:text-gray-200 dark:group-hover:text-brandColor">@{{ lead.title }}</span>
                                        <span v-if="lead.value" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">$@{{ lead.value }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                        <span v-if="lead.stage" class="rounded-full bg-sky-100 px-2 py-px font-medium text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">@{{ lead.stage }}</span>
                                        <span>@{{ lead.created_at }}</span>
                                    </div>
                                </a>
                            </div>

                            <p v-else-if="!leadFormOpen" class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-gray-700">
                                Este cliente no tiene pedidos.
                            </p>
                        </template>
                    </div>

                    <!-- sidebar footer -->
                    <div class="border-t border-gray-200 p-2.5 dark:border-gray-800">
                        <a :href="profile.view_url" target="_blank" class="primary-button w-full justify-center !py-1.5 text-sm">
                            Ver perfil completo
                        </a>
                    </div>
                </template>
            </aside>

            <!-- Product picker: fills the producto/precio variables of a quick reply -->
            <Teleport to="body">
                <x-admin::modal ref="productModal" class="wa-nested-modal">
                    <x-slot:header>
                        <h3 class="text-base font-semibold dark:text-white">Elegí un producto</h3>
                    </x-slot>

                    <x-slot:content>
                        <input v-model="productSearch" @input="debouncedProducts"
                               type="text" placeholder="Buscar por nombre o SKU…"
                               class="mb-3 w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all focus:border-brandColor dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">

                        <div v-if="productLoading" class="flex justify-center py-8">
                            <x-admin::spinner />
                        </div>

                        <p v-else-if="!products.length" class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-sm text-gray-400 dark:border-gray-700">
                            No se encontraron productos.
                        </p>

                        <div v-else class="max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                            <button v-for="p in products" :key="p.id" type="button"
                                    class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800"
                                    @click="pickProduct(p)">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-200">@{{ p.name }}</span>
                                    <span class="text-xs text-gray-400">@{{ p.sku }}</span>
                                </span>
                                <span class="shrink-0 text-sm font-semibold text-brandColor">@{{ Number(p.price).toFixed(2) }}</span>
                            </button>
                        </div>
                    </x-slot>
                </x-admin::modal>
            </Teleport>

            <!-- Quick replies manager: native CRM modal -->
            <Teleport to="body">
                <x-admin::modal ref="qrModal" class="wa-nested-modal">
                    <x-slot:header>
                        <h3 class="text-base font-semibold dark:text-white">
                            Respuestas rápidas
                        </h3>
                    </x-slot>

                    <x-slot:content>
                        <!-- existing replies -->
                        <div v-if="quickReplies.length" class="mb-4 max-h-52 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                            <div v-for="r in quickReplies" :key="r.id"
                                 class="flex items-center justify-between gap-2 px-3 py-2">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-sm font-medium text-brandColor">/@{{ r.shortcut }}</span>
                                        <span v-if="r.title" class="truncate text-sm text-gray-600 dark:text-gray-300">@{{ r.title }}</span>
                                        <span v-if="r.is_global" class="rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">Equipo</span>
                                        <span v-else class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">Personal</span>
                                    </div>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">@{{ r.content }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" aria-label="Editar respuesta rápida" title="Editar"
                                            class="rounded-md p-1.5 transition-all hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-950"
                                            @click="editQuickReply(r)">
                                        <span class="icon-edit text-xl" aria-hidden="true"></span>
                                    </button>
                                    <button type="button" aria-label="Eliminar respuesta rápida" title="Eliminar"
                                            class="rounded-md p-1.5 transition-all hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-950"
                                            @click="deleteQuickReply(r)">
                                        <span class="icon-delete text-xl" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <p v-else class="mb-4 rounded-lg border border-dashed border-gray-300 px-3 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            Todavía no hay respuestas rápidas. Creá la primera abajo.
                        </p>

                        <!-- create / edit form -->
                        <p class="mb-2.5 text-sm font-semibold text-gray-800 dark:text-white">
                            @{{ qrForm.id ? 'Editar respuesta' : 'Nueva respuesta' }}
                        </p>

                        <div class="mb-2.5 grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="mb-1.5 flex items-center gap-1 text-sm font-normal text-gray-800 dark:text-white required">
                                    Atajo
                                </label>
                                <input v-model="qrForm.shortcut" placeholder="saludo"
                                       :class="qrTried && !qrForm.shortcut.trim() ? 'border !border-red-600' : ''"
                                       class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400">
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Se busca escribiendo /atajo en el chat</p>
                            </div>
                            <div>
                                <label class="mb-1.5 flex items-center gap-1 text-sm font-normal text-gray-800 dark:text-white">
                                    Título
                                </label>
                                <input v-model="qrForm.title" placeholder="Saludo inicial"
                                       class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400">
                            </div>
                        </div>

                        <div class="mb-2.5">
                            <label class="mb-1.5 flex items-center gap-1 text-sm font-normal text-gray-800 dark:text-white required">
                                Contenido
                            </label>
                            <textarea v-model="qrForm.content" rows="3"
                                      :class="qrTried && !qrForm.content.trim() ? 'border !border-red-600' : ''"
                                      class="w-full resize-none rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"></textarea>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Variables: <span v-pre>@{{nombre}}</span> → nombre del contacto · <span v-pre>@{{producto}}</span> y <span v-pre>@{{precio}}</span> → al insertar se abre el selector de productos.
                            </p>
                        </div>

                        <label class="flex w-max cursor-pointer select-none items-center gap-2 text-sm text-gray-800 dark:text-gray-300">
                            <input type="checkbox" v-model="qrForm.is_global" class="h-4 w-4 accent-brandColor">
                            Visible para todo el equipo
                        </label>
                    </x-slot>

                    <x-slot:footer>
                        <div class="flex items-center gap-x-2.5">
                            <button v-if="qrForm.id" type="button" class="secondary-button" @click="newQuickReply">
                                Cancelar edición
                            </button>

                            <button type="button" class="primary-button" :disabled="qrSaving" @click="saveQuickReply">
                                @{{ qrSaving ? 'Guardando…' : (qrForm.id ? 'Actualizar' : 'Crear') }}
                            </button>
                        </div>
                    </x-slot>
                </x-admin::modal>
            </Teleport>
        </div>
    </script>

    <script type="module">
        // Drafts survive switching conversations in the chat page (the inbox
        // remounts per conversation, so they live outside the component).
        const waDrafts = {};

        // The client detail opens by default; if the advisor closes it, we
        // respect that for the rest of the session (survives remounts).
        let waProfilePref = true;

        app.component('v-whatsapp-inbox', {
            template: '#v-whatsapp-inbox-template',

            props: {
                context: { type: Object, required: true },
                useMock: { type: Boolean, default: true },
                threadUrl: { type: String, default: '' },
                sendUrl: { type: String, default: '' },
                sendMediaUrl: { type: String, default: '' },
                templatesUrl: { type: String, default: '' },
                sendTemplateUrl: { type: String, default: '' },
                agentUrlBase: { type: String, default: '' },
                quickRepliesUrl: { type: String, default: '' },
                personUrlBase: { type: String, default: '' },
                productsUrl: { type: String, default: '' },
                // Page mode: fill the parent instead of a bordered 440px card.
                fullHeight: { type: Boolean, default: false },
                // Hide the built-in "Detalle del cliente" sidebar (used when the
                // chat is embedded somewhere that already shows the client detail).
                hideProfile: { type: Boolean, default: false },
            },

            data() {
                return {
                    messages: [],
                    draft: '',
                    isLoading: false,
                    aiEnabled: false,
                    // Test mode is on and this number is not whitelisted: the
                    // agent stays silent here no matter what the switch says.
                    agentTestBlocked: false,
                    replyingTo: null,
                    showAttach: false,
                    uploading: false,
                    uploadError: '',
                    recording: false,
                    recorder: null,
                    recChunks: [],
                    recSeconds: 0,
                    recTimer: null,
                    recDiscard: false,
                    // approved templates - the only way to write once the 24h window closed
                    showTemplates: false,
                    templates: [],
                    templatesLoaded: false,
                    templatesReason: '',
                    selectedTemplate: null,
                    templateVars: [],
                    templateSending: false,
                    templateError: '',
                    since: null,
                    pollTimer: null,
                    // What the active provider can do. Conservative default:
                    // text only, until the server says otherwise.
                    capabilities: { send: ['text'], receive: [] },
                    // WhatsApp 24h window; refreshed by every poll.
                    window: { applies: false, open: true, seconds_left: null },
                    conversationId: null,
                    agentToggling: false,
                    agentNote: false,
                    // Internal note composer (notes are Krayin note-activities)
                    composerMode: 'mensaje',
                    noteDraft: '',
                    noteSaving: false,
                    noteError: null,
                    // Quick replies (canned responses)
                    quickReplies: [],
                    qrSaving: false,
                    qrTried: false, // marks required fields after a failed submit
                    qrIndex: 0,     // highlighted match in the "/" picker
                    // Client detail sidebar
                    profile: null,
                    profileLoading: false,
                    profileOpen: false,
                    profileTab: 'info',
                    profileTabs: [
                        { id: 'info', label: 'Info' },
                        { id: 'seguro', label: 'Seguro' },
                        { id: 'imagenes', label: 'Imágenes' },
                        { id: 'documentos', label: 'Docs' },
                        { id: 'enlaces', label: 'Enlaces' },
                        { id: 'productos', label: '@lang('admin::app.acl.products')' },
                        { id: 'pedidos', label: '@lang('admin::app.acl.leads')' },
                    ],
                    // Edición de cobertura dentro de la pestaña "Seguro" del drawer
                    insuranceForm: {
                        editing:    false,
                        saving:     false,
                        ci:         '',
                        seguro_ids: [],
                        error:      null,
                    },
                    // Quick lead creation (inside the drawer)
                    leadFormOpen: false,
                    leadForm: { title: '', value: '' },
                    leadSaving: false,
                    // Product picker for the producto/precio variables
                    products: [],
                    productSearch: '',
                    productLoading: false,
                    productTimer: null,
                    qrPendingContent: null,
                    // Smart scroll: don't yank the user while reading history
                    newBelow: false,
                    gateway: null,
                    // Emoji picker (only offered when the gateway supports it)
                    showEmoji: false,
                    emojis: ['😀','😄','😅','😂','🙂','😉','😊','😍','😘','🤔','😐','😕','😢','😭','😡','🥳','😎','🤝','👍','👎','👏','🙏','💪','👋','❤️','💚','🔥','✨','🎉','✅','❌','⚠️','📌','📎','📄','📷','🕐','📅','💰','🛒','📦','🚚','🏠','📍','☎️','💬','➡️','⭐'],
                    qrForm: { id: null, shortcut: '', title: '', content: '', is_global: false },
                };
            },

            computed: {
                initials() {
                    return (this.context.name || '?')
                        .split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
                },

                canAttach() {
                    return ['image', 'document', 'audio'].some(type => this.canSend(type));
                },

                // While the AI agent owns the conversation a human must not talk
                // over it. The backend rejects every send for the same reason, so
                // locking the whole row turns a failed request into a visible
                // state with one explicit way out: the "Desactivalo" button.
                agentLocked() {
                    return this.aiEnabled;
                },

                // Recording needs both permission from the channel and a browser
                // able to produce a format WhatsApp accepts.
                canRecord() {
                    return this.canSend('audio') && !! this.recorderMime();
                },

                windowLabel() {
                    let s = this.window.seconds_left;
                    if (s == null) return '';
                    const h = Math.floor(s / 3600);
                    const m = Math.floor((s % 3600) / 60);
                    return h > 0 ? `${h}h ${m}m` : `${m}m`;
                },

                // Hide shared-media tabs with nothing in them (Kommo relays
                // text only for now, so they'd always be empty noise there).
                visibleProfileTabs() {
                    if (!this.profile) return this.profileTabs;

                    return this.profileTabs.filter(t => {
                        if (t.id === 'imagenes') return this.profile.images.length > 0;
                        if (t.id === 'documentos') return this.profile.documents.length > 0;
                        if (t.id === 'enlaces') return this.profile.links.length > 0;
                        // "Seguro" se muestra siempre: sin seguros cargados es donde se agregan.
                        return true;
                    });
                },

                /**
                 * Aseguradoras del paciente con su estado. `insurances` puede no venir
                 * si el perfil se cacheó con una versión previa del payload.
                 */
                profileInsurances() {
                    return this.profile?.insurances || [];
                },

                // Catálogo de aseguradoras para el editor inline.
                insuranceOptionList() {
                    return this.profile?.insurance_options || [];
                },

                /**
                 * Resumen para la pestaña Info: alcanza con saber si hay cobertura y
                 * cuántas aseguradoras hay; el detalle vive en la pestaña Seguro.
                 */
                insuranceSummary() {
                    const list = this.profileInsurances;

                    if (! list.length) return null;

                    const vigente = list.find(i => i.status === 'VIGENTE');
                    const pendiente = list.filter(i => ! i.status).length;

                    return {
                        total:     list.length,
                        vigente:   vigente || null,
                        pendiente: pendiente,
                        status:    this.profile?.insurance_status || null,
                    };
                },

                // Canned replies matching what the advisor typed after "/".
                qrMatches() {
                    if (!this.draft.startsWith('/')) return [];
                    const q = this.draft.slice(1).toLowerCase();
                    return this.quickReplies
                        .filter(r => r.shortcut.toLowerCase().includes(q) || (r.title || '').toLowerCase().includes(q))
                        .slice(0, 8);
                },
            },

            watch: {
                // Typing changes the matches: restart the highlight at the top.
                qrMatches() {
                    this.qrIndex = 0;
                },

                // Remember what the advisor was typing, per conversation.
                draft(value) {
                    const key = this.context.conversationId || this.conversationId;
                    if (key) waDrafts[key] = value;
                },

                // Opening/closing the detail is a session-wide preference.
                profileOpen(value) {
                    waProfilePref = value;
                },

                // Switching Mensaje <-> Nota: focus the input that appeared.
                composerMode() {
                    this.focusComposer();
                },

                /**
                 * Al cambiar de contacto el editor de cobertura debe cerrarse: dejarlo
                 * abierto haría que el asesor edite los seguros del paciente anterior.
                 */
                'context.personId'() {
                    this.insuranceForm = {
                        editing: false, saving: false, ci: '', seguro_ids: [], error: null,
                    };
                },
            },

            mounted() {
                if (this.useMock) {
                    this.loadMock();
                } else {
                    this.startReal();
                }

                // Restore the draft the advisor left in this conversation.
                const draftKey = this.context.conversationId;
                if (draftKey && waDrafts[draftKey]) this.draft = waDrafts[draftKey];

                // Open the client detail by default (desktop only: on mobile the
                // sidebar overlays the whole chat and would hide the messages).
                if (waProfilePref && !this.hideProfile && this.context.personId && !this.useMock && window.innerWidth >= 1024) {
                    this.profileOpen = true;
                    this.fetchProfile();
                }

                // The tab shows/hides with v-show, so the component mounts hidden.
                // When it becomes visible: focus the composer AND land at the
                // bottom of the thread (a hidden container can't be scrolled).
                this.visibilityObserver = new IntersectionObserver(entries => {
                    if (entries.some(entry => entry.isIntersecting)) {
                        this.focusComposer();
                        this.scrollToBottom();
                    }
                });
                this.visibilityObserver.observe(this.$el);

                // Esc closes the profile drawer and any open picker.
                this.escHandler = (event) => {
                    if (event.key !== 'Escape') return;
                    this.showEmoji = false;
                    this.showAttach = false;
                    this.profileOpen = false;
                };
                document.addEventListener('keydown', this.escHandler);

                // Click anywhere else closes the emoji/attach popovers.
                this.docClickHandler = (event) => {
                    if (!event.target.closest('.js-pop')) {
                        this.showEmoji = false;
                        this.showAttach = false;
                    }
                };
                document.addEventListener('click', this.docClickHandler);
            },

            beforeUnmount() {
                if (this.pollTimer) clearInterval(this.pollTimer);
                if (this.visibilityObserver) this.visibilityObserver.disconnect();
                if (this.escHandler) document.removeEventListener('keydown', this.escHandler);
                if (this.docClickHandler) document.removeEventListener('click', this.docClickHandler);
            },

            methods: {
                /**
                 * Clases literales, no concatenadas: Tailwind purga lo que no puede
                 * "ver" en el template, así que armar el nombre de la clase en runtime
                 * dejaría el color fuera del CSS de producción.
                 */
                insuranceStatusClass(status) {
                    const map = {
                        VIGENTE:       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                        EN_MORA:       'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                        VENCIDO:       'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                        NO_REGISTRADO: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                    };

                    return map[status] || 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300';
                },

                /**
                 * Abre el editor precargando lo que el paciente ya tiene, para que
                 * corregir un seguro no obligue a re-marcarlos todos desde cero.
                 */
                startEditInsurance() {
                    this.insuranceForm.error = null;
                    this.insuranceForm.ci = this.profile?.insurance_ci || '';
                    this.insuranceForm.seguro_ids = this.profileInsurances.map(i => i.id);
                    this.insuranceForm.editing = true;
                },

                /**
                 * Persiste la cobertura y la verifica sin salir del inbox. La respuesta
                 * trae `results` (una entrada por aseguradora), así que repintamos el
                 * panel con eso en vez de volver a pedir el perfil entero.
                 */
                saveInsurance() {
                    if (this.insuranceForm.saving) return;
                    if (!this.insuranceForm.ci || !this.insuranceForm.seguro_ids.length) return;

                    this.insuranceForm.saving = true;
                    this.insuranceForm.error = null;

                    this.$axios.post(`${this.personUrlBase}/${this.context.personId}/insurance`, {
                            ci_paciente:     this.insuranceForm.ci,
                            seguro_paciente: this.insuranceForm.seguro_ids,
                        })
                        .then(res => {
                            const results = res.data?.results ?? [];

                            this.profile.insurances = results.map(r => ({
                                id:         r.seguro_id,
                                name:       r.seguro_name,
                                status:     r.status,
                                message:    r.message,
                                payload:    r.data ?? null,
                                checked_at: 'hace instantes',
                            }));

                            this.profile.insurance_ci = this.insuranceForm.ci;
                            this.profile.insurance_status = res.data?.status ?? null;
                            this.profile.insurance_checked_at = 'hace instantes';

                            this.insuranceForm.editing = false;

                            this.$emitter.emit('add-flash', {
                                type:    'success',
                                message: `Cobertura actualizada (${results.length || 1} verificada/s).`,
                            });
                        })
                        .catch(err => {
                            this.insuranceForm.error = err.response?.data?.message
                                || 'No se pudo guardar la cobertura.';
                        })
                        .finally(() => { this.insuranceForm.saving = false; });
                },

                // Aplana el payload crudo de la aseguradora en pares mostrables.
                insuranceDetails(insurance) {
                    const data = insurance?.payload;

                    if (! data || typeof data !== 'object') return [];

                    return Object.entries(data)
                        .filter(([, value]) => value !== null && value !== '' && value !== undefined)
                        .map(([label, value]) => ({ label, value }));
                },

                canSend(type) {
                    return (this.capabilities?.send ?? []).includes(type);
                },

                toggleAgent(enabled) {
                    // Mock mode has no backend; just flip the flag.
                    if (this.useMock) {
                        this.aiEnabled = enabled;
                        this.agentNote = !enabled;
                        return;
                    }
                    if (!this.conversationId || this.agentToggling) return;

                    // Optimista: el checkbox del header y el candado del composer
                    // (ambos derivan de aiEnabled) tienen que moverse JUNTOS al
                    // instante. Sin esto quedaban desincronizados mientras la
                    // request estaba en vuelo -y para siempre si el PATCH fallaba-.
                    const previous = this.aiEnabled;
                    this.aiEnabled = enabled;
                    this.agentNote = !enabled;

                    this.agentToggling = true;
                    this.$axios.patch(`${this.agentUrlBase}/${this.conversationId}/agent`, { enabled })
                        .then(res => {
                            // El servidor manda el estado efectivo (p.ej. bloqueado
                            // por whitelist de prueba): reconciliamos con él.
                            this.aiEnabled = res.data?.agent?.effective ?? enabled;
                            this.agentTestBlocked = res.data?.agent?.test_blocked ?? false;
                            // Show the reminder only when the human just took over.
                            this.agentNote = !this.aiEnabled;
                            // Anything the locked composer could still have open
                            // has to go with it.
                            if (this.aiEnabled) {
                                this.showAttach = false;
                                this.showEmoji = false;
                                this.closeTemplates();
                            }
                            // The composer just unlocked: put the caret there.
                            if (!this.aiEnabled) this.focusComposer();
                        })
                        .catch(() => {
                            // Falló: revertir para que header y composer vuelvan a
                            // coincidir con el estado real previo.
                            this.aiEnabled = previous;
                            this.agentNote = !previous;
                        })
                        .finally(() => { this.agentToggling = false; });
                },

                loadMock() {
                    const t = (h, m) => `${h}:${m}`;
                    this.messages = [
                        { id: 1, direction: 'inbound', type: 'text', text: 'Hola, buenas tardes 👋', time: t('14', '02'), status: 'read' },
                        { id: 2, direction: 'inbound', type: 'text', text: 'Quiero cotizar unas tarjetas de presentación', time: t('14', '02'), status: 'read' },
                        { id: 3, direction: 'outbound', sender: 'ia', type: 'text', text: '¡Hola! Claro que sí. ¿Cuántas unidades necesitas?', time: t('14', '03'), status: 'read' },
                        { id: 4, direction: 'inbound', type: 'image', text: 'Este es el diseño que tengo', media: { url: 'https://placehold.co/400x260/25D366/ffffff?text=Diseño' }, time: t('14', '05'), status: 'read' },
                        { id: 5, direction: 'inbound', type: 'audio', media: { url: 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3' }, time: t('14', '06'), status: 'read' },
                        { id: 6, direction: 'outbound', sender: 'human', type: 'document', media: { url: '#', filename: 'cotizacion-tarjetas.pdf', meta: 'PDF · 128 KB' }, time: t('14', '10'), status: 'delivered',
                          replyTo: { author: 'Cliente', preview: 'Este es el diseño que tengo' } },
                        { id: 7, direction: 'inbound', type: 'location', location: { lat: '-17.7833', lng: '-63.1821' }, time: t('14', '12'), status: 'read' },
                        { id: 8, direction: 'inbound', type: 'contact', contact: { name: 'Juan Pérez', phone: '+591 700 00000' }, time: t('14', '12'), status: 'read' },
                        { id: 9, direction: 'outbound', sender: 'human', type: 'text', text: 'Perfecto, te envío la cotización. ¿Confirmamos?', time: t('14', '15'), status: 'sent' },
                    ];
                    this.aiEnabled = true;
                    // The mock showcases every type; real capabilities come from
                    // the server, which only reports what a driver implements.
                    this.capabilities = {
                        send: ['text', 'image', 'document', 'audio', 'reply'],
                        receive: ['text', 'image', 'document', 'audio'],
                    };
                    this.scrollToBottom();
                },

                startReal() {
                    if (!this.context.phone && !this.context.personId && !this.context.leadId && !this.context.conversationId) return;
                    this.loadQuickReplies();
                    this.isLoading = true;
                    this.fetchThread(true).finally(() => {
                        this.isLoading = false;
                        // poll for incoming (until Reverb replaces this in HU-05)
                        this.pollTimer = setInterval(() => this.fetchThread(false), 4000);
                    });
                },

                fetchThread(initial) {
                    return this.$axios.get(this.threadUrl, {
                            params: {
                                conversation_id: this.context.conversationId,
                                phone: this.context.phone,
                                person_id: this.context.personId,
                                lead_id: this.context.leadId,
                                since: initial ? null : this.since,
                            },
                        })
                        .then(res => {
                            if (res.data?.server_time) this.since = res.data.server_time;
                            if (res.data?.capabilities) this.capabilities = res.data.capabilities;
                            if (res.data?.window) this.window = res.data.window;
                            if (res.data?.conversation?.id) this.conversationId = res.data.conversation.id;
                            if (res.data?.conversation?.gateway) this.gateway = res.data.conversation.gateway;
                            if (res.data?.agent) {
                                this.aiEnabled = res.data.agent.effective;
                                this.agentTestBlocked = res.data.agent.test_blocked ?? false;
                            }

                            const incoming = res.data?.messages ?? [];
                            if (!incoming.length) return;

                            // Decide BEFORE mutating: only follow the stream if the
                            // user was already at the bottom (or it's the first load).
                            const stick = initial || this.isNearBottom();
                            let appended = false;

                            if (initial) {
                                this.messages = incoming;
                            } else {
                                for (const m of incoming) {
                                    const i = this.messages.findIndex(x => x.id === m.id);
                                    if (i === -1) { this.messages.push(m); appended = true; }
                                    else this.messages.splice(i, 1, m);
                                }
                            }

                            if (stick) this.scrollToBottom();
                            else if (appended) this.newBelow = true;
                        })
                        .catch(() => {});
                },

                send() {
                    // The composer is disabled while the agent answers, but a
                    // keyboard shortcut could still land here.
                    if (this.agentLocked) {
                        return;
                    }

                    // "/" picker open: Enter inserts the highlighted match, not a send.
                    if (this.qrMatches.length) {
                        this.insertQuickReply(this.qrMatches[this.qrIndex] ?? this.qrMatches[0]);
                        return;
                    }

                    const text = this.draft.trim();
                    if (!text) return;
                    this.draft = '';
                    this.showEmoji = false;
                    this.$nextTick(() => { if (this.$refs.msgInput) this.$refs.msgInput.style.height = 'auto'; });

                    if (this.useMock) {
                        const msg = {
                            id: Date.now(), direction: 'outbound', sender: 'human', type: 'text',
                            text, time: this.now(), date: this.today(), status: 'queued', replyTo: this.replyingTo,
                        };
                        this.messages.push(msg);
                        this.replyingTo = null;
                        this.scrollToBottom();
                        setTimeout(() => { msg.status = 'sent'; }, 500);
                        setTimeout(() => { msg.status = 'delivered'; }, 1200);
                        return;
                    }

                    this.sendReal(text);
                },

                sendReal(text) {
                    const tempId = 'tmp-' + Date.now();
                    const replyId = this.replyingTo?.id ?? null;
                    this.messages.push({
                        id: tempId, direction: 'outbound', sender: 'human', type: 'text',
                        text, time: this.now(), date: this.today(), status: 'queued', replyTo: this.replyingTo,
                    });
                    this.replyingTo = null;
                    this.scrollToBottom();

                    this.$axios.post(this.sendUrl, {
                            phone: this.context.phone,
                            person_id: this.context.personId,
                            lead_id: this.context.leadId,
                            name: this.context.name,
                            text,
                            reply_to_id: replyId,
                        })
                        .then(res => {
                            const real = res.data?.message;
                            const i = this.messages.findIndex(m => m.id === tempId);
                            if (real && i !== -1) this.messages.splice(i, 1, real);
                        })
                        .catch(() => {
                            const i = this.messages.findIndex(m => m.id === tempId);
                            if (i !== -1) this.messages[i].status = 'failed';
                        });
                },

                loadQuickReplies() {
                    if (!this.quickRepliesUrl) return;
                    this.$axios.get(this.quickRepliesUrl)
                        .then(res => { this.quickReplies = res.data?.quick_replies ?? []; })
                        .catch(() => {});
                },

                insertQuickReply(reply) {
                    // Product variables need a pick from the Product module first.
                    const needsProduct = new RegExp('\\{\\{\\s*(producto|precio)\\s*\\}\\}').test(reply.content);

                    if (needsProduct && this.productsUrl) {
                        this.qrPendingContent = reply.content;
                        this.$refs.productModal.open();
                        this.loadProducts();
                        return;
                    }

                    this.draft = this.applyVars(reply.content);
                },

                loadProducts() {
                    this.productLoading = true;
                    this.$axios.get(this.productsUrl, { params: { search: this.productSearch || null } })
                        .then(res => { this.products = res.data?.products ?? []; })
                        .catch(() => {})
                        .finally(() => { this.productLoading = false; });
                },

                debouncedProducts() {
                    if (this.productTimer) clearTimeout(this.productTimer);
                    this.productTimer = setTimeout(() => this.loadProducts(), 300);
                },

                pickProduct(product) {
                    if (this.qrPendingContent) {
                        this.draft = this.applyVars(this.qrPendingContent, product);
                        this.qrPendingContent = null;
                    }
                    this.$refs.productModal.close();
                    this.focusComposer();
                },

                createLead() {
                    if (!this.leadForm.title.trim() || this.leadSaving || !this.context.personId) return;

                    this.leadSaving = true;
                    this.$axios.post(`${this.personUrlBase}/${this.context.personId}/leads`, {
                            title: this.leadForm.title.trim(),
                            lead_value: this.leadForm.value || null,
                        })
                        .then(res => {
                            this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                            this.leadForm = { title: '', value: '' };
                            this.leadFormOpen = false;
                            // Refresh the sidebar so the new lead shows up.
                            this.profile = null;
                            this.fetchProfile();
                        })
                        .catch(err => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: err.response?.data?.message || 'No se pudo crear el lead.',
                            });
                        })
                        .finally(() => { this.leadSaving = false; });
                },

                // Arrow-key navigation inside the "/" picker.
                qrMove(event, step) {
                    if (!this.qrMatches.length) return; // no picker: let the caret move

                    event.preventDefault();
                    const len = this.qrMatches.length;
                    this.qrIndex = (this.qrIndex + step + len) % len;

                    this.$nextTick(() => {
                        const list = this.$refs.qrList;
                        const item = list?.children[this.qrIndex];
                        if (item?.scrollIntoView) item.scrollIntoView({ block: 'nearest' });
                    });
                },

                // Replace supported variables with contact/product data.
                applyVars(content, product = null) {
                    const re = (name) => new RegExp('\\{\\{\\s*' + name + '\\s*\\}\\}', 'g');

                    let result = String(content).replace(re('nombre'), this.context.name || '');

                    if (product) {
                        result = result
                            .replace(re('producto'), product.name || '')
                            .replace(re('precio'), product.price != null ? Number(product.price).toFixed(2) : '');
                    }

                    return result;
                },

                // Header click toggles the sidebar; loads the profile once.
                openProfile() {
                    if (this.hideProfile) return;
                    if (!this.context.personId || !this.personUrlBase) return;

                    this.profileOpen = !this.profileOpen;

                    if (this.profileOpen) this.fetchProfile();
                },

                fetchProfile() {
                    if (this.profile) return; // already loaded for this contact

                    this.profileLoading = true;
                    this.$axios.get(`${this.personUrlBase}/${this.context.personId}`)
                        .then(res => { this.profile = res.data?.person ?? null; })
                        .catch(() => {
                            this.$emitter.emit('add-flash', { type: 'error', message: 'No se pudo cargar el detalle del cliente.' });
                            this.profileOpen = false;
                        })
                        .finally(() => { this.profileLoading = false; });
                },

                openQuickReplies() {
                    this.newQuickReply();
                    this.$refs.qrModal.open();
                },

                newQuickReply() {
                    this.qrTried = false;
                    this.qrForm = { id: null, shortcut: '', title: '', content: '', is_global: false };
                },

                editQuickReply(reply) {
                    this.qrTried = false;
                    this.qrForm = {
                        id: reply.id, shortcut: reply.shortcut, title: reply.title || '',
                        content: reply.content, is_global: reply.is_global,
                    };
                },

                saveQuickReply() {
                    if (this.qrSaving) return;

                    if (!this.qrForm.shortcut.trim() || !this.qrForm.content.trim()) {
                        this.qrTried = true; // highlight required fields, CRM style
                        return;
                    }

                    this.qrSaving = true;

                    const payload = {
                        shortcut: this.qrForm.shortcut.trim().replace(/^\//, ''),
                        title: this.qrForm.title || null,
                        content: this.qrForm.content,
                        is_global: this.qrForm.is_global,
                    };

                    const req = this.qrForm.id
                        ? this.$axios.put(`${this.quickRepliesUrl}/${this.qrForm.id}`, payload)
                        : this.$axios.post(this.quickRepliesUrl, payload);

                    req.then(res => {
                            this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                            this.newQuickReply();
                            this.loadQuickReplies();
                        })
                        .catch(err => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: err.response?.data?.message || 'No se pudo guardar la respuesta rápida.',
                            });
                        })
                        .finally(() => { this.qrSaving = false; });
                },

                deleteQuickReply(reply) {
                    // Same confirmation dialog the rest of the CRM uses.
                    this.$emitter.emit('open-confirm-modal', {
                        agree: () => {
                            this.$axios.delete(`${this.quickRepliesUrl}/${reply.id}`)
                                .then(res => {
                                    this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                                    if (this.qrForm.id === reply.id) this.newQuickReply();
                                    this.loadQuickReplies();
                                })
                                .catch(err => {
                                    this.$emitter.emit('add-flash', {
                                        type: 'error',
                                        message: err.response?.data?.message || 'No se pudo eliminar.',
                                    });
                                });
                        },
                    });
                },

                sendNote() {
                    const text = this.noteDraft.trim();
                    if (!text || this.noteSaving || !this.conversationId) return;

                    this.noteError = null;
                    this.noteSaving = true;
                    const tempId = 'tmp-note-' + Date.now();
                    this.messages.push({
                        id: tempId, kind: 'note', text,
                        author: null, time: this.now(), date: this.today(),
                        timestamp: new Date().toISOString(),
                    });
                    this.scrollToBottom();

                    this.$axios.post(`${this.agentUrlBase}/${this.conversationId}/notes`, { comment: text })
                        .then(res => {
                            const real = res.data?.note;
                            const i = this.messages.findIndex(m => m.id === tempId);
                            if (real && i !== -1) this.messages.splice(i, 1, real);
                            this.noteDraft = '';
                            this.$nextTick(() => { if (this.$refs.noteInput) this.$refs.noteInput.style.height = 'auto'; });
                        })
                        .catch(err => {
                            const i = this.messages.findIndex(m => m.id === tempId);
                            if (i !== -1) this.messages.splice(i, 1);
                            this.noteError = err.response?.data?.message || 'No se pudo guardar la nota.';
                        })
                        .finally(() => { this.noteSaving = false; });
                },

                /*
                 * Approved templates.
                 *
                 * Once the 24h window closes WhatsApp refuses free text, so a
                 * template is the only way to reach the customer again. The
                 * catalogue is fetched on demand - it is not needed until the
                 * agent actually opens the picker.
                 */
                openTemplates() {
                    if (this.agentLocked) {
                        return;
                    }

                    this.showTemplates = true;
                    this.templateError = '';

                    if (this.templatesLoaded || ! this.templatesUrl) {
                        return;
                    }

                    this.$axios.get(this.templatesUrl)
                        .then(({ data }) => {
                            this.templates = data?.templates ?? [];
                            this.templatesReason = data?.reason ?? '';
                            this.templatesLoaded = true;
                        })
                        .catch(() => {
                            this.templatesReason = 'No se pudo cargar la lista de plantillas.';
                            this.templatesLoaded = true;
                        });
                },

                closeTemplates() {
                    this.showTemplates = false;
                    this.selectedTemplate = null;
                    this.templateVars = [];
                    this.templateError = '';
                },

                selectTemplate(template) {
                    this.selectedTemplate = template;
                    // Meta ships an example per variable; it is a far better
                    // starting point than an empty box.
                    this.templateVars = Array.from(
                        { length: template.variables },
                        (_, i) => template.examples?.[i] ?? '',
                    );
                },

                // The body with its placeholders replaced, so the agent sees what
                // will actually be sent. Written as a regex on purpose: a literal
                // brace pair here would be parsed by Blade as one of its own
                // expressions and break the view.
                templatePreview() {
                    if (! this.selectedTemplate) return '';

                    return this.selectedTemplate.body.replace(/\{\{(\d+)\}\}/g, (token, n) => {
                        const value = this.templateVars[n - 1];

                        return (value ?? '').trim() !== '' ? value : token;
                    });
                },

                templateReady() {
                    return !! this.selectedTemplate
                        && this.templateVars.every(v => (v ?? '').trim() !== '');
                },

                sendTemplate() {
                    if (! this.templateReady() || this.templateSending) {
                        return;
                    }

                    this.templateSending = true;
                    this.templateError = '';

                    this.$axios.post(this.sendTemplateUrl, {
                            conversation_id: this.conversationId,
                            phone: this.context.phone,
                            name: this.context.name,
                            person_id: this.context.personId,
                            lead_id: this.context.leadId,
                            template: this.selectedTemplate.name,
                            language: this.selectedTemplate.language,
                            variables: this.templateVars,
                        })
                        .then(({ data }) => {
                            if (data?.message) {
                                this.messages.push(data.message);
                                this.scrollToBottom();
                            }
                            this.closeTemplates();
                        })
                        .catch((error) => {
                            this.templateError = error.response?.data?.message
                                ?? 'No se pudo enviar la plantilla.';
                        })
                        .finally(() => { this.templateSending = false; });
                },

                /*
                 * Voice notes.
                 *
                 * WhatsApp only takes audio as aac/mp4/mpeg/amr or ogg-with-opus,
                 * and browsers disagree on what they can record: Firefox gives
                 * ogg/opus, Safari gives mp4, Chrome usually only webm - which
                 * WhatsApp rejects. So the format is chosen at run time from what
                 * WhatsApp accepts, and if the browser offers none we say so
                 * instead of recording something that would bounce back.
                 */
                recorderMime() {
                    if (typeof MediaRecorder === 'undefined') {
                        return null;
                    }

                    const accepted = [
                        'audio/ogg;codecs=opus',
                        'audio/ogg',
                        'audio/mp4',
                        'audio/mpeg',
                    ];

                    return accepted.find(m => MediaRecorder.isTypeSupported(m)) ?? null;
                },

                startRecording() {
                    const mime = this.recorderMime();

                    if (! mime) {
                        this.uploadError = 'Este navegador no puede grabar en un formato que WhatsApp acepte.';
                        return;
                    }

                    navigator.mediaDevices.getUserMedia({ audio: true })
                        .then(stream => {
                            this.recChunks = [];
                            this.recorder = new MediaRecorder(stream, { mimeType: mime });

                            this.recorder.ondataavailable = e => {
                                if (e.data.size) this.recChunks.push(e.data);
                            };

                            this.recorder.onstop = () => {
                                // Always release the mic, whether we send or discard.
                                stream.getTracks().forEach(t => t.stop());

                                if (this.recDiscard || ! this.recChunks.length) {
                                    return;
                                }

                                const blob = new Blob(this.recChunks, { type: mime });
                                const ext = mime.includes('ogg') ? 'ogg' : (mime.includes('mp4') ? 'm4a' : 'mp3');

                                this.uploadMedia(
                                    new File([blob], `nota-de-voz-${Date.now()}.${ext}`, { type: blob.type })
                                );
                            };

                            this.recDiscard = false;
                            this.recorder.start();
                            this.recording = true;
                            this.recSeconds = 0;
                            this.recTimer = setInterval(() => { this.recSeconds++; }, 1000);
                        })
                        .catch(() => {
                            this.uploadError = 'No se pudo acceder al micrófono.';
                        });
                },

                stopRecording(discard = false) {
                    if (! this.recorder || ! this.recording) {
                        return;
                    }

                    this.recDiscard = discard;
                    clearInterval(this.recTimer);
                    this.recording = false;
                    this.recorder.stop();
                },

                recElapsed() {
                    const m = String(Math.floor(this.recSeconds / 60)).padStart(2, '0');
                    const s = String(this.recSeconds % 60).padStart(2, '0');

                    return `${m}:${s}`;
                },

                // Opens the picker filtered to the chosen kind; the upload itself
                // happens in uploadFile() once the user chooses something.
                pickFile(type) {
                    if (this.agentLocked) {
                        return;
                    }

                    this.showAttach = false;

                    // Only what WhatsApp actually accepts - a wide image/* would
                    // let the agent pick a .gif that Meta then rejects.
                    const accept = {
                        image: 'image/jpeg,image/png',
                        audio: 'audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg',
                        video: 'video/mp4,video/3gpp',
                        document: '.pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx',
                    };

                    this.$refs.fileInput.accept = accept[type] ?? '';
                    this.$refs.fileInput.click();
                },

                uploadFile(event) {
                    const file = event.target.files?.[0];

                    // Reset right away so choosing the same file twice re-triggers.
                    event.target.value = '';

                    if (file) {
                        this.uploadMedia(file);
                    }
                },

                uploadMedia(file) {
                    if (this.uploading) {
                        return;
                    }

                    const form = new FormData();
                    form.append('file', file);
                    form.append('phone', this.context.phone ?? '');
                    if (this.context.name) form.append('name', this.context.name);
                    if (this.context.personId) form.append('person_id', this.context.personId);
                    if (this.context.leadId) form.append('lead_id', this.context.leadId);
                    // The composer text travels as the caption.
                    if (this.draft.trim()) form.append('caption', this.draft.trim());

                    this.uploading = true;

                    this.$axios.post(this.sendMediaUrl, form)
                        .then(({ data }) => {
                            if (data?.message) {
                                this.messages.push(data.message);
                                this.draft = '';
                                this.scrollToBottom();
                            }
                        })
                        .catch((error) => {
                            this.uploadError = error.response?.data?.message
                                ?? 'No se pudo enviar el archivo.';
                            setTimeout(() => { this.uploadError = ''; }, 6000);
                        })
                        .finally(() => { this.uploading = false; });
                },

                statusTick(status) {
                    // Text-presentation glyphs (︎), not emoji icons.
                    return { queued: '◷', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠︎' }[status] || '';
                },

                formatText(s) {
                    if (!s) return '';
                    return String(s)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/\n/g, '<br>');
                },

                now() {
                    const d = new Date();
                    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
                },

                today() {
                    const d = new Date();
                    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                },

                dayLabel(date) {
                    if (!date) return '';
                    const today = new Date(); today.setHours(0, 0, 0, 0);
                    const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
                    // date is 'Y-m-d'; parse as local midnight
                    const [y, m, d] = date.split('-').map(Number);
                    const dt = new Date(y, m - 1, d);
                    if (dt.getTime() === today.getTime()) return 'Hoy';
                    if (dt.getTime() === yesterday.getTime()) return 'Ayer';
                    return dt.toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' });
                },

                scrollToBottom() {
                    // Double defer: wait for Vue's DOM patch AND the browser's
                    // layout pass, or scrollHeight is measured too early.
                    this.$nextTick(() => {
                        requestAnimationFrame(() => {
                            const c = this.$refs.scroll;
                            if (c) c.scrollTop = c.scrollHeight;
                        });
                    });
                },

                isNearBottom() {
                    const c = this.$refs.scroll;
                    if (!c) return true;
                    return c.scrollHeight - c.scrollTop - c.clientHeight < 80;
                },

                onScroll() {
                    if (this.newBelow && this.isNearBottom()) this.newBelow = false;
                },

                jumpToBottom() {
                    this.newBelow = false;
                    this.scrollToBottom();
                },

                // Grow the textarea with its content, up to ~5 lines.
                autoGrow(event) {
                    const el = event.target;
                    el.style.height = 'auto';
                    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
                },

                insertEmoji(emoji) {
                    this.draft += emoji;
                    this.focusComposer();
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

                // Put the caret in the visible composer so typing lands there.
                focusComposer() {
                    this.$nextTick(() => {
                        const input = this.composerMode === 'nota' ? this.$refs.noteInput : this.$refs.msgInput;
                        if (input && input.offsetParent !== null) input.focus();
                    });
                },
            },
        });
    </script>
@endPushOnce
