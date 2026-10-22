@php
    /*
     * El símbolo de la moneda del CRM (config('app.currency')). Antes estaba
     * escrito "$" a mano en cada monto, que en una instalación que cobra en
     * bolivianos es un dato equivocado, no un detalle de estilo.
     */
    $waMoneda = \Webkul\Whatsapp\Support\Currency::symbol();
@endphp

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
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-900 max-lg:flex-wrap max-lg:gap-2 max-lg:px-3 max-lg:py-2">
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
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
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

                <!-- AI agent switch. Celular: pasa a su propia fila, a la derecha. -->
                <label class="flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-300 max-lg:ml-auto"
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
                 :class="fullHeight ? 'flex-1' : 'h-[440px] max-lg:h-[60dvh]'">
                <div v-if="isLoading" class="m-auto flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <x-admin::spinner /> <span>Cargando conversación…</span>
                </div>

<!--
                    Un error del hilo va ANTES de "sin mensajes": las dos
                    situaciones se veían igual, y "Sin mensajes todavía" sobre una
                    conversación que no se pudo cargar es una mentira que manda al
                    asesor a escribirle al cliente creyendo que el historial está
                    vacío.
                -->
                <div v-else-if="threadError" class="m-auto max-w-xs text-center">
                    <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">No se pudo abrir la conversación</div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">@{{ threadError }}</div>
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
                                <img v-if="msg.media" :src="msg.media.url"
                                     @click="openLightbox(msg.media.url, chatImageUrls())"
                                     class="wa-zoomable max-h-56 rounded-md" alt="imagen">
                                <div v-if="msg.text" class="mt-1 whitespace-pre-wrap break-words">@{{ msg.text }}</div>
                            </div>

                            <!--
                                Toda burbuja con archivo va guardada por `msg.media`.
                                Un mensaje multimedia puede existir SIN archivo: la
                                referencia de Meta caduca, y hasta que existió la
                                descarga automática ninguna media entrante se guardó.
                                Sin la guarda, `msg.media.url` sobre null tira
                                TypeError en el render y la conversación entera
                                queda en blanco — no solo esa burbuja.
                            -->
                            <div v-else-if="msg.type === 'sticker'">
                                <img v-if="msg.media" :src="msg.media.url" class="h-28 w-28 object-contain" alt="sticker">
                                <span v-else class="text-xs italic text-gray-400">Sticker no disponible</span>
                            </div>

                            <div v-else-if="msg.type === 'audio'" class="min-w-[220px]">
                                <audio v-if="msg.media" controls :src="msg.media.url" class="w-full"></audio>
                                <span v-else class="text-xs italic text-gray-400">Nota de voz no disponible</span>
                            </div>

                            <div v-else-if="msg.type === 'video'">
                                <video v-if="msg.media" controls :src="msg.media.url" class="max-h-56 rounded-md"></video>
                                <span v-else class="text-xs italic text-gray-400">Video no disponible</span>
                            </div>

                            <component :is="msg.media ? 'a' : 'div'" v-else-if="msg.type === 'document'"
                                       :href="msg.media ? msg.media.url : null"
                                       :target="msg.media ? '_blank' : null" rel="noopener"
                                       class="flex items-center gap-2 rounded-md bg-black/5 px-2 py-2 dark:bg-white/10">
                                <span class="icon-file text-xl"></span>
                                <span class="flex flex-col">
                                    <span class="font-medium">@{{ (msg.media && msg.media.filename) || 'Documento' }}</span>
                                    <span v-if="msg.media" class="text-[11px] text-gray-500 dark:text-gray-300">@{{ msg.media.meta }}</span>
                                    <span v-else class="text-[11px] italic text-gray-400">Archivo no disponible</span>
                                </span>
                            </component>

                            <div v-else-if="msg.type === 'contact'" class="min-w-[200px]">
                                <div class="flex items-center gap-2 rounded-md bg-black/5 px-2 py-2 dark:bg-white/10">
                                    <span class="icon-user text-xl"></span>
                                    <span class="flex flex-col">
                                        <span class="font-medium">@{{ msg.contact.name }}</span>
                                        <span class="text-[11px] text-gray-500 dark:text-gray-300">@{{ msg.contact.phone }}</span>
                                    </span>
                                </div>
                            </div>

                            <!--
                                Location. Rendered as a link to Maps rather than
                                an embedded map: a tile per bubble would hit the
                                tile server once for every pin in the thread.
                            -->
                            <!--
                                Ubicación: el mapa ES el mensaje, abierto y
                                dentro del hilo. Sin clic previo ni modal: un
                                par de coordenadas no le dice nada a nadie, y
                                obligar a abrir algo para ver dónde queda un
                                punto es un paso de más en medio de una
                                conversación.

                                Se monta recién cuando entra en pantalla (ver
                                registerBubbleMap), para no levantar veinte
                                mapas de golpe al abrir un chat largo.
                            -->
                            <!--
                                Las medidas van en `style` y no en clases de
                                Tailwind con valor arbitrario (h-[220px]) a
                                propósito: esas clases sólo existen si el CSS se
                                recompiló, y con el CSS viejo la clase no aplica
                                nada -> altura 0 -> Leaflet mide 0px, centra el
                                marcador fuera de la caja y el mapa no se ve.
                                Acá la altura no es decoración: es un requisito
                                para que el mapa funcione.
                            -->
                            <div v-else-if="msg.type === 'location'" style="width: min(340px, 70vw)">
                                <template v-if="msg.location">
                                    <!--
                                        `isolate`: Leaflet reparte z-index de 400 a 1000 entre sus
                                        panes y controles, y `.leaflet-container` no crea stacking
                                        context, asi que sin esto esos 1000 compiten en el contexto
                                        raiz. Hoy no se nota porque los modales del admin estan en
                                        10002, pero cualquier overlay por debajo de 1000 quedaria
                                        tapado por los tiles del mapa.
                                    -->
                                    <div :ref="el => registerBubbleMap(el, msg)"
                                         style="height: 220px; width: 100%"
                                         class="isolate overflow-hidden rounded-lg border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-700"></div>

                                    <div class="mt-1.5 flex items-start gap-2">
                                        <span class="icon-location shrink-0 text-xl text-brandColor"></span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate font-medium">@{{ msg.location.name || 'Ubicación' }}</p>
                                            <p v-if="msg.location.address"
                                               class="truncate text-[11px] text-gray-500 dark:text-gray-300">@{{ msg.location.address }}</p>
                                            <p class="font-mono text-[11px] text-gray-400">
                                                @{{ msg.location.lat.toFixed(5) }}, @{{ msg.location.lng.toFixed(5) }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-1 flex items-center gap-3 text-[11px]">
                                        <button type="button" @click="copiarCoords(msg)"
                                                class="text-brandColor hover:underline">
                                            @{{ coordsCopiadas === msg.id ? 'Copiado' : 'Copiar coordenadas' }}
                                        </button>
                                        <a :href="msg.location.url" target="_blank" rel="noopener"
                                           class="text-brandColor hover:underline">Abrir en Google Maps</a>
                                    </div>
                                </template>

                                <div v-else class="flex items-center gap-2 rounded-lg border border-gray-200 px-2.5 py-2 dark:border-gray-700">
                                    <span class="icon-location shrink-0 text-xl text-gray-400"></span>
                                    <span class="italic text-gray-500">Ubicación sin coordenadas</span>
                                </div>
                            </div>

                            <!--
                                Menú tocable: lo que se le OFRECIÓ al cliente.
                                Antes caía en el v-else de abajo y el asesor
                                leía "Tipo no soportado" donde el agente había
                                ofrecido siete ciudades. Al retomar una
                                conversación derivada, eso es justo el contexto
                                que necesita.
                            -->
                            <div v-else-if="msg.type === 'interactive'" class="min-w-[220px]">
                                <!--
                                    La RESPUESTA del cliente: tocó un botón o una fila.

                                    Antes caía en el v-else de abajo y mostraba "Menú enviado"
                                    —el asesor leía que se había mandado un menú justo donde el
                                    cliente había contestado—. Se marca como toque y no como
                                    texto escrito, porque no es lo mismo: el id de la opción es
                                    lo que el agente rutea, y los títulos se repiten entre menús.

                                    Comentario HTML y no Blade: dentro del x-template dos llaves
                                    seguidas son sintaxis de Blade Y de Vue, y romperlo deja el
                                    componente en blanco sin ningún error. Hay un test que lo vigila.
                                -->
                                <div v-if="msg.interactive && msg.interactive.kind === 'seleccion'">
                                    <div class="flex items-center gap-1.5">
                                        <svg class="h-4 w-4 shrink-0 opacity-60" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/>
                                        </svg>
                                        <span class="text-xs uppercase tracking-wide opacity-60">Eligió</span>
                                    </div>
                                    <div class="break-words text-base font-semibold">@{{ msg.interactive.seleccion.title }}</div>
                                </div>

                                <div v-else-if="msg.interactive" class="whitespace-pre-wrap break-words"
                                     v-html="formatText(msg.interactive.body)"></div>
                                <div v-else class="italic text-gray-500">Menú enviado</div>

                                <div v-if="msg.interactive && msg.interactive.kind !== 'seleccion'" class="mt-1.5 border-t border-gray-200 pt-1.5 dark:border-gray-600">
                                    <!-- Botones: se ven de una, como los ve el cliente. -->
                                    <div v-if="msg.interactive.kind === 'buttons'" class="flex flex-col gap-1">
                                        <span v-for="opt in msg.interactive.options" :key="opt.id"
                                              class="rounded border border-gray-300 px-2 py-1 text-center text-xs text-brandColor dark:border-gray-600">
                                            @{{ opt.title }}
                                        </span>
                                    </div>

                                    <!-- Lista: el label del menú y las opciones en línea. -->
                                    <div v-else>
                                        <span class="flex items-center gap-1.5 text-xs font-medium text-brandColor">
                                            <span class="icon-menu shrink-0"></span>
                                            @{{ msg.interactive.button || 'Ver opciones' }}
                                            <span class="text-gray-400">(@{{ msg.interactive.options.length }})</span>
                                        </span>
                                        <p class="mt-0.5 text-[11px] leading-snug text-gray-500 dark:text-gray-300">
                                            @{{ msg.interactive.options.map(o => o.title).join(' · ') }}
                                        </p>
                                    </div>

                                    <p v-if="msg.interactive.footer"
                                       class="mt-1 text-[11px] text-gray-400">@{{ msg.interactive.footer }}</p>
                                </div>
                            </div>

                            <!--
                                Reacción: el emoji que el cliente puso sobre un
                                mensaje nuestro. Caía en el v-else de abajo y se
                                leía "Tipo no soportado" — el asesor no tenía
                                forma de saber que el cliente había respondido.

                                La cita del mensaje reaccionado la dibuja el
                                bloque `msg.replyTo` de más arriba: el gateway
                                mapea reaction.message_id a replyTo justamente
                                para no tener dos caminos para lo mismo.
                            -->
                            <div v-else-if="msg.type === 'reaction'" class="flex items-center gap-2">
                                <span v-if="msg.reaction && msg.reaction.emoji" class="text-2xl leading-none">@{{ msg.reaction.emoji }}</span>
                                <span class="text-xs italic text-gray-500 dark:text-gray-300">
                                    @{{ (msg.reaction && msg.reaction.removed) ? 'quitó su reacción' : 'reaccionó' }}
                                </span>
                            </div>

                            <!--
                                Plantilla. `msg.text` es el body ya renderizado con sus
                                variables, que es lo que el cliente recibe.

                                Sin esta rama toda plantilla enviada caía en el v-else y
                                el asesor leía "Tipo no soportado" sobre un mensaje que él
                                mismo acababa de mandar.
                            -->
                            <div v-else-if="msg.type === 'template'">
                                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide opacity-60">Plantilla</span>
                                <div class="whitespace-pre-wrap break-words" v-html="formatText(msg.text)"></div>
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
            <div class="border-t border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900 max-lg:pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
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
                            class="max-h-28 flex-1 resize-none rounded-2xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-gray-800 max-lg:text-base focus:border-amber-500 focus:outline-none dark:border-amber-700 dark:bg-amber-900/20 dark:text-gray-100"
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

                <!--
                    Derivación abierta. Va ANTES que el aviso del agente porque
                    lo reemplaza: si la conversación fue derivada, el agente ya
                    está apagado y lo que importa es quién la debe atender.
                -->
                <div v-if="handoff.open"
                     class="mb-2 rounded-md px-3 py-2 text-xs"
                     :class="handoff.pooled
                        ? 'bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300'
                        : 'bg-sky-50 text-sky-800 dark:bg-sky-900/20 dark:text-sky-300'">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                                  :class="handoff.pooled ? 'bg-amber-200 text-amber-900 dark:bg-amber-800 dark:text-amber-100'
                                                         : 'bg-sky-200 text-sky-900 dark:bg-sky-800 dark:text-sky-100'">
                                @{{ handoff.pooled ? 'SIN ASESOR' : 'DERIVADA' }}
                            </span>

                            <span v-if="handoff.city" class="font-semibold">@{{ handoff.city }}</span>

                            <span v-if="handoff.assigned_user">
                                · @{{ handoff.is_mine ? 'Te toca a vos' : 'Atiende ' + handoff.assigned_user.name }}
                            </span>
                            <span v-else>· nadie la tomó todavía</span>

                            <span v-if="handoffWaiting" class="opacity-70">· hace @{{ handoffWaiting }}</span>
                        </span>

                        <span class="flex shrink-0 gap-1.5 max-lg:w-full max-lg:[&>button]:h-10 max-lg:[&>button]:flex-1">
                            <button v-if="handoff.pooled" type="button" @click="claimHandoff" :disabled="handoffBusy"
                                    class="rounded-md bg-amber-600 px-2.5 py-1 font-semibold text-white transition hover:bg-amber-700 disabled:opacity-50">
                                Tomar conversación
                            </button>
                            <button type="button" @click="resolveHandoff" :disabled="handoffBusy"
                                    class="rounded-md border border-current px-2.5 py-1 font-semibold transition hover:bg-black/5">
                                Cerrar derivación
                            </button>
                        </span>
                    </div>

                    <p v-if="handoff.reason" class="mt-1 opacity-90">
                        <span class="font-semibold">Motivo:</span> @{{ handoff.reason }}
                    </p>
                </div>

                <div v-if="!context.phone" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    Este contacto no tiene número de WhatsApp. Asigna un teléfono para poder chatear.
                </div>

                <div v-else-if="aiEnabled" class="flex items-center gap-2 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
                    <span class="rounded bg-emerald-200 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-800 dark:text-emerald-100">IA</span>
                    <span>El agente IA está atendiendo esta conversación. <button class="font-semibold underline" @click="toggleAgent(false)">Desactivalo</button> para escribir a mano.</span>
                </div>

                <!--
                    Window closed. The old copy told the advisor to use a
                    template and then gave them no way to do it: the composer,
                    action menu included, is replaced by this box. Hence the
                    button — it is the only path out of here.
                -->
                <div v-else-if="window.applies && !window.open"
                     class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">
                    <span>Ventana de 24h cerrada. Hasta que el cliente vuelva a escribir, solo podés contactarlo con una plantilla aprobada.</span>

                    <button v-if="canSend('template')" type="button" @click="openTemplates"
                            class="flex shrink-0 items-center gap-1.5 rounded-md bg-red-600 px-2.5 py-1.5 font-semibold text-white transition hover:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                        <span class="icon-sent"></span> Elegir plantilla
                    </button>
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

                    <!--
                        Action menu. Every entry is rendered from the gateway's
                        declared capabilities, never hard-coded: an option that
                        is visible must actually work, or the advisor sends into
                        a 501 they cannot interpret.
                    -->
                    <div v-if="canAct" class="js-pop relative">
                        <button type="button" @click="showAttach = !showAttach"
                                aria-label="Adjuntar o enviar"
                                title="Adjuntar archivo, ubicación o plantilla"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:hover:bg-gray-700">
                            <span class="icon-attachment text-xl"></span>
                        </button>

                        <div v-if="showAttach" class="absolute bottom-11 left-0 z-10 w-48 rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <button v-for="opt in attachOptions" :key="opt.kind" type="button"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700"
                                    @click="pickFile(opt.kind)">
                                <span :class="opt.icon"></span> @{{ opt.label }}
                            </button>

                            <div v-if="attachOptions.length && (canSend('location') || canSend('template'))"
                                 class="my-1 border-t border-gray-100 dark:border-gray-700"></div>

                            <button v-if="canSend('location')" type="button"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700"
                                    @click="openLocation">
                                <span class="icon-location"></span> Ubicación
                            </button>

                            <!-- Also with the window open: a template is a fine
                                 way to open a formal topic, not just a fallback. -->
                            <button v-if="canSend('template')" type="button"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-700"
                                    @click="openTemplates">
                                <span class="icon-sent"></span> Plantilla
                            </button>
                        </div>

                        <!--
                            ONE input whose `accept` changes with the chosen
                            kind, not one per kind.

                            In Vue 3 a string `ref` inside a v-for does NOT
                            collect the elements into $refs: that was Vue 2
                            behaviour. `$refs['file-image']` came back undefined,
                            the optional chaining swallowed it, and clicking
                            "Imagen" did nothing at all — no dialog, no error.
                        -->
                        <input ref="fileInput" type="file" class="hidden"
                               :accept="pendingAccept"
                               @change="stageFile">
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
                        class="max-h-28 flex-1 resize-none rounded-2xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 max-lg:text-base focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
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
                                    <span v-if="profile.active_lead.value" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $waMoneda }} @{{ profile.active_lead.value }}</span>
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
                                    <p class="truncate text-base font-bold text-brandColor">{{ $waMoneda }} @{{ profile.leads_total }}</p>
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
                                <!--
                                    Los videos siguen abriéndose en una pestaña
                                    nueva: el visor hace zoom y rotación, que a
                                    un video no le sirven de nada.
                                -->
                                <component :is="img.type === 'video' ? 'a' : 'button'"
                                           v-for="(img, i) in profile.images" :key="'img-' + i"
                                           :type="img.type === 'video' ? null : 'button'"
                                           :href="img.type === 'video' ? img.url : null"
                                           :target="img.type === 'video' ? '_blank' : null"
                                           rel="noopener"
                                           :title="(img.mine ? 'Enviado' : 'Recibido') + ' · ' + img.date"
                                           class="relative block w-full"
                                           @click="img.type === 'video' ? null : openLightbox(img.url, profileImageUrls())">
                                    <!-- Un video no se puede pintar con <img>: se
                                         muestra el primer frame con el propio
                                         <video>, sin controles ni descarga. -->
                                    <video v-if="img.type === 'video'" :src="img.url" preload="metadata"
                                           class="h-20 w-full rounded-md object-cover transition hover:opacity-80"></video>
                                    <img v-else :src="img.url" alt="archivo compartido"
                                         class="h-20 w-full rounded-md object-cover transition hover:opacity-80">

                                    <!-- SVG en vez de la fuente de iconos: no hay
                                         un icono de reproducción en esta fuente. -->
                                    <span v-if="img.type === 'video'"
                                          class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                        <svg viewBox="0 0 24 24" class="h-7 w-7 drop-shadow" fill="rgba(255,255,255,.9)" aria-hidden="true">
                                            <circle cx="12" cy="12" r="11" fill="rgba(0,0,0,.35)"/>
                                            <path d="M10 8.5v7l5.5-3.5z"/>
                                        </svg>
                                    </span>

                                    <!-- Quién lo mandó: en un comprobante es la
                                         diferencia entre "se lo enviamos" y
                                         "nos lo envió". -->
                                    <span class="absolute bottom-0 left-0 rounded-tr-md rounded-bl-md px-1 text-[9px] font-medium text-white"
                                          :class="img.mine ? 'bg-brandColor/80' : 'bg-gray-700/70'">
                                        @{{ img.mine ? '↑' : '↓' }}
                                    </span>
                                </component>
                            </div>
                            <p v-else class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-gray-700">
                                Sin imágenes ni videos en la conversación.
                            </p>
                        </template>

                        <!-- Documentos -->
                        <template v-else-if="profileTab === 'documentos'">
                            <div v-if="profile.documents.length" class="flex flex-col divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                <div v-for="(doc, i) in profile.documents" :key="'doc-' + i"
                                     class="px-2.5 py-2 transition hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <a :href="doc.url" target="_blank" rel="noopener" class="flex items-center gap-2">
                                        <!-- La fuente de iconos de este proyecto no
                                             trae micrófono; para audio va un SVG. -->
                                        <svg v-if="doc.type === 'audio'" viewBox="0 0 24 24"
                                             class="h-4 w-4 shrink-0 text-gray-400" fill="none"
                                             stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <rect x="9" y="2" width="6" height="11" rx="3"/>
                                            <path stroke-linecap="round" d="M5 11a7 7 0 0014 0M12 18v4"/>
                                        </svg>
                                        <span v-else class="icon-file text-lg text-gray-400"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-gray-800 dark:text-gray-200">@{{ doc.name }}</span>
                                            <span class="text-[11px] text-gray-400">
                                                @{{ doc.mine ? 'Enviado' : 'Recibido' }} · @{{ doc.date }}
                                            </span>
                                        </span>
                                    </a>

                                    <!-- Una nota de voz se escucha acá mismo: bajarla
                                         para oírla es una fricción que no hace falta. -->
                                    <audio v-if="doc.type === 'audio'" :src="doc.url" controls preload="none"
                                           class="mt-1.5 h-8 w-full"></audio>
                                </div>
                            </div>
                            <p v-else class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-gray-700">
                                Sin documentos ni audios compartidos.
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
                                        <span v-if="prod.amount" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $waMoneda }} @{{ prod.amount }}</span>
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-2 text-[11px] text-gray-400">
                                        <span>x@{{ prod.quantity }}</span>
                                        <span v-if="prod.price">· {{ $waMoneda }} @{{ prod.price }} c/u</span>
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
                                        <span v-if="lead.value" class="shrink-0 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $waMoneda }} @{{ lead.value }}</span>
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

            <!-- Location picker: browser GPS + a Leaflet map with a draggable pin -->
            <Teleport to="body">
                <x-admin::modal ref="locationModal">
                    <x-slot:header>
                        <h3 class="text-base font-semibold dark:text-white">Enviar ubicación</h3>
                    </x-slot>

                    <x-slot:content>
                        <div v-if="savedLocations.length" class="mb-3 flex flex-wrap gap-1.5">
                            <button v-for="(place, i) in savedLocations" :key="'sl-' + i" type="button"
                                    class="rounded-full border border-gray-200 px-2.5 py-1 text-xs transition hover:border-brandColor hover:text-brandColor dark:border-gray-700"
                                    @click="useSavedLocation(place)">
                                <span class="icon-location"></span> @{{ place.name }}
                            </button>
                        </div>

                        <div class="mb-2 flex items-center gap-2">
                            <button type="button" @click="useMyLocation" :disabled="locating"
                                    class="flex items-center gap-1.5 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs font-medium transition hover:border-brandColor hover:text-brandColor disabled:opacity-50 dark:border-gray-700">
                                <span class="icon-location"></span>
                                @{{ locating ? 'Buscando…' : 'Usar mi ubicación' }}
                            </button>
                            <span v-if="locationForm.accuracy" class="text-[11px] text-gray-500 dark:text-gray-400">
                                Precisión aprox. @{{ Math.round(locationForm.accuracy) }} m
                            </span>
                        </div>

                        <!--
                            A plain style attribute for the height, not a Tailwind
                            class: Leaflet renders into this node and measures it
                            on creation. Inside a modal that has just opened the
                            box can still be zero-height, and the map mounts
                            invisible without erroring.
                        -->
                        <div ref="mapBox" style="height: 260px"
                             class="w-full overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"></div>

                        <p v-if="mapError" class="mt-2 rounded-md bg-amber-50 px-3 py-1.5 text-xs text-amber-700 dark:bg-amber-900/25 dark:text-amber-300">
                            @{{ mapError }} Podés escribir las coordenadas a mano.
                        </p>
                        <p v-else class="mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                            Arrastrá el pin (o tocá el mapa) para ajustar el punto exacto.
                        </p>

                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <label class="block">
                                <span class="mb-1 block text-[11px] text-gray-500 dark:text-gray-400">Latitud</span>
                                <input v-model="locationForm.latitude" type="text" inputmode="decimal"
                                       @change="pinFromInputs"
                                       class="w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-[11px] text-gray-500 dark:text-gray-400">Longitud</span>
                                <input v-model="locationForm.longitude" type="text" inputmode="decimal"
                                       @change="pinFromInputs"
                                       class="w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            </label>
                        </div>

                        <input v-model="locationForm.label" type="text" placeholder="Nombre del lugar (opcional)"
                               class="mt-2 w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        <input v-model="locationForm.address" type="text" placeholder="Dirección (opcional)"
                               class="mt-2 w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">

                        <p class="mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                            Sin nombre ni dirección, WhatsApp muestra solo las coordenadas.
                        </p>

                        <div v-if="locationError" class="mt-2 rounded-md bg-red-50 px-3 py-1.5 text-xs text-red-700 dark:bg-red-900/25 dark:text-red-300">
                            @{{ locationError }}
                        </div>
                    </x-slot>

                    <x-slot:footer>
                        <button type="button" class="primary-button"
                                :disabled="locationSending || !hasPin" @click="sendLocation">
                            @{{ locationSending ? 'Enviando…' : 'Enviar ubicación' }}
                        </button>
                    </x-slot>
                </x-admin::modal>
            </Teleport>

            <!-- Template picker: the only way through a closed 24h window -->
            <Teleport to="body">
                <x-admin::modal ref="templateModal">
                    <x-slot:header>
                        <h3 class="text-base font-semibold dark:text-white">Enviar plantilla</h3>
                    </x-slot>

                    <x-slot:content>
                        <div v-if="templatesLoading" class="flex justify-center py-8">
                            <x-admin::spinner />
                        </div>

                        <p v-else-if="templatesReason" class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-sm text-gray-500 dark:border-gray-700">
                            @{{ templatesReason }}
                        </p>

                        <p v-else-if="!templates.length" class="rounded-lg border border-dashed border-gray-300 px-3 py-6 text-center text-sm text-gray-500 dark:border-gray-700">
                            No hay plantillas aprobadas en esta cuenta.<br>
                            <span class="text-xs text-gray-400">Se dan de alta en WhatsApp Manager; Meta tarda 24-48 h en aprobarlas.</span>
                        </p>

                        <template v-else>
                            <div class="max-h-56 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                <button v-for="t in templates" :key="t.name + t.language" type="button"
                                        class="flex w-full flex-col items-start px-3 py-2 text-left transition"
                                        :class="isPickedTemplate(t) ? 'bg-brandColor/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800'"
                                        @click="pickTemplate(t)">
                                    <span class="flex w-full items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">@{{ t.name }}</span>
                                        <span class="shrink-0 text-[11px] uppercase text-gray-400">@{{ t.language }}</span>
                                    </span>
                                    <span class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">@{{ t.body }}</span>
                                </button>
                            </div>

                            <div v-if="pickedTemplate" class="mt-3">
                                <!--
                                    Encabezado con archivo. La plantilla lo declara en Meta;
                                    sin él, el envío vuelve con 132012 y el asesor no tiene
                                    forma de adivinar que lo que faltaba era el documento.
                                -->
                                <div v-if="templateNeedsFile" class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-700 dark:bg-amber-900/20">
                                    <span class="mb-1 block text-[11px] font-medium text-amber-800 dark:text-amber-300">
                                        Encabezado: esta plantilla envía un @{{ templateHeaderLabel }}
                                    </span>
                                    <input v-model="templateHeaderLink" type="url"
                                           placeholder="https://… (URL pública del archivo)"
                                           class="w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                    <input v-if="pickedTemplate.header.format === 'DOCUMENT'"
                                           v-model="templateHeaderFilename" type="text"
                                           placeholder="Nombre con el que lo verá el cliente (ej: cotizacion.pdf)"
                                           class="mt-1.5 w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                </div>

                                <div v-for="n in pickedTemplate.variables" :key="'v-' + n" class="mb-2">
                                    <span class="mb-1 block text-[11px] text-gray-500 dark:text-gray-400">Variable @{{ n }} · @{{ placeholder(n) }}</span>
                                    <input v-model="templateVars[n - 1]" type="text"
                                           class="w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                </div>

                                <!-- The advisor sees exactly what the customer will get -->
                                <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-400">Vista previa</span>
                                    @{{ templatePreview }}
                                </div>
                            </div>

                            <div v-if="templateError" class="mt-2 rounded-md bg-red-50 px-3 py-1.5 text-xs text-red-700 dark:bg-red-900/25 dark:text-red-300">
                                @{{ templateError }}
                            </div>
                        </template>
                    </x-slot>

                    <x-slot:footer>
                        <button type="button" class="primary-button"
                                :disabled="templateSending || !pickedTemplate" @click="sendTemplate">
                            @{{ templateSending ? 'Enviando…' : 'Enviar plantilla' }}
                        </button>
                    </x-slot>
                </x-admin::modal>
            </Teleport>

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
                agentUrlBase: { type: String, default: '' },
                quickRepliesUrl: { type: String, default: '' },
                personUrlBase: { type: String, default: '' },
                productsUrl: { type: String, default: '' },
                sendMediaUrl: { type: String, default: '' },
                sendLocationUrl: { type: String, default: '' },
                templatesUrl: { type: String, default: '' },
                sendTemplateUrl: { type: String, default: '' },
                // Page mode: fill the parent instead of a bordered 440px card.
                fullHeight: { type: Boolean, default: false },
            },

            data() {
                return {
                    messages: [],
                    draft: '',
                    isLoading: false,
                    threadError: null,
                    aiEnabled: false,
                    replyingTo: null,
                    showAttach: false,
                    since: null,
                    pollTimer: null,
                    // What the active provider can do. Conservative default:
                    // text only, until the server says otherwise.
                    capabilities: { send: ['text'], receive: [] },

                    // Action menu: attachments, location and templates.
                    savedLocations: [],
                    mediaSending: false,
                    pendingKind: null,

                    // Derivación. Todas las claves presentes desde el arranque:
                    // el thread reemplaza el objeto entero en cada poll, y una
                    // clave ausente dejaría en pantalla el valor de la
                    // conversación anterior.
                    handoff: {
                        state: null, open: false, pooled: false, reason: null,
                        source: null, requested_at: null, city: null,
                        assigned_user: null, assigned_at: null, is_mine: false,
                    },
                    handoffBusy: false,

                    // Visor de imágenes: zoom, rotación, paneo y navegación.
                    // Id del mensaje cuyas coordenadas se acaban de copiar,
                    // para el "Copiado" de un segundo y medio.
                    coordsCopiadas: null,

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

                    /*
                     * Leaflet is loaded from the CDN the first time the picker
                     * opens, not in the bundle: it is ~150 KB that most sessions
                     * never use. The map object itself is deliberately NOT in
                     * data() — see mounted().
                     */
                    locating: false,
                    locationSending: false,
                    locationError: null,
                    mapError: null,
                    locationForm: { latitude: '', longitude: '', label: '', address: '', accuracy: null },

                    templates: [],
                    templatesLoading: false,
                    templatesReason: null,
                    templateError: null,
                    templateSending: false,
                    pickedTemplate: null,
                    templateVars: [],
                    templateHeaderLink: '',
                    templateHeaderFilename: '',
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
                        { id: 'imagenes', label: 'Media' },
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
                initials() {
                    return (this.context.name || '?')
                        .split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
                },

                /*
                 * The file kinds the active gateway really accepts, each with
                 * the `accept` string that keeps the OS picker from offering
                 * something Meta would reject.
                 */
                attachOptions() {
                    return [
                        { kind: 'image',    label: 'Imagen',    icon: 'icon-image',      accept: 'image/jpeg,image/png,image/webp' },
                        { kind: 'document', label: 'Documento', icon: 'icon-file',       accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv' },
                        { kind: 'audio',    label: 'Audio',     icon: 'icon-sent',       accept: 'audio/mpeg,audio/ogg,audio/amr,audio/aac' },
                        { kind: 'video',    label: 'Video',     icon: 'icon-video',      accept: 'video/mp4,video/3gpp' },
                    ].filter(opt => this.canSend(opt.kind));
                },

                canAttach() {
                    return this.attachOptions.length > 0;
                },

                pendingAccept() {
                    return this.attachOptions.find(o => o.kind === this.pendingKind)?.accept ?? '';
                },

                // The whole menu: it is worth showing for a location or a
                // template even when no file kind is available.
                canAct() {
                    return this.canAttach || this.canSend('location') || this.canSend('template');
                },

                // Cuánto hace que espera. Es el número que decide si alguien
                // tiene que meterse: "derivada" sin tiempo no dice nada.
                handoffWaiting() {
                    if (! this.handoff.open || ! this.handoff.requested_at) return null;

                    const mins = Math.floor((Date.now() - new Date(this.handoff.requested_at)) / 60000);

                    if (mins < 1) return 'un momento';
                    if (mins < 60) return mins + ' min';

                    return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
                },

                hasPin() {
                    return this.locationForm.latitude !== '' && this.locationForm.longitude !== '';
                },

                templateNeedsFile() {
                    return !! (this.pickedTemplate && this.pickedTemplate.header && this.pickedTemplate.header.media);
                },

                templateHeaderLabel() {
                    const f = this.pickedTemplate?.header?.format;
                    return f === 'IMAGE' ? 'imagen' : (f === 'VIDEO' ? 'video' : 'documento');
                },

                templatePreview() {
                    if (! this.pickedTemplate) return '';

                    const n = this.pickedTemplate.variables || 0;

                    return Array.from({ length: n }, (_, i) => i).reduce(
                        (body, i) => {
                            const token = this.placeholder(i + 1);

                            return body.split(token).join(this.templateVars[i] || token);
                        },
                        this.pickedTemplate.body,
                    );
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
                /*
                 * Deliberately NOT in data(): wrapped in Vue's reactivity proxy,
                 * Leaflet breaks — it stores DOM nodes and internal handles on
                 * these objects and compares them by identity, which a proxy
                 * silently changes. They live on the instance instead, where Vue
                 * leaves them alone.
                 */
                this.map = null;
                this.marker = null;
                this.leafletPromise = null;

                // Mapas de las burbujas, por id de mensaje, y el del visor.
                this.bubbleMaps = new Map();
                this.bubbleMapObserver = null;

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
                // Con el visor abierto, el teclado es suyo: navega y hace zoom.
                // Cerrado, Esc cierra los popovers y el panel del cliente.
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

                this.destroyBubbleMaps();
            },

            methods: {
                /**
                 * Guarda un mensaje en el hilo: lo reemplaza si ya está (mismo id) y si no
                 * lo agrega; después deja el hilo en orden de llegada.
                 *
                 * Un solo lugar para todas las altas. Antes cada camino hacía su propio
                 * push: el poll agregaba al final sin ordenar (un mensaje cruzado con
                 * otro quedaba fuera de orden hasta recargar), y si el poll traía el
                 * mensaje real antes de que respondiera el envío, quedaba DUPLICADO.
                 */
                guardarMensaje(mensaje, reemplazaA = null) {
                    if (! mensaje) {
                        return;
                    }

                    const provisorio = reemplazaA === null ? -1 : this.messages.findIndex((m) => m.id === reemplazaA);

                    if (provisorio !== -1) {
                        this.messages.splice(provisorio, 1);
                    }

                    const i = this.messages.findIndex((m) => m.id === mensaje.id);

                    if (i === -1) {
                        this.messages.push(mensaje);
                    } else {
                        this.messages.splice(i, 1, mensaje);
                    }

                    this.ordenarMensajes();
                },

                /**
                 * Orden de llegada: por hora y, en el mismo segundo, por id (el orden en
                 * que el CRM los guardó). Lo provisorio, que todavía no tiene hora del
                 * servidor, queda al final en el orden en que se escribió.
                 */
                ordenarMensajes() {
                    const hora = (m) => (m.timestamp && ! String(m.id).startsWith('tmp-') ? Date.parse(m.timestamp) : Number.POSITIVE_INFINITY);
                    const numero = (m) => (typeof m.id === 'number' ? m.id : Number.POSITIVE_INFINITY);
                    const comparar = (a, b) => (a === b ? 0 : (a < b ? -1 : 1));

                    this.messages.sort((a, b) => comparar(hora(a), hora(b)) || comparar(numero(a), numero(b)));
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
                            if (res.data?.locations) this.savedLocations = res.data.locations;
                            if (res.data?.handoff) this.handoff = res.data.handoff;
                            if (res.data?.window) this.window = res.data.window;
                            if (res.data?.conversation?.id) this.conversationId = res.data.conversation.id;
                            if (res.data?.conversation?.gateway) this.gateway = res.data.conversation.gateway;
                            if (res.data?.agent) this.aiEnabled = res.data.agent.effective;

                            const incoming = res.data?.messages ?? [];
                            if (!incoming.length) return;

                            // Decide BEFORE mutating: only follow the stream if the
                            // user was already at the bottom (or it's the first load).
                            const stick = initial || this.isNearBottom();
                            let appended = false;

                            if (initial) {
                                // Otro hilo: los mapas del anterior apuntan a
                                // burbujas que están por desaparecer.
                                this.destroyBubbleMaps();

                                this.messages = incoming;
                            } else {
                                for (const m of incoming) {
                                    if (this.messages.findIndex(x => x.id === m.id) === -1) appended = true;

                                    this.guardarMensaje(m);
                                }
                            }

                            if (stick) this.scrollToBottom();
                            else if (appended) this.newBelow = true;

                            this.threadError = null;
                        })
                        .catch(err => {
                            // Este catch estaba vacío y se tragaba todo: cuando el
                            // hilo fallaba, el panel caía en "Sin mensajes todavía"
                            // —o en blanco— y el asesor no tenía forma de saber que
                            // había habido un error. El hilo es la pantalla
                            // principal del módulo: si no carga, hay que decirlo.
                            const status = err.response?.status;

                            if (status === 403) {
                                // Alcance por ciudad. No se reintenta: el permiso no
                                // va a cambiar en el próximo poll, y seguir pidiendo
                                // cada 4 segundos sólo llena el log del servidor.
                                this.threadError = err.response?.data?.message
                                    ?? 'No tenés acceso a esta conversación.';
                                this.stopPolling();

                                return;
                            }

                            // El poll no avisa: un corte de red de un segundo no
                            // puede tapar los mensajes que ya están en pantalla.
                            if (!initial) return;

                            this.threadError = status === 419
                                ? 'Tu sesión expiró. Recargá la página.'
                                : 'No se pudo cargar la conversación.';
                        });
                },

                stopPolling() {
                    if (this.pollTimer) {
                        clearInterval(this.pollTimer);
                        this.pollTimer = null;
                    }
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
                            // Si el poll ya lo trajo, se saca el provisorio y no queda duplicado.
                            this.guardarMensaje(res.data?.message, tempId);
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
                            this.guardarMensaje(res.data?.note, tempId);
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

                /* ---------------- Adjuntos ---------------- */

                pickFile(kind) {
                    this.showAttach = false;
                    this.pendingKind = kind;

                    // nextTick so `accept` is already updated when the OS dialog
                    // opens; otherwise the first pick offers the previous kind's
                    // file types.
                    this.$nextTick(() => this.$refs.fileInput?.click());
                },

                stageFile(event) {
                    const file = event.target.files?.[0];

                    // Cleared right away so picking the same file twice in a row
                    // still fires @change the second time.
                    event.target.value = '';

                    if (! file || ! this.pendingKind) return;

                    this.uploadMedia(this.pendingKind, file);
                },

                uploadMedia(kind, file) {
                    if (this.mediaSending) return;

                    this.mediaSending = true;

                    const form = new FormData();
                    form.append('kind', kind);
                    form.append('file', file);

                    // The typed draft rides along as the caption instead of
                    // being lost: the advisor writes "acá va el plano" and then
                    // attaches it, which is the natural order.
                    if (this.draft.trim() && ['image', 'video', 'document'].includes(kind)) {
                        form.append('caption', this.draft.trim());
                    }

                    if (this.conversationId) form.append('conversation_id', this.conversationId);
                    if (this.context.phone) form.append('phone', this.context.phone);
                    if (this.context.name) form.append('name', this.context.name);

                    this.$axios.post(this.sendMediaUrl, form)
                        .then(res => {
                            this.guardarMensaje(res.data.message);
                            this.draft = '';
                            this.scrollToBottom();
                        })
                        .catch(err => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: err.response?.data?.message ?? 'No se pudo enviar el archivo.',
                            });
                        })
                        .finally(() => { this.mediaSending = false; });
                },

                /* ---------------- Derivación ---------------- */

                claimHandoff() {
                    if (this.handoffBusy || ! this.conversationId) return;

                    this.handoffBusy = true;

                    this.$axios.post(`${this.agentUrlBase}/${this.conversationId}/handoff/claim`)
                        .then(res => { this.handoff = res.data.handoff; })
                        .catch(err => {
                            // 409 = otro asesor llegó primero. No es un error del
                            // usuario, así que se le dice qué pasó y se refresca.
                            this.$emitter.emit('add-flash', {
                                type: err.response?.status === 409 ? 'warning' : 'error',
                                message: err.response?.data?.message ?? 'No se pudo tomar la conversación.',
                            });
                            this.fetchThread();
                        })
                        .finally(() => { this.handoffBusy = false; });
                },

                resolveHandoff() {
                    if (this.handoffBusy || ! this.conversationId) return;

                    this.handoffBusy = true;

                    this.$axios.post(`${this.agentUrlBase}/${this.conversationId}/handoff/resolve`, {
                        reactivate_ai: true,
                    })
                        .then(res => {
                            this.handoff = res.data.handoff;
                            if (res.data.agent) this.aiEnabled = res.data.agent.effective;
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: 'Derivación cerrada. El agente vuelve a atender.',
                            });
                        })
                        .catch(err => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: err.response?.data?.message ?? 'No se pudo cerrar la derivación.',
                            });
                        })
                        .finally(() => { this.handoffBusy = false; });
                },

                /* ---------------- Visor de imágenes ---------------- */

                // Todas las imágenes del hilo, para poder pasar de una a otra
                // con las flechas sin cerrar el visor.
                // Solo las imágenes de la galería del perfil: un video en la
                // lista de navegación dejaría al visor mostrando un cuadro roto.
                profileImageUrls() {
                    return (this.profile?.images ?? [])
                        .filter(x => x.type !== 'video')
                        .map(x => x.url);
                },

                chatImageUrls() {
                    return this.messages
                        .filter(m => m.type === 'image' && m.media && m.media.url)
                        .map(m => m.media.url);
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

                /* ---------------- Ubicación ---------------- */

                openLocation() {
                    this.showAttach = false;
                    this.locationError = null;
                    this.mapError = null;
                    this.$refs.locationModal.open();

                    // The modal is not in the DOM until it opens, so the map
                    // container does not exist yet on this tick.
                    this.$nextTick(() => this.mountMap());
                },

                /*
                |------------------------------------------------------------
                | Ubicaciones en el mapa
                |------------------------------------------------------------
                */

                /**
                 * Registra el contenedor del mapa de un mensaje de ubicación.
                 *
                 * No monta nada todavía: un chat con veinte ubicaciones
                 * levantaría veinte mapas y otros tantos pedidos de tiles al
                 * abrirse. El IntersectionObserver los monta cuando entran en
                 * pantalla, y sólo esos. Por eso el mapa puede estar abierto
                 * por defecto sin que abrir un chat largo cueste caro.
                 */
                registerBubbleMap(el, msg) {
                    if (! msg) return;

                    /*
                     * Vue llama al ref con null cuando el elemento se
                     * desmonta. Es la única señal de que esa burbuja se fue, y
                     * sin destruir el mapa Leaflet queda con listeners sobre un
                     * nodo que ya no está en el documento.
                     */
                    if (! el) {
                        this.destroyBubbleMap(String(msg.id));

                        return;
                    }

                    if (! msg.location) return;

                    el.dataset.waMsg = String(msg.id);
                    el.dataset.waLat = msg.location.lat;
                    el.dataset.waLng = msg.location.lng;

                    if (! this.bubbleMapObserver) {
                        this.bubbleMapObserver = new IntersectionObserver(entries => {
                            entries.forEach(entry => {
                                if (entry.isIntersecting) {
                                    this.mountBubbleMap(entry.target);
                                    this.bubbleMapObserver.unobserve(entry.target);
                                }
                            });
                        }, { rootMargin: '200px' });
                    }

                    this.bubbleMapObserver.observe(el);
                },

                mountBubbleMap(el) {
                    const id = el.dataset.waMsg;

                    if (! id || this.bubbleMaps.has(id)) return;

                    const lat = Number(el.dataset.waLat);
                    const lng = Number(el.dataset.waLng);

                    if (! Number.isFinite(lat) || ! Number.isFinite(lng)) return;

                    this.loadLeaflet()
                        .then(() => {
                            // El hilo pudo cambiar mientras cargaba el CDN.
                            if (! el.isConnected || this.bubbleMaps.has(id)) return;

                            const map = window.L.map(el, {
                                /*
                                 * Es el mapa principal, no una vista previa:
                                 * se puede arrastrar y hay botones de zoom.
                                 *
                                 * Pero el zoom con la RUEDA queda apagado a
                                 * propósito: dentro de un hilo que scrollea,
                                 * un mapa que captura la rueda le traba el
                                 * scroll al asesor cada vez que el cursor le
                                 * pasa por encima. Los botones + / − hacen lo
                                 * mismo sin ese costo.
                                 */
                                scrollWheelZoom: false,
                                attributionControl: false,
                            }).setView([lat, lng], 16);

                            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                maxZoom: 19,
                            }).addTo(map);

                            window.L.marker([lat, lng]).addTo(map);

                            this.bubbleMaps.set(id, map);

                            // Leaflet mide al crearse, y la burbuja puede seguir
                            // creciendo en ese tick.
                            this.$nextTick(() => map.invalidateSize());
                        })
                        .catch(() => {
                            // Sin mapa la burbuja sigue mostrando nombre y
                            // coordenadas, y el clic sigue abriendo el visor.
                        });
                },

                destroyBubbleMap(id) {
                    const map = this.bubbleMaps.get(id);

                    if (map) {
                        map.remove();
                        this.bubbleMaps.delete(id);
                    }
                },

                destroyBubbleMaps() {
                    this.bubbleMaps.forEach(map => map.remove());
                    this.bubbleMaps.clear();

                    if (this.bubbleMapObserver) {
                        this.bubbleMapObserver.disconnect();
                        this.bubbleMapObserver = null;
                    }
                },

                copiarCoords(msg) {
                    const texto = `${msg.location.lat}, ${msg.location.lng}`;

                    // clipboard exige contexto seguro; sin https no existe.
                    if (! navigator.clipboard) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: texto });

                        return;
                    }

                    navigator.clipboard.writeText(texto)
                        .then(() => {
                            this.coordsCopiadas = msg.id;
                            setTimeout(() => {
                                if (this.coordsCopiadas === msg.id) this.coordsCopiadas = null;
                            }, 1500);
                        })
                        .catch(() => {
                            this.$emitter.emit('add-flash', { type: 'error', message: 'No se pudo copiar.' });
                        });
                },

                /**
                 * Pull Leaflet from the CDN, once per page.
                 */
                loadLeaflet() {
                    if (window.L) return Promise.resolve();
                    if (this.leafletPromise) return this.leafletPromise;

                    this.leafletPromise = new Promise((resolve, reject) => {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                        document.head.appendChild(css);

                        const script = document.createElement('script');
                        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                        script.onload = resolve;
                        script.onerror = () => reject(new Error('No se pudo cargar el mapa.'));
                        document.head.appendChild(script);
                    });

                    return this.leafletPromise;
                },

                mountMap() {
                    this.loadLeaflet()
                        .then(() => {
                            const box = this.$refs.mapBox;

                            if (! box) {
                                this.mapError = 'No se pudo montar el mapa en la página.';
                                return;
                            }

                            if (this.map) {
                                // Reopened: Leaflet measured the box while the
                                // modal was closed, so it thinks it is 0px tall.
                                this.$nextTick(() => this.map.invalidateSize());
                                return;
                            }

                            // Start on the last pin, a saved location, or a wide
                            // view of Santa Cruz.
                            const start = this.hasPin
                                ? [Number(this.locationForm.latitude), Number(this.locationForm.longitude)]
                                : (this.savedLocations.length
                                    ? [this.savedLocations[0].latitude, this.savedLocations[0].longitude]
                                    : [-17.78333, -63.18214]);

                            this.map = window.L.map(box).setView(start, this.hasPin ? 16 : 12);

                            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '© OpenStreetMap',
                                maxZoom: 19,
                            }).addTo(this.map);

                            this.map.on('click', e => this.setPin(e.latlng.lat, e.latlng.lng, { recenter: false }));

                            if (this.hasPin) {
                                this.setPin(Number(this.locationForm.latitude), Number(this.locationForm.longitude));
                            }

                            // Leaflet measures on creation, and inside a modal
                            // that just opened the box can still be 0px tall.
                            this.$nextTick(() => this.map.invalidateSize());
                        })
                        .catch(e => { this.mapError = e.message; });
                },

                setPin(lat, lng, { recenter = true } = {}) {
                    if (! this.map) {
                        this.writeCoords(lat, lng);
                        return;
                    }

                    if (! this.marker) {
                        this.marker = window.L.marker([lat, lng], { draggable: true }).addTo(this.map);
                        this.marker.on('dragend', () => {
                            const p = this.marker.getLatLng();
                            this.writeCoords(p.lat, p.lng);
                            // The GPS reading no longer describes this pin.
                            this.locationForm.accuracy = null;
                        });
                    } else {
                        this.marker.setLatLng([lat, lng]);
                    }

                    this.writeCoords(lat, lng);

                    if (recenter) this.map.setView([lat, lng], Math.max(this.map.getZoom(), 16));
                },

                writeCoords(lat, lng) {
                    this.locationForm.latitude = Number(lat).toFixed(6);
                    this.locationForm.longitude = Number(lng).toFixed(6);
                },

                pinFromInputs() {
                    const lat = Number(this.locationForm.latitude);
                    const lng = Number(this.locationForm.longitude);

                    if (Number.isFinite(lat) && Number.isFinite(lng)) this.setPin(lat, lng);
                },

                useMyLocation() {
                    if (! navigator.geolocation) {
                        this.locationError = 'Este navegador no comparte la ubicación.';
                        return;
                    }

                    this.locating = true;
                    this.locationError = null;

                    navigator.geolocation.getCurrentPosition(
                        pos => {
                            this.locationForm.accuracy = pos.coords.accuracy;
                            this.setPin(pos.coords.latitude, pos.coords.longitude);
                            this.locating = false;
                        },
                        () => {
                            // Denying the permission is a normal answer, not an
                            // error: the map and the manual fields still work.
                            this.locationError = 'No se pudo obtener tu ubicación. Marcá el punto en el mapa.';
                            this.locating = false;
                        },
                        { enableHighAccuracy: true, timeout: 10000 },
                    );
                },

                useSavedLocation(place) {
                    this.locationForm.label = place.name;
                    this.locationForm.address = place.address || '';
                    this.locationForm.accuracy = null;
                    this.setPin(place.latitude, place.longitude);
                },

                sendLocation() {
                    if (this.locationSending || ! this.hasPin) return;

                    this.locationSending = true;
                    this.locationError = null;

                    this.$axios.post(this.sendLocationUrl, {
                        conversation_id: this.conversationId || null,
                        phone: this.context.phone || null,
                        name: this.context.name || null,
                        latitude: this.locationForm.latitude,
                        longitude: this.locationForm.longitude,
                        label: this.locationForm.label || null,
                        address: this.locationForm.address || null,
                    })
                        .then(res => {
                            this.guardarMensaje(res.data.message);
                            this.$refs.locationModal.close();
                            this.locationForm = { latitude: '', longitude: '', label: '', address: '', accuracy: null };
                            this.scrollToBottom();
                        })
                        .catch(err => {
                            this.locationError = err.response?.data?.message ?? 'No se pudo enviar la ubicación.';
                        })
                        .finally(() => { this.locationSending = false; });
                },

                /* ---------------- Plantillas ---------------- */

                /**
                 * El marcador de una variable: {NN} con llaves dobles.
                 *
                 * Se arma concatenando y NUNCA se escribe literal, porque este
                 * archivo es Blade y dos llaves seguidas son sintaxis suya:
                 *   - en el HTML, Vue corta la interpolación en el primer cierre
                 *     y queda un string sin terminar -> el componente entero no
                 *     compila y el chat se ve en blanco;
                 *   - en el <script>, Blade las toma como suyas y deja una
                 *     etiqueta PHP incrustada en medio del JavaScript.
                 * Las dos cosas ya pasaron acá.
                 */
                placeholder(n) {
                    return '{' + '{' + n + '}' + '}';
                },

                openTemplates() {
                    this.showAttach = false;
                    this.templateError = null;
                    this.pickedTemplate = null;
                    this.templateVars = [];
                    this.templateHeaderLink = '';
                    this.templateHeaderFilename = '';
                    this.$refs.templateModal.open();

                    this.templatesLoading = true;
                    this.templatesReason = null;

                    this.$axios.get(this.templatesUrl)
                        .then(res => {
                            this.templates = res.data?.templates ?? [];
                            this.templatesReason = res.data?.reason ?? null;
                        })
                        .catch(() => { this.templatesReason = 'No se pudo leer el catálogo de plantillas.'; })
                        .finally(() => { this.templatesLoading = false; });
                },

                isPickedTemplate(t) {
                    return this.pickedTemplate
                        && this.pickedTemplate.name === t.name
                        && this.pickedTemplate.language === t.language;
                },

                pickTemplate(t) {
                    this.pickedTemplate = t;
                    this.templateVars = Array.from({ length: t.variables }, () => '');
                    // Cambiar de plantilla no puede arrastrar el archivo de la anterior.
                    this.templateHeaderLink = '';
                    this.templateHeaderFilename = '';
                    this.templateError = null;
                },

                sendTemplate() {
                    if (this.templateSending || ! this.pickedTemplate) return;

                    this.templateSending = true;
                    this.templateError = null;

                    this.$axios.post(this.sendTemplateUrl, {
                        conversation_id: this.conversationId || null,
                        phone: this.context.phone || null,
                        name: this.context.name || null,
                        template: this.pickedTemplate.name,
                        language: this.pickedTemplate.language,
                        variables: this.templateVars,
                        header_link: this.templateNeedsFile ? this.templateHeaderLink : null,
                        header_filename: this.templateNeedsFile ? this.templateHeaderFilename : null,
                    })
                        .then(res => {
                            this.guardarMensaje(res.data.message);
                            this.$refs.templateModal.close();
                            this.pickedTemplate = null;
                            this.templateHeaderLink = '';
                            this.templateHeaderFilename = '';
                            this.scrollToBottom();
                        })
                        .catch(err => {
                            this.templateError = err.response?.data?.message ?? 'No se pudo enviar la plantilla.';
                        })
                        .finally(() => { this.templateSending = false; });
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
