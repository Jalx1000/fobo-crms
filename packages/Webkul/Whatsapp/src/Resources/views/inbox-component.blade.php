@pushOnce('scripts')
    <style>
        .wa-scroll::-webkit-scrollbar { width: 6px; }
        .wa-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,.18); border-radius: 3px; }
        .dark .wa-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); }

        /* Visor de imágenes — CSS propio a propósito: el build de Tailwind del
           admin está precompilado, así que una clase de utilidad nueva escrita
           acá no se compilaría nunca y el visor saldría sin estilos. */
        .wa-zoomable { cursor: zoom-in; }
        .wa-viewer {
            position: fixed; inset: 0; z-index: 10002; display: flex;
            align-items: center; justify-content: center;
            background: rgba(0,0,0,.92); backdrop-filter: blur(4px);
            -webkit-user-select: none; user-select: none;
        }
        .wa-viewer__img {
            max-height: 86vh; max-width: 92vw; border-radius: 10px;
            object-fit: contain; box-shadow: 0 25px 60px -15px rgba(0,0,0,.7);
            transition: transform .15s ease;
        }
        .wa-viewer__img.is-grab { cursor: grab; }
        .wa-viewer__img.is-grabbing { cursor: grabbing; transition: none; }
        .wa-toolbar {
            position: absolute; top: 16px; left: 50%; transform: translateX(-50%);
            z-index: 10; display: flex; align-items: center; gap: 2px; padding: 6px;
            border-radius: 16px; border: 1px solid rgba(255,255,255,.12);
            background: rgba(24,28,36,.82); backdrop-filter: blur(10px);
            box-shadow: 0 8px 28px rgba(0,0,0,.45); color: rgba(255,255,255,.92);
        }
        .wa-tbtn {
            display: flex; align-items: center; justify-content: center;
            height: 38px; width: 38px; border-radius: 11px; color: inherit;
            background: transparent; border: 0; cursor: pointer; transition: background .15s;
        }
        .wa-tbtn:hover { background: rgba(255,255,255,.14); }
        .wa-tbtn:focus-visible { outline: 2px solid rgba(255,255,255,.55); outline-offset: 1px; }
        .wa-tbtn--close:hover { background: rgba(239,68,68,.85); }
        .wa-tsep { margin: 0 4px; height: 22px; width: 1px; background: rgba(255,255,255,.14); }
        .wa-tpct {
            min-width: 46px; text-align: center; font-size: 12px; font-weight: 600;
            color: rgba(255,255,255,.72); font-variant-numeric: tabular-nums;
        }
        .wa-vicon { height: 20px; width: 20px; display: block; }
        .wa-nav {
            position: absolute; top: 50%; transform: translateY(-50%); z-index: 10;
            display: flex; align-items: center; justify-content: center;
            height: 46px; width: 46px; border-radius: 9999px; cursor: pointer;
            border: 1px solid rgba(255,255,255,.12); background: rgba(24,28,36,.72);
            backdrop-filter: blur(10px); box-shadow: 0 8px 28px rgba(0,0,0,.45);
            color: rgba(255,255,255,.92); transition: background .15s;
        }
        .wa-nav:hover { background: rgba(24,28,36,.98); }
        .wa-nav--prev { left: 18px; }
        .wa-nav--next { right: 18px; }
        .wa-counter {
            position: absolute; top: 18px; right: 18px; z-index: 10;
            border-radius: 9999px; border: 1px solid rgba(255,255,255,.12);
            background: rgba(24,28,36,.82); backdrop-filter: blur(10px);
            padding: 6px 13px; font-size: 12px; font-weight: 600;
            color: rgba(255,255,255,.82); font-variant-numeric: tabular-nums;
        }
        .wa-caption {
            position: absolute; bottom: 22px; left: 50%; transform: translateX(-50%);
            z-index: 10; max-width: 80vw; white-space: pre-wrap; word-break: break-word;
            border-radius: 12px; border: 1px solid rgba(255,255,255,.12);
            background: rgba(24,28,36,.82); backdrop-filter: blur(10px);
            padding: 9px 16px; text-align: center; font-size: 14px; color: rgba(255,255,255,.92);
        }
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

                <!-- Estado de la derivación: lo primero que hay que saber al
                     abrir la conversación, así que va antes del switch. -->
                <span v-if="handoff.open"
                      class="mr-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                      :class="handoff.tomado
                          ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200'
                          : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'"
                      :title="handoff.tomado
                          ? `Atiende ${handoff.asesor?.name ?? 'un asesor'}`
                          : 'Nadie tomó todavía esta derivación'">
                    <span aria-hidden="true">@{{ handoff.tomado ? '👤' : '⏳' }}</span>
                    @{{ handoff.tomado ? (handoff.asesor?.name ?? 'Asesor') : 'Sin asesor' }}
                </span>

                <!-- AI agent switch -->
                <label class="flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-300"
                       :class="agentToggling ? 'opacity-50 pointer-events-none' : ''">
                    <span>Agente IA</span>
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
                            <!-- Archivo en camino o que no se pudo traer. Va antes que el
                                 resto para que ningún tipo con media caiga en una burbuja
                                 vacía mientras se descarga. -->
                            <div v-if="msg.media && msg.media.estado === 'pendiente'"
                                 class="flex items-center gap-2 rounded-md bg-black/5 px-3 py-3 text-xs text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                                Descargando @{{ etiquetaMedia(msg.type) }}…
                            </div>

                            <div v-else-if="msg.media && msg.media.estado === 'fallido'"
                                 class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                <p class="font-medium">No se pudo traer @{{ etiquetaMedia(msg.type) }}</p>
                                <p v-if="msg.media.motivo" class="mt-0.5 opacity-80">@{{ msg.media.motivo }}</p>
                                <p class="mt-1 opacity-70">Pedile al cliente que lo reenvíe.</p>
                            </div>

                            <div v-else-if="msg.type === 'text'" class="whitespace-pre-wrap break-words" v-html="formatText(msg.text)"></div>

                            <div v-else-if="msg.type === 'image'">
                                <img v-if="msg.media" :src="msg.media.url"
                                     @click="openLightbox(msg.media.url, chatImageUrls())"
                                     class="wa-zoomable max-h-56 rounded-md" alt="imagen">
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
                     or the window closed — it never reaches the customer -->
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

                <!-- Derivación abierta. Va FUERA de la cadena v-if del composer
                     a propósito: es un aviso que acompaña, no que reemplaza. Si
                     ocupara un eslabón de la cadena, el asesor se quedaría sin
                     caja de texto justo cuando tiene que contestar. -->
                <div v-if="handoff.open"
                     class="mb-2 rounded-md border px-3 py-2 text-xs"
                     :class="handoff.tomado
                         ? 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-200'
                         : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200'">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold">
                                <template v-if="!handoff.tomado">Derivada a un asesor · sin tomar</template>
                                <template v-else-if="handoff.is_mine">La estás atendiendo vos</template>
                                <template v-else>La atiende @{{ handoff.asesor?.name ?? 'otro asesor' }}</template>
                                <span class="font-normal opacity-80">· @{{ handoffEspera }}</span>
                            </p>

                            <p v-if="handoff.reason" class="mt-0.5 break-words opacity-90">
                                <span v-if="handoff.referencia" class="font-medium">@{{ handoff.referencia }} — </span>@{{ handoff.reason }}
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <div class="flex items-center gap-2">
                                <button v-if="!handoff.tomado" type="button" :disabled="handoffBusy"
                                        class="rounded-md bg-amber-600 px-2.5 py-1 font-semibold text-white transition hover:bg-amber-700 disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                                        @click="claimHandoff">
                                    Tomar
                                </button>

                                <button type="button" :disabled="handoffBusy"
                                        class="rounded-md border border-current px-2.5 py-1 font-semibold transition hover:bg-white/60 disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current dark:hover:bg-white/10"
                                        @click="resolveHandoff">
                                    Cerrar
                                </button>
                            </div>

                            <!-- Cerrar devuelve la conversación a la IA salvo que
                                 el asesor destilde esto: el estado opuesto (IA
                                 apagada y sin derivación) es tierra de nadie. -->
                            <label class="flex cursor-pointer items-center gap-1.5 opacity-90">
                                <input type="checkbox" v-model="reactivarIa" class="h-3 w-3 rounded border-current">
                                <span>Al cerrar, devolver a la IA</span>
                            </label>
                        </div>
                    </div>

                    <p v-if="handoffError" class="mt-1.5 font-medium text-red-700 dark:text-red-300" role="alert">
                        @{{ handoffError }}
                    </p>
                </div>

                <div v-if="!context.phone" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    Este contacto no tiene número de WhatsApp. Asigna un teléfono para poder chatear.
                </div>

                <div v-else-if="aiEnabled" class="flex items-center gap-2 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
                    <span class="rounded bg-emerald-200 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-800 dark:text-emerald-100">IA</span>
                    <span>El agente IA está atendiendo esta conversación. <button class="font-semibold underline" @click="toggleAgent(false)">Desactivalo</button> para escribir a mano.</span>
                </div>

                <div v-else-if="window.applies && !window.open" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">
                    Ventana de 24h cerrada. WhatsApp no permite texto libre hasta que el cliente vuelva a escribir (o usar una plantilla aprobada).
                </div>

                <template v-else>
                    <!-- reminder to re-enable the agent when the human is done -->
                    <div v-if="agentNote" class="mb-2 flex items-center justify-between rounded-md bg-amber-50 px-3 py-1.5 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        <span>Desactivaste el agente. Acordate de <button class="font-semibold underline" @click="toggleAgent(true)">reactivarlo</button> cuando termines.</span>
                        <button type="button" aria-label="Descartar aviso"
                                class="ml-2 rounded text-amber-600 transition hover:text-amber-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                                @click="agentNote = false">✕</button>
                    </div>

                <div class="relative flex items-end gap-2">
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
                                aria-label="Insertar emoji"
                                title="Insertar emoji"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-700">
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
                            aria-label="Respuestas rápidas"
                            title="Respuestas rápidas (escribí / para insertarlas)"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-700">
                        <span class="icon-bookmark text-xl"></span>
                    </button>

                    <!-- Only rendered when the active provider can actually send attachments -->
                    <div v-if="canAttach" class="js-pop relative">
                        <button type="button" @click="showAttach = !showAttach"
                                :disabled="enviandoAdjunto"
                                aria-label="Adjuntar archivo"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-700">
                            <span class="icon-attachment text-xl"></span>
                        </button>
                        <div v-if="showAttach" class="absolute bottom-11 left-0 z-10 w-56 rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <button class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" @click="elegirArchivo">
                                <span class="icon-attachment"></span> Subir un archivo
                            </button>
                            <button class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" @click="enviarGaleria">
                                <span class="icon-image"></span> Fotos de un inmueble
                            </button>
                            <button class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" @click="enviarUbicacion">
                                <span class="icon-location"></span> Ubicación de un inmueble
                            </button>
                        </div>
                        <input ref="fileInput" type="file" class="hidden" @change="subirArchivo">
                    </div>

                    <textarea
                        ref="msgInput"
                        v-model="draft"
                        rows="1"
                        placeholder="Escribe un mensaje… (/ para respuestas rápidas)"
                        title="Enter envía · Shift+Enter salto de línea"
                        @input="autoGrow"
                        @keydown.enter.exact.prevent="send"
                        @keydown.enter.ctrl.exact.prevent="send"
                        @keydown.enter.meta.exact.prevent="send"
                        @keydown.down="qrMove($event, 1)"
                        @keydown.up="qrMove($event, -1)"
                        class="max-h-28 flex-1 resize-none rounded-2xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    ></textarea>

                    <button type="button" @click="send" :disabled="!draft.trim()"
                            aria-label="Enviar mensaje"
                            title="Enviar"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-brandColor text-white transition hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor disabled:opacity-40">
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

                            <!-- active lead -->
                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Pedido en curso</p>
                            <a v-if="profile.active_lead" :href="profile.active_lead.url" target="_blank"
                               class="group mb-3 block rounded-lg border border-gray-200 p-2.5 transition hover:border-brandColor dark:border-gray-800">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium text-gray-800 group-hover:text-brandColor dark:text-gray-200">@{{ profile.active_lead.title }}</span>
                                    {{-- El importe ya viene con su moneda desde el servidor, sin signo delante:
                                         acá se opera en USD y BOB. --}}
                                    <span v-if="profile.active_lead.value" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">@{{ profile.active_lead.value }}</span>
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                    <span v-if="profile.active_lead.stage" class="rounded-full bg-sky-100 px-2 py-px font-medium text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">@{{ profile.active_lead.stage }}</span>
                                    <span>abierto hace @{{ profile.active_lead.days_open }} día@{{ profile.active_lead.days_open === 1 ? '' : 's' }}</span>
                                </div>
                            </a>
                            <button v-else type="button"
                                    class="mb-3 block w-full rounded-lg border border-dashed border-gray-300 px-3 py-3 text-center text-xs text-gray-400 transition hover:border-brandColor hover:text-brandColor dark:border-gray-700"
                                    @click="profileTab = 'pedidos'; leadFormOpen = true">
                                Sin pedido en curso — crear uno
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
                                    <span class="font-medium text-gray-800 dark:text-gray-200">@{{ profile.whatsapp.first_contact || '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between px-2.5 py-1.5 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Último mensaje</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">@{{ profile.whatsapp.last_inbound || '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between px-2.5 py-1.5 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">Canal</span>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium capitalize text-gray-700 dark:bg-gray-800 dark:text-gray-300">@{{ gateway || 'WhatsApp' }}</span>
                                </div>
                            </div>
                        </template>

                        <!-- Imágenes -->
                        <template v-else-if="profileTab === 'imagenes'">
                            <div v-if="profile.images.length" class="grid grid-cols-3 gap-1.5">
                                {{-- La galería abre el visor en vez de una pestaña nueva:
                                     así se pasa de una imagen a otra sin salir del chat. --}}
                                <button v-for="(img, i) in profile.images" :key="'img-' + i" type="button"
                                        :title="img.date"
                                        @click="openLightbox(img.url, profileImageUrls())">
                                    <img :src="img.url" class="wa-zoomable h-20 w-full rounded-md object-cover transition hover:opacity-80" alt="imagen compartida">
                                </button>
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
                                        <span v-if="lead.value" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">@{{ lead.value }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                        <span v-if="lead.stage" class="rounded-full bg-sky-100 px-2 py-px font-medium text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">@{{ lead.stage }}</span>
                                        <span>@{{ lead.created_at }}</span>
                                    </div>

                                    <!-- Inmuebles consultados: le recuerdan al asesor de qué venían
                                         hablando, sin salir del chat. -->
                                    <div v-if="inmueblesPorLead[lead.id]" class="mt-2 flex flex-col gap-1 border-t border-gray-100 pt-2 dark:border-gray-800">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                                            Inmuebles de interés
                                        </p>
                                        <div v-for="inm in inmueblesPorLead[lead.id]" :key="inm.codigo"
                                             class="flex items-baseline justify-between gap-2 text-[11px]">
                                            <span class="truncate text-gray-700 dark:text-gray-300">
                                                <b>@{{ inm.codigo }}</b> · @{{ inm.zona }}
                                            </span>
                                            <span class="shrink-0 tabular-nums text-gray-500">
                                                @{{ inm.moneda }} @{{ Number(inm.precio).toLocaleString('es-BO') }}
                                            </span>
                                        </div>
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
                <x-admin::modal ref="productModal">
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
                <x-admin::modal ref="qrModal">
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
            <!-- Visor de imágenes: zoom / rotación / paneo / navegación -->
            <Teleport to="body">
                <div v-if="viewer.open" @click="closeLightbox" @wheel.prevent="viewerWheel"
                     class="wa-viewer" role="dialog" aria-modal="true" aria-label="Visor de imagen">

                    <!-- barra de herramientas -->
                    <div class="wa-toolbar" @click.stop>
                        <button type="button" class="wa-tbtn" @click="viewerZoom(-0.25)" aria-label="Alejar" title="Alejar (−)">
                            <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </button>
                        <span class="wa-tpct">@{{ Math.round(viewer.scale * 100) }}%</span>
                        <button type="button" class="wa-tbtn" @click="viewerZoom(0.25)" aria-label="Acercar" title="Acercar (+)">
                            <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/><line x1="11" y1="8" x2="11" y2="14"/></svg>
                        </button>

                        <span class="wa-tsep"></span>

                        <button type="button" class="wa-tbtn" @click="viewerRotate(-90)" aria-label="Rotar a la izquierda" title="Rotar izquierda">
                            <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        </button>
                        <button type="button" class="wa-tbtn" @click="viewerRotate(90)" aria-label="Rotar a la derecha" title="Rotar derecha">
                            <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        </button>
                        <button type="button" class="wa-tbtn" @click="viewerReset" aria-label="Restablecer vista" title="Ajustar / Restablecer">
                            <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M8 21H5a2 2 0 0 1-2-2v-3m18 0v3a2 2 0 0 1-2 2h-3"/></svg>
                        </button>

                        <span class="wa-tsep"></span>

                        <a class="wa-tbtn" :href="viewer.list[viewer.index]" download target="_blank" @click.stop aria-label="Descargar imagen" title="Descargar">
                            <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        </a>
                        <button type="button" class="wa-tbtn wa-tbtn--close" @click="closeLightbox" aria-label="Cerrar visor" title="Cerrar (Esc)">
                            <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <!-- contador -->
                    <div v-if="viewer.list.length > 1" class="wa-counter" @click.stop>
                        @{{ viewer.index + 1 }} / @{{ viewer.list.length }}
                    </div>

                    <!-- anterior / siguiente -->
                    <button v-if="viewer.list.length > 1" type="button" class="wa-nav wa-nav--prev" @click.stop="viewerPrev" aria-label="Anterior">
                        <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button v-if="viewer.list.length > 1" type="button" class="wa-nav wa-nav--next" @click.stop="viewerNext" aria-label="Siguiente">
                        <svg class="wa-vicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>

                    <!-- la imagen. @click.stop para que ampliarla no cierre el visor -->
                    <img class="wa-viewer__img" :class="viewer.scale > 1 ? (viewer.dragging ? 'is-grabbing' : 'is-grab') : ''"
                         :src="viewer.list[viewer.index]" :style="viewerStyle()" @click.stop @mousedown="viewerDragStart"
                         alt="imagen ampliada" draggable="false">

                    <!-- pie -->
                    <div v-if="viewerCaption()" class="wa-caption" @click.stop>@{{ viewerCaption() }}</div>
                </div>
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
                galeriaUrl: { type: String, default: '' },
                ubicacionUrl: { type: String, default: '' },
                inmueblesDeLeadsUrl: { type: String, default: '' },
                agentUrlBase: { type: String, default: '' },
                quickRepliesUrl: { type: String, default: '' },
                personUrlBase: { type: String, default: '' },
                productsUrl: { type: String, default: '' },
                // Page mode: fill the parent instead of a bordered 440px card.
                fullHeight: { type: Boolean, default: false },
            },

            data() {
                return {
                    messages: [],
                    draft: '',
                    isLoading: false,
                    aiEnabled: false,
                    replyingTo: null,
                    showAttach: false,
                    enviandoAdjunto: false,

                    viewer: {
                        open: false,
                        list: [],      // urls por las que navegan ‹ ›
                        index: 0,
                        scale: 1,
                        rotation: 0,
                        tx: 0,         // desplazamiento del paneo
                        ty: 0,
                        dragging: false,
                        startX: 0,
                        startY: 0,
                    },

                    // Inmuebles consultados, por id de lead. Se piden aparte
                    // porque el módulo de WhatsApp no conoce el catálogo.
                    inmueblesPorLead: {},
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
                    // Derivación a un asesor. Se refresca en cada poll porque
                    // la abre el agente IA, no el asesor que está mirando.
                    handoff: { state: 'ninguno', open: false, tomado: false, reason: null,
                               referencia: null, asesor: null, is_mine: false,
                               opened_at: null, taken_at: null },
                    handoffBusy: false,
                    handoffError: null,
                    // Al cerrar, devolver la conversación a la IA por defecto.
                    reactivarIa: true,
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
                        { id: 'imagenes', label: 'Imágenes' },
                        { id: 'documentos', label: 'Docs' },
                        { id: 'enlaces', label: 'Enlaces' },
                        { id: 'productos', label: 'Productos' },
                        { id: 'pedidos', label: 'Pedidos' },
                    ],
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
                /*
                | Hace cuánto espera el cliente. Un timestamp absoluto obliga al
                | asesor a hacer la resta mentalmente; "hace 3 h" se lee de un
                | vistazo, que es lo que decide si esto es urgente o no.
                |
                | Se recalcula en cada poll porque `handoff` se reasigna entero:
                | no hace falta un timer aparte para que el texto envejezca.
                */
                handoffEspera() {
                    const desde = this.handoff.taken_at || this.handoff.opened_at;
                    if (!desde) return '';

                    const mins = Math.max(0, Math.round((Date.now() - new Date(desde)) / 60000));
                    if (mins < 1) return 'recién';
                    if (mins < 60) return `hace ${mins} min`;

                    const horas = Math.floor(mins / 60);
                    if (horas < 24) return `hace ${horas} h`;

                    const dias = Math.floor(horas / 24);
                    return `hace ${dias} ${dias === 1 ? 'día' : 'días'}`;
                },

                initials() {
                    return (this.context.name || '?')
                        .split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
                },

                canAttach() {
                    return ['image', 'document', 'audio'].some(type => this.canSend(type));
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
                        return true;
                    });
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
                if (waProfilePref && this.context.personId && !this.useMock && window.innerWidth >= 1024) {
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
                /*
                 * Con el visor abierto el teclado es suyo: navega, hace zoom y
                 * rota. Cerrado, Esc cierra los popovers y el panel del cliente.
                 */
                this.escHandler = (event) => {
                    if (this.viewer.open) {
                        if (event.key === 'Escape')     { this.closeLightbox(); return; }
                        if (event.key === 'ArrowLeft')  { this.viewerPrev(); return; }
                        if (event.key === 'ArrowRight') { this.viewerNext(); return; }
                        if (event.key === '+' || event.key === '=') { this.viewerZoom(0.25); return; }
                        if (event.key === '-')          { this.viewerZoom(-0.25); return; }
                        if (event.key.toLowerCase() === 'r') { this.viewerRotate(90); return; }
                        return;
                    }

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

                    this.agentToggling = true;
                    this.$axios.patch(`${this.agentUrlBase}/${this.conversationId}/agent`, { enabled })
                        .then(res => {
                            this.aiEnabled = res.data?.agent?.effective ?? enabled;
                            // Show the reminder only when the human just took over.
                            this.agentNote = !this.aiEnabled;
                            if (this.aiEnabled) this.showAttach = false;
                            // The composer just unlocked: put the caret there.
                            if (!this.aiEnabled) this.focusComposer();
                        })
                        .catch(() => {})
                        .finally(() => { this.agentToggling = false; });
                },

                claimHandoff() {
                    if (!this.conversationId || this.handoffBusy) return;

                    this.handoffBusy = true;
                    this.handoffError = null;

                    this.$axios.post(`${this.agentUrlBase}/${this.conversationId}/handoff/claim`)
                        .then(res => {
                            if (res.data?.handoff) this.handoff = res.data.handoff;
                            if (res.data?.agent) this.aiEnabled = res.data.agent.effective;
                            // El asesor va a escribir ahora mismo: dejale el cursor puesto.
                            this.focusComposer();
                        })
                        .catch(err => {
                            // 409 = llegó segundo. Mostramos el estado real que
                            // devuelve el servidor en vez de dejar la UI mintiendo.
                            this.handoffError = err.response?.data?.message ?? 'No se pudo tomar la conversación.';
                            if (err.response?.data?.handoff) this.handoff = err.response.data.handoff;
                        })
                        .finally(() => { this.handoffBusy = false; });
                },

                resolveHandoff() {
                    if (!this.conversationId || this.handoffBusy) return;

                    this.handoffBusy = true;
                    this.handoffError = null;

                    this.$axios.post(`${this.agentUrlBase}/${this.conversationId}/handoff/resolve`, {
                        reactivar_ia: this.reactivarIa,
                    })
                        .then(res => {
                            if (res.data?.handoff) this.handoff = res.data.handoff;
                            if (res.data?.agent) this.aiEnabled = res.data.agent.effective;
                            // Si la IA volvió a atender, el recordatorio de
                            // "reactivá el agente" ya no aplica.
                            this.agentNote = !this.aiEnabled;
                        })
                        .catch(err => {
                            this.handoffError = err.response?.data?.message ?? 'No se pudo cerrar la derivación.';
                            if (err.response?.data?.handoff) this.handoff = err.response.data.handoff;
                        })
                        .finally(() => { this.handoffBusy = false; });
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
                            if (res.data?.agent) this.aiEnabled = res.data.agent.effective;
                            if (res.data?.handoff) this.handoff = res.data.handoff;

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
                    if (!this.context.personId || !this.personUrlBase) return;

                    this.profileOpen = !this.profileOpen;

                    if (this.profileOpen) this.fetchProfile();
                },

                fetchProfile() {
                    if (this.profile) return; // already loaded for this contact

                    this.profileLoading = true;
                    this.$axios.get(`${this.personUrlBase}/${this.context.personId}`)
                        .then(res => {
                            this.profile = res.data?.person ?? null;
                            this.cargarInmueblesDeLeads();
                        })
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

                /* ---------------- Visor de imágenes ---------------- */

                // Todas las imágenes del hilo, para pasar de una a otra con las
                // flechas sin cerrar el visor.
                chatImageUrls() {
                    return this.messages
                        .filter(m => m.type === 'image' && m.media && m.media.url)
                        .map(m => m.media.url);
                },

                // Solo las imágenes de la galería del perfil: un video en la
                // lista de navegación dejaría al visor mostrando un cuadro roto.
                profileImageUrls() {
                    return (this.profile?.images ?? [])
                        .filter(x => x.type !== 'video')
                        .map(x => x.url);
                },

                openLightbox(url, list = null) {
                    if (! url) return;

                    const urls = (Array.isArray(list) && list.length) ? list : [url];
                    const index = Math.max(0, urls.indexOf(url));

                    this.viewer = {
                        open: true, list: urls, index,
                        scale: 1, rotation: 0, tx: 0, ty: 0,
                        dragging: false, startX: 0, startY: 0,
                    };
                },

                closeLightbox() {
                    this.viewer.open = false;
                },

                // El pie de la imagen abierta: un mensaje de imagen puede llevar
                // caption. Las de la galería del perfil no tienen, así que ahí
                // no se muestra nada.
                viewerCaption() {
                    const url = this.viewer.list[this.viewer.index];
                    const m = this.messages.find(x => x.media && x.media.url === url && x.text);

                    return m ? m.text : null;
                },

                viewerStyle() {
                    const v = this.viewer;

                    return {
                        transform: `translate(${v.tx}px, ${v.ty}px) scale(${v.scale}) rotate(${v.rotation}deg)`,
                        // Sin transición mientras se arrastra: el suavizado hace
                        // que el paneo se sienta pegajoso.
                        transition: v.dragging ? 'none' : 'transform .15s ease',
                    };
                },

                viewerZoom(delta) {
                    this.viewer.scale = Math.min(6, Math.max(0.25, +(this.viewer.scale + delta).toFixed(2)));

                    // Al volver al 100% se recentra: si no, la imagen queda
                    // corrida y no hay forma de arrastrarla de vuelta.
                    if (this.viewer.scale === 1) { this.viewer.tx = 0; this.viewer.ty = 0; }
                },

                viewerWheel(event) {
                    this.viewerZoom(event.deltaY < 0 ? 0.2 : -0.2);
                },

                viewerRotate(deg) {
                    this.viewer.rotation += deg;
                },

                viewerReset() {
                    Object.assign(this.viewer, { scale: 1, rotation: 0, tx: 0, ty: 0 });
                },

                viewerPrev() {
                    if (this.viewer.list.length < 2) return;

                    this.viewer.index = (this.viewer.index - 1 + this.viewer.list.length) % this.viewer.list.length;
                    this.viewerReset();
                },

                viewerNext() {
                    if (this.viewer.list.length < 2) return;

                    this.viewer.index = (this.viewer.index + 1) % this.viewer.list.length;
                    this.viewerReset();
                },

                /**
                 * Arrastrar para desplazar la imagen ampliada.
                 *
                 * Los listeners van en `document`, no en la imagen: si el cursor
                 * se sale de ella mientras se arrastra, el movimiento tiene que
                 * seguir. Enganchados a la imagen, el paneo se corta apenas el
                 * puntero cruza el borde.
                 */
                viewerDragStart(event) {
                    if (this.viewer.scale <= 1) return;

                    event.preventDefault();
                    this.viewer.dragging = true;
                    this.viewer.startX = event.clientX - this.viewer.tx;
                    this.viewer.startY = event.clientY - this.viewer.ty;

                    this._viewerDrag = (e) => {
                        if (! this.viewer.dragging) return;

                        this.viewer.tx = e.clientX - this.viewer.startX;
                        this.viewer.ty = e.clientY - this.viewer.startY;
                    };

                    this._viewerDragEnd = () => {
                        this.viewer.dragging = false;
                        document.removeEventListener('mousemove', this._viewerDrag);
                        document.removeEventListener('mouseup', this._viewerDragEnd);
                    };

                    document.addEventListener('mousemove', this._viewerDrag);
                    document.addEventListener('mouseup', this._viewerDragEnd);
                },

                // Nombre en castellano del tipo, para los avisos del hilo.
                etiquetaMedia(tipo) {
                    return {
                        image: 'la imagen', video: 'el video', audio: 'el audio',
                        document: 'el documento', sticker: 'el sticker',
                    }[tipo] || 'el archivo';
                },

                elegirArchivo() {
                    this.showAttach = false;
                    this.$refs.fileInput.click();
                },

                /*
                 * Sube el archivo y lo envía de verdad.
                 *
                 * Antes esto empujaba un marcador de posición al hilo con estado
                 * "enviado": el asesor creía haber mandado algo que el cliente
                 * nunca recibía. El servidor sube el archivo al proveedor ANTES
                 * de responder, así que un error llega mientras el asesor
                 * todavía está mirando la pantalla.
                 */
                subirArchivo(event) {
                    const archivo = event.target.files[0];
                    event.target.value = '';

                    if (! archivo) return;

                    const form = new FormData();
                    form.append('archivo', archivo);
                    form.append('phone', this.context.phone);
                    form.append('name', this.context.name || '');

                    this.enviandoAdjunto = true;

                    this.$axios.post(this.sendMediaUrl, form)
                        .then(res => {
                            const real = res.data?.message;
                            if (real) this.messages.push(real);
                            this.scrollToBottom();
                        })
                        .catch(err => {
                            alert(err.response?.data?.message || 'No se pudo enviar el archivo.');
                        })
                        .finally(() => {
                            this.enviandoAdjunto = false;
                        });
                },

                /*
                 * Manda la galería del inmueble que el cliente está mirando.
                 *
                 * El asesor escribe el código que ya apareció en la conversación.
                 * Los mensajes se crean del lado del servidor —uno por foto— así
                 * que el hilo se recarga en vez de empujarlos a mano.
                 */
                /*
                 * Pide los inmuebles consultados de los leads que se están
                 * mostrando. Va en una llamada aparte —y no dentro del perfil—
                 * porque el módulo de WhatsApp lo comparte otro tenant que no
                 * tiene catálogo inmobiliario y no debe saber que existe.
                 */
                cargarInmueblesDeLeads() {
                    const ids = (this.profile?.leads || []).map(l => l.id);

                    if (! ids.length || ! this.inmueblesDeLeadsUrl) {
                        this.inmueblesPorLead = {};

                        return;
                    }

                    this.$axios.post(this.inmueblesDeLeadsUrl, { lead_ids: ids })
                        .then(res => { this.inmueblesPorLead = res.data?.inmuebles || {}; })
                        // Silencioso a propósito: es información de apoyo. Un
                        // error acá no debe tapar el panel del cliente.
                        .catch(() => { this.inmueblesPorLead = {}; });
                },

                /*
                 * Manda la ubicación del inmueble que se está conversando.
                 *
                 * El asesor escribe el código que ya apareció en el chat. Es el
                 * caso real de esta plaza: la ubicación que hace falta compartir
                 * casi siempre es la de una propiedad del catálogo, no un punto
                 * cualquiera del mapa.
                 */
                enviarUbicacion() {
                    this.showAttach = false;

                    const codigo = window.prompt('Código del inmueble cuya ubicación querés enviar (ej. C21-0001):');

                    if (! codigo || ! codigo.trim()) return;

                    this.enviandoAdjunto = true;

                    this.$axios.post(this.ubicacionUrl, {
                            conversation_id: this.conversationId,
                            codigo: codigo.trim(),
                        })
                        .then(() => this.fetchThread())
                        .catch(err => {
                            alert(err.response?.data?.message || 'No se pudo enviar la ubicación.');
                        })
                        .finally(() => {
                            this.enviandoAdjunto = false;
                        });
                },

                enviarGaleria() {
                    this.showAttach = false;

                    const codigo = window.prompt('Código del inmueble cuyas fotos querés enviar (ej. C21-0001):');

                    if (! codigo || ! codigo.trim()) return;

                    this.enviandoAdjunto = true;

                    this.$axios.post(this.galeriaUrl, {
                            conversation_id: this.conversationId,
                            codigo: codigo.trim(),
                            tipo: 'foto',
                        })
                        .then(() => this.fetchThread())
                        .catch(err => {
                            alert(err.response?.data?.message || 'No se pudieron enviar las fotos.');
                        })
                        .finally(() => {
                            this.enviandoAdjunto = false;
                        });
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
