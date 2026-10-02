<x-admin::layouts>
    <x-slot:title>Punto de Distribuidor</x-slot>

    {{--
        Punto de Distribuidor.

        Táctil primero: se usa de pie, con una mano, en una tablet o un celular.
        De ahí las tres reglas que gobiernan todo el diseño de abajo:

        1. Objetivos de 64px en las acciones principales y 56px en las secundarias.
           Por debajo de eso un dedo falla, y acá fallar significa cobrar mal.
        2. Nada depende de `hover`: en una pantalla táctil no existe. Todo estado
           se muestra con color, borde o ícono.
        3. Cantidades e importes con teclado numérico nativo (`inputmode`) y
           steppers de ± — tipear "1" en un campo de texto con guantes no pasa.

        La sucursal vive en el encabezado y no en un paso del flujo porque el
        PRECIO depende de ella: el botellón de 20 L vale $8.000 en San Salvador de
        Jujuy y $7.500 en Barrio Alto Comedero y Palpalá. Elegirla al final sería
        recalcular un pedido ya armado.
    --}}
    <v-punto
        :sucursales='@json($sucursales)'
        :formas-pago='@json($formasPago)'
        productos-url="{{ route('admin.distribuidor.productos') }}"
        pedidos-url="{{ route('admin.distribuidor.pedidos') }}"
        buscar-contacto-url="{{ route('admin.distribuidor.contacto.buscar') }}"
        guardar-contacto-url="{{ route('admin.distribuidor.contacto.store') }}"
        guardar-pedido-url="{{ route('admin.distribuidor.pedido.store') }}"
    />

    @pushOnce('scripts')
        <script type="text/x-template" id="v-punto-template">
            <div class="mx-auto w-full max-w-5xl pb-28">

                {{--
                    ── Encabezado: UN solo selector de lugar ──

                    Antes había dos listas del mismo lugar en la misma pantalla: ésta
                    (sucursales, de `warehouses`) y una fila de chips de ciudad en la vista
                    de Pedidos (de `lead_pipelines`). Como sucursal y ciudad son el MISMO
                    lugar, era la misma lista repetida con dos nombres — y la segunda
                    mostraba además pipelines que no son sucursales reales.

                    Este selector manda sobre todo: qué precios se cargan y qué pedidos
                    pendientes se ven.
                --}}
                <div class="sticky top-0 z-20 -mx-4 mb-4 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                    <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                        Dónde estás trabajando
                    </span>

                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in sucursales" :key="s.id" type="button"
                                @click="elegirSucursal(s)"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] flex-1 basis-[45%] rounded-xl border-2 px-4 text-base font-semibold transition-colors duration-200 sm:basis-auto"
                                :class="sucursal && sucursal.id === s.id
                                    ? 'border-brandColor bg-brandColor text-white'
                                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                            @{{ s.name }}
                        </button>
                    </div>
                </div>

                {{-- ── Dos pestañas grandes ── --}}
                <div class="mb-4 grid grid-cols-2 gap-2">
                    <button type="button" @click="vista = 'nuevo'"
                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 text-lg font-bold transition-colors duration-200"
                            :class="vista === 'nuevo'
                                ? 'border-brandColor bg-brandColor/10 text-brandColor'
                                : 'border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-400'">
                        Nuevo pedido
                    </button>
                    <button type="button" @click="irAPedidos"
                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 text-lg font-bold transition-colors duration-200"
                            :class="vista === 'pedidos'
                                ? 'border-brandColor bg-brandColor/10 text-brandColor'
                                : 'border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-400'">
                        Pedidos
                        <span v-if="pedidos.length" class="ml-1 rounded-full bg-brandColor px-2 py-0.5 text-sm text-white">
                            @{{ pedidos.length }}
                        </span>
                    </button>
                </div>

                <div v-if="aviso" class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-base text-red-700 dark:bg-red-900/25 dark:text-red-300">
                    @{{ aviso }}
                </div>

                {{-- ══════════════ NUEVO PEDIDO ══════════════ --}}
                <template v-if="vista === 'nuevo'">
                    <div v-if="!sucursal" class="rounded-xl border-2 border-dashed border-gray-300 px-4 py-10 text-center text-base text-gray-500 dark:border-gray-700">
                        Elegí una sucursal para empezar.
                    </div>

                    <template v-else>
                        {{-- Paso 1: el cliente, por teléfono --}}
                        <section class="mb-4 rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                            <h2 class="mb-3 text-lg font-bold text-gray-800 dark:text-gray-200">1 · Cliente</h2>

                            <div v-if="!cliente" class="flex flex-col gap-2 sm:flex-row">
                                {{-- inputmode="tel": teclado numérico nativo, sin buscar números en el alfabético --}}
                                <div class="flex-1">
                                    <label for="punto-telefono" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Teléfono del cliente
                                    </label>
                                    <input id="punto-telefono" v-model="telefono" type="tel" inputmode="tel"
                                           placeholder="Ej. 3884123456"
                                           @keyup.enter="buscarCliente"
                                           class="min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-xl focus:border-brandColor focus:outline-none focus:ring-2 focus:ring-brandColor dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                </div>
                                <button type="button" @click="buscarCliente" :disabled="cargando" aria-label="Buscar el contacto por teléfono"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-brandColor px-8 text-lg font-bold text-white disabled:opacity-50 sm:min-w-[160px]">
                                    @{{ cargando ? '…' : 'Buscar' }}
                                </button>
                            </div>

                            {{-- No encontrado: se pide el nombre, no se crea a ciegas --}}
                            <div v-if="!cliente && buscado && !encontrado" class="mt-3 rounded-xl bg-amber-50 p-3 dark:bg-amber-900/20">
                                <p class="mb-2 text-base text-amber-800 dark:text-amber-300">
                                    Ese número no está en el sistema. ¿Lo cargamos?
                                </p>
                                <label for="punto-nombre" class="mb-1 block text-sm font-medium text-amber-900 dark:text-amber-200">
                                    Nombre del cliente
                                </label>
                                <input id="punto-nombre" v-model="nombre" type="text" placeholder="Nombre y apellido"
                                       class="mb-2 min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-xl focus:border-brandColor focus:outline-none focus:ring-2 focus:ring-brandColor dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                <button type="button" @click="crearCliente" :disabled="cargando || !nombre.trim()"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] w-full rounded-xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-50">
                                    Crear contacto
                                </button>
                            </div>

                            {{-- Elegido --}}
                            <div v-if="cliente" class="flex items-center justify-between gap-3 rounded-xl bg-emerald-50 p-4 dark:bg-emerald-900/20">
                                <div class="min-w-0">
                                    <div class="truncate text-xl font-bold text-gray-800 dark:text-gray-100">@{{ cliente.name }}</div>
                                    <div class="text-base text-gray-600 dark:text-gray-400">@{{ cliente.telefono }}</div>
                                </div>
                                <button type="button" @click="limpiarCliente"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] shrink-0 rounded-xl border-2 border-gray-300 px-5 text-base font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cambiar
                                </button>
                            </div>
                        </section>

                        {{-- Paso 2: los productos de ESA sucursal --}}
                        <section class="mb-4 rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                            <h2 class="mb-1 text-lg font-bold text-gray-800 dark:text-gray-200">2 · Productos</h2>
                            <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">Precios de @{{ sucursal.name }}</p>

                            <div v-if="!productos.length" class="rounded-xl border-2 border-dashed border-gray-300 px-4 py-8 text-center text-base text-gray-500 dark:border-gray-700">
                                Esta sucursal no tiene productos cargados.
                            </div>

                            {{-- Una columna en celular, dos en tablet --}}
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div v-for="p in productos" :key="p.id"
                                     class="rounded-xl border-2 p-3 transition-colors duration-200"
                                     :class="cantidadDe(p.id) > 0 ? 'border-brandColor bg-brandColor/5' : 'border-gray-200 dark:border-gray-800'">
                                    <div class="mb-2">
                                        <div class="text-base font-semibold leading-tight text-gray-800 dark:text-gray-200">@{{ p.name }}</div>

                                        {{--
                                            El precio viene del catálogo de la sucursal pero se
                                            puede editar: un descuento, un precio acordado o un
                                            redondeo en mano son parte del trabajo. Se muestra
                                            cuándo difiere del de lista para que el cambio sea
                                            visible y no un error silencioso.
                                        --}}
                                        <div class="flex items-center gap-2">
                                            <input :value="precioDe(p)" @input="fijarPrecio(p, $event.target.value)"
                                                   :aria-label="'Precio de ' + p.name"
                                                   type="number" inputmode="decimal" min="0"
                                                   class="min-h-[56px] w-full rounded-xl border-2 px-3 text-lg font-bold dark:bg-gray-900 dark:text-gray-200"
                                                   :class="precioCambiado(p)
                                                       ? 'border-amber-500 text-amber-700 dark:text-amber-400'
                                                       : 'border-gray-200 text-brandColor dark:border-gray-800'">
                                        </div>
                                        <div v-if="precioCambiado(p)" class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                                            Lista: @{{ money(p.price) }}
                                        </div>
                                    </div>

                                    {{-- Stepper: ± de 56px en vez de tipear --}}
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="sumar(p, -1)" :aria-label="'Quitar uno de ' + p.name"
                                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] min-w-[56px] rounded-xl border-2 border-gray-300 text-2xl font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                            −
                                        </button>
                                        <input :value="cantidadDe(p.id)" @input="fijar(p, $event.target.value)"
                                               :aria-label="'Cantidad de ' + p.name"
                                               type="number" inputmode="numeric" min="0"
                                               class="min-h-[56px] w-full rounded-xl border-2 border-gray-300 text-center text-xl font-bold dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        <button type="button" @click="sumar(p, 1)" :aria-label="'Agregar uno de ' + p.name"
                                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] min-w-[56px] rounded-xl bg-brandColor text-2xl font-bold text-white">
                                            +
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {{-- Paso 3: nota opcional --}}
                        <section class="mb-4 rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                            <h2 class="mb-3 text-lg font-bold text-gray-800 dark:text-gray-200">3 · Nota <span class="text-sm font-normal text-gray-500">(opcional)</span></h2>
                            <label for="punto-nota" class="sr-only">Nota del pedido</label>
                            <textarea id="punto-nota" v-model="nota" rows="2" placeholder="Dirección, referencia, horario…"
                                      class="w-full rounded-xl border-2 border-gray-300 p-3 text-lg dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                        </section>
                    </template>
                </template>

                {{-- ══════════════ PEDIDOS ══════════════ --}}
                <template v-if="vista === 'pedidos'">
                    {{-- Pendientes es el default: es el trabajo que falta hacer. --}}
                    <div class="mb-3 grid grid-cols-2 gap-2">
                        <button type="button" @click="cambiarFiltro('pendientes')"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 text-base font-bold transition-colors duration-200"
                                :class="filtro === 'pendientes'
                                    ? 'border-brandColor bg-brandColor text-white'
                                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                            Pendientes
                        </button>
                        <button type="button" @click="cambiarFiltro('hoy')"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 text-base font-bold transition-colors duration-200"
                                :class="filtro === 'hoy'
                                    ? 'border-brandColor bg-brandColor text-white'
                                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                            Mis pedidos de hoy
                        </button>
                    </div>

                    {{--
                        Alcance: dos botones, no una segunda lista de lugares.

                        El lugar ya se eligió arriba. Acá sólo se decide si se mira lo de
                        ese lugar o todo, que es la única pregunta que el selector de
                        arriba no contesta.
                    --}}
                    <div class="mb-4 grid grid-cols-2 gap-2">
                        <button type="button" @click="verSoloMiLugar(true)"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 px-3 text-base font-semibold transition-colors duration-200"
                                :class="soloMiLugar
                                    ? 'border-brandColor bg-brandColor text-white'
                                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                            @{{ sucursal ? sucursal.name : 'Mi lugar' }}
                        </button>
                        <button type="button" @click="verSoloMiLugar(false)"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 px-3 text-base font-semibold transition-colors duration-200"
                                :class="!soloMiLugar
                                    ? 'border-gray-800 bg-gray-800 text-white dark:border-gray-200 dark:bg-gray-200 dark:text-gray-900'
                                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                            Todas
                        </button>
                    </div>

                    <div v-if="cargando" class="py-10 text-center text-base text-gray-600 dark:text-gray-400">Cargando…</div>

                    <div v-else-if="!pedidos.length" class="rounded-xl border-2 border-dashed border-gray-300 px-4 py-10 text-center text-base text-gray-600 dark:text-gray-400 dark:border-gray-700">
                        @{{ filtro === 'pendientes' ? 'No hay pedidos pendientes acá.' : 'Todavía no tomaste pedidos hoy.' }}
                    </div>

                    <div v-for="pe in pedidos" :key="pe.id" class="mb-3 rounded-2xl border-2 border-gray-200 p-4 dark:border-gray-800">
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-xl font-bold text-gray-800 dark:text-gray-100">@{{ pe.cliente || pe.title }}</div>
                                <div class="text-base text-gray-600 dark:text-gray-400">#@{{ pe.id }} · @{{ pe.telefono }}</div>
                            </div>
                            <span class="shrink-0 rounded-full px-3 py-1 text-sm font-bold" :class="colorEtapa(pe.etapa_code)">
                                @{{ pe.etapa }}
                            </span>
                        </div>

                        {{-- El saldo es el número que importa: va grande --}}
                        <div class="mb-3 grid grid-cols-3 gap-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-800/50">
                            <div>
                                <div class="text-xs uppercase text-gray-500">Total</div>
                                <div class="text-lg font-bold text-gray-800 dark:text-gray-200">@{{ money(pe.total) }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase text-gray-500">Cobrado</div>
                                <div class="text-lg font-bold text-emerald-600">@{{ money(pe.cobrado) }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase text-gray-500">Saldo</div>
                                <div class="text-2xl font-extrabold" :class="pe.saldo > 0 ? 'text-red-600' : 'text-emerald-600'">
                                    @{{ money(pe.saldo) }}
                                </div>
                            </div>
                        </div>

                        {{-- Cuándo se entregó y quién: es lo que se pregunta cuando el cliente dice que no le llegó. --}}
                        <div v-if="pe.entregado_at" class="mb-3 rounded-xl bg-emerald-50 px-3 py-2 dark:bg-emerald-900/20">
                            <div class="text-base text-emerald-800 dark:text-emerald-300">
                                Entregado el <strong>@{{ pe.entregado_at }}</strong>
                                <span v-if="pe.entregado_por">por @{{ pe.entregado_por }}</span>
                            </div>
                            <a v-if="pe.entrega_foto" :href="pe.entrega_foto" target="_blank" rel="noopener"
                               class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mt-2 block">
                                <img :src="pe.entrega_foto" alt="Foto de la entrega"
                                     class="max-h-40 rounded-lg border border-emerald-200 dark:border-emerald-800">
                            </a>
                        </div>

                        {{-- Acciones: grandes, y sólo las que aplican a esta etapa --}}
                        <div class="grid grid-cols-2 gap-2">
                            <button v-if="pe.etapa_code === 'prospectos'" type="button" @click="avanzar(pe, 'confirmado')"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-brandColor text-lg font-bold text-white">
                                Confirmar
                            </button>

                            {{--
                                Entregar abre la cámara: la foto es la prueba de que el
                                pedido llegó, y es obligatoria. `capture="environment"`
                                abre la cámara trasera nativa del dispositivo — más
                                confiable que getUserMedia en iOS, y no pide permisos
                                hasta que el usuario toca el botón.
                            --}}
                            <button v-if="pe.etapa_code === 'pedidos-confirmado'" type="button" @click="pedirFoto(pe)"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-600 text-lg font-bold text-white">
                                <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.9 47.9 0 0 0-1.134-.175a2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0a2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg>Entregar
                            </button>

                            <button v-if="pe.saldo > 0" type="button" @click="abrirCobro(pe)"
                                    class="min-h-[64px] rounded-xl border-2 border-emerald-600 text-lg font-bold text-emerald-700 dark:text-emerald-400">
                                Cobrar
                            </button>

                            <a :href="'{{ url(config('app.admin_path').'/distribuidor/whatsapp') }}/' + pe.id"
                               class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 flex min-h-[64px] items-center justify-center rounded-xl border-2 border-emerald-600 text-lg font-bold text-emerald-700 dark:text-emerald-400">
                                WhatsApp
                            </a>

                            <button v-if="pe.etapa_code !== 'pedidos-cancelado' && pe.etapa_code !== 'pedido-entregado'"
                                    type="button" @click="avanzar(pe, 'cancelado')"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-red-300 text-base font-semibold text-red-600 dark:border-red-800">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </template>

                {{-- ── Barra fija: el total y la acción, al alcance del pulgar ── --}}
                <div v-if="vista === 'nuevo' && sucursal"
                     class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                    <div class="mx-auto flex max-w-5xl items-center gap-3">
                        <div class="shrink-0">
                            <div class="text-xs uppercase text-gray-500">Total</div>
                            <div class="text-2xl font-extrabold text-gray-900 dark:text-white">@{{ money(total) }}</div>
                        </div>
                        <button type="button" @click="guardarPedido" :disabled="!puedeGuardar || cargando"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] flex-1 rounded-xl bg-brandColor text-xl font-bold text-white disabled:opacity-40">
                            @{{ cargando ? 'Guardando…' : 'Abrir pedido' }}
                        </button>
                    </div>
                </div>

                {{--
                    El input de la cámara vive una sola vez, oculto, y se dispara por
                    código. Un <input type="file"> visible por cada pedido sería una
                    fila de controles nativos imposible de estilar con 64px.
                --}}
                <input ref="camara" type="file" accept="image/*" capture="environment"
                       class="hidden" tabindex="-1" aria-hidden="true" @change="fotoTomada">

                {{-- ── Confirmar la entrega con la foto a la vista ── --}}
                <Teleport to="body">
                    <div v-if="entrega.pedido" class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center" @click.self="cancelarEntrega">
                        <div class="w-full max-w-md rounded-t-2xl bg-white p-5 dark:bg-gray-900 sm:rounded-2xl">
                            <h3 class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">Confirmar entrega</h3>
                            <p class="mb-3 text-base text-gray-600 dark:text-gray-400">
                                Pedido #@{{ entrega.pedido.id }} · @{{ entrega.pedido.cliente }}
                            </p>

                            <img v-if="entrega.preview" :src="entrega.preview" alt="Foto tomada"
                                 class="mb-3 max-h-64 w-full rounded-xl object-contain">

                            <button type="button" @click="abrirCamara"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-3 flex min-h-[56px] w-full cursor-pointer items-center justify-center rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">
                                <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.9 47.9 0 0 0-1.134-.175a2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0a2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg>@{{ entrega.preview ? 'Sacar otra foto' : 'Sacar la foto' }}
                            </button>

                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="cancelarEntrega"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="confirmarEntrega" :disabled="!entrega.archivo || cargando"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-40">
                                    @{{ cargando ? 'Enviando…' : 'Entregado' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                {{-- ── Cobro: dentro del pedido, no en otra sección ── --}}
                <Teleport to="body">
                    <div v-if="cobro.pedido" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center" @click.self="cobro.pedido = null">
                        <div class="w-full max-w-md rounded-t-2xl bg-white p-5 dark:bg-gray-900 sm:rounded-2xl">
                            <h3 class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">Registrar cobro</h3>
                            <p class="mb-4 text-base text-gray-600 dark:text-gray-400">
                                Saldo: <strong>@{{ money(cobro.pedido.saldo) }}</strong>
                            </p>

                            {{-- Atajos: lo que se cobra el 90% de las veces, sin tipear --}}
                            <div class="mb-3 grid grid-cols-2 gap-2">
                                <button type="button" @click="cobro.amount = cobro.pedido.saldo"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-emerald-600 text-base font-bold text-emerald-700 dark:text-emerald-400">
                                    Todo el saldo
                                </button>
                                <button type="button" @click="cobro.amount = Math.round(cobro.pedido.saldo / 2)"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    La mitad
                                </button>
                            </div>

                            <label for="punto-cobro" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Importe que estás cobrando
                            </label>
                            <input id="punto-cobro" v-model.number="cobro.amount" type="number" inputmode="decimal" min="0"
                                   placeholder="Importe"
                                   class="mb-3 min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-center text-2xl font-bold dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">

                            <div class="mb-4 grid grid-cols-3 gap-2">
                                <button v-for="(label, key) in formasPago" :key="key" type="button"
                                        @click="cobro.method = key"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 text-base font-semibold transition-colors duration-200"
                                        :class="cobro.method === key
                                            ? 'border-brandColor bg-brandColor text-white'
                                            : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                                    @{{ label }}
                                </button>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="cobro.pedido = null"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="registrarCobro" :disabled="!cobro.amount || cargando"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-40">
                                    Registrar
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>
            </div>
        </script>

        <script type="module">
            app.component('v-punto', {
                template: '#v-punto-template',

                props: {
                    sucursales: { type: Array, default: () => [] },
                    formasPago: { type: Object, default: () => ({}) },
                    productosUrl: { type: String, default: '' },
                    pedidosUrl: { type: String, default: '' },
                    buscarContactoUrl: { type: String, default: '' },
                    guardarContactoUrl: { type: String, default: '' },
                    guardarPedidoUrl: { type: String, default: '' },
                },

                data() {
                    return {
                        vista: 'nuevo',
                        sucursal: null,
                        productos: [],
                        // product_id => cantidad
                        carrito: {},
                        // product_id => precio editado (sólo los que se tocaron)
                        precios: {},
                        filtro: 'pendientes',
                        // El lugar sale del selector del encabezado; esto sólo lo acota.
                        soloMiLugar: true,
                        telefono: '',
                        nombre: '',
                        cliente: null,
                        buscado: false,
                        encontrado: false,
                        nota: '',
                        pedidos: [],
                        cobro: { pedido: null, amount: null, method: 'efectivo' },
                        entrega: { pedido: null, archivo: null, preview: null },
                        cargando: false,
                        aviso: null,
                    };
                },

                computed: {
                    total() {
                        return this.productos.reduce(
                            (t, p) => t + (this.carrito[p.id] || 0) * this.precioDe(p),
                            0,
                        );
                    },

                    puedeGuardar() {
                        return !! this.cliente && this.lineas.length > 0;
                    },

                    lineas() {
                        return this.productos
                            .filter(p => (this.carrito[p.id] || 0) > 0)
                            .map(p => ({
                                product_id: p.id,
                                quantity: this.carrito[p.id],
                                price: this.precioDe(p),
                            }));
                    },

                    /**
                     * La ciudad del pedido.
                     *
                     * La resuelve el SERVIDOR (`Catalogo::ciudadDeSucursal`) y
                     * llega con la sucursal. Antes se deducía acá matcheando
                     * nombres en JS: una segunda versión de la equivalencia que
                     * podía discrepar de la del backend, sobre dos tablas cuyos
                     * ids se contradicen.
                     */
                    ciudadId() {
                        return this.sucursal ? (this.sucursal.ciudad_id || null) : null;
                    },
                },

                mounted() {
                    // Una sola sucursal: no se pregunta lo obvio.
                    if (this.sucursales.length === 1) {
                        this.elegirSucursal(this.sucursales[0]);
                    }
                },

                methods: {
                    money(n) {
                        return new Intl.NumberFormat('es-AR', {
                            style: 'currency',
                            currency: '{{ config('app.currency', 'ARS') }}',
                            maximumFractionDigits: 0,
                        }).format(n || 0);
                    },

                    elegirSucursal(s) {
                        this.sucursal = s;
                        this.carrito = {};
                        // Los precios editados eran de la sucursal anterior.
                        this.precios = {};
                        this.cargarProductos();

                        // Si estoy mirando pedidos, la lista sigue al lugar elegido.
                        if (this.vista === 'pedidos') this.cargarPedidos();
                    },

                    cargarProductos() {
                        this.$axios.get(this.productosUrl, { params: { sucursal: this.sucursal.id } })
                            .then(r => { this.productos = r.data.productos; })
                            .catch(() => { this.aviso = 'No se pudo cargar el catálogo.'; });
                    },

                    cantidadDe(id) {
                        return this.carrito[id] || 0;
                    },

                    /** El precio efectivo: el editado si se tocó, si no el de lista. */
                    precioDe(p) {
                        const v = this.precios[p.id];

                        return (v === undefined || v === null || v === '') ? p.price : Number(v);
                    },

                    fijarPrecio(p, valor) {
                        const n = Math.max(0, parseFloat(valor) || 0);
                        this.precios = { ...this.precios, [p.id]: n };
                    },

                    precioCambiado(p) {
                        return this.precioDe(p) !== p.price;
                    },

                    sumar(p, delta) {
                        const n = Math.max(0, (this.carrito[p.id] || 0) + delta);
                        this.carrito = { ...this.carrito, [p.id]: n };
                    },

                    fijar(p, valor) {
                        const n = Math.max(0, parseInt(valor, 10) || 0);
                        this.carrito = { ...this.carrito, [p.id]: n };
                    },

                    buscarCliente() {
                        if (! this.telefono.trim()) return;

                        this.cargando = true;
                        this.aviso = null;

                        this.$axios.get(this.buscarContactoUrl, { params: { telefono: this.telefono } })
                            .then(r => {
                                this.buscado = true;
                                this.encontrado = r.data.encontrado;
                                if (r.data.encontrado) this.cliente = r.data.persona;
                            })
                            .catch(() => { this.aviso = 'No se pudo buscar el contacto.'; })
                            .finally(() => { this.cargando = false; });
                    },

                    crearCliente() {
                        this.cargando = true;

                        this.$axios.post(this.guardarContactoUrl, { telefono: this.telefono, nombre: this.nombre })
                            .then(r => { this.cliente = r.data.persona; })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo crear el contacto.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    limpiarCliente() {
                        this.cliente = null;
                        this.buscado = false;
                        this.encontrado = false;
                        this.nombre = '';
                    },

                    guardarPedido() {
                        if (! this.puedeGuardar) return;

                        if (! this.ciudadId) {
                            this.aviso = 'No hay una ciudad que coincida con la sucursal "' + this.sucursal.name + '".';
                            return;
                        }

                        this.cargando = true;
                        this.aviso = null;

                        this.$axios.post(this.guardarPedidoUrl, {
                            person_id: this.cliente.id,
                            ciudad_id: this.ciudadId,
                            nota: this.nota || null,
                            productos: this.lineas,
                        })
                            .then(() => {
                                this.carrito = {};
                                this.precios = {};
                                this.nota = '';
                                this.telefono = '';
                                this.limpiarCliente();
                                this.irAPedidos();
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo abrir el pedido.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    irAPedidos() {
                        this.vista = 'pedidos';
                        this.cargarPedidos();
                    },

                    cambiarFiltro(filtro) {
                        this.filtro = filtro;
                        this.cargarPedidos();
                    },

                    verSoloMiLugar(valor) {
                        this.soloMiLugar = valor;
                        this.cargarPedidos();
                    },

                    cargarPedidos() {
                        this.cargando = true;

                        this.$axios.get(this.pedidosUrl, {
                            params: {
                                filtro: this.filtro,
                                // La ciudad la define el selector del encabezado.
                                ciudad: (this.soloMiLugar && this.sucursal) ? this.sucursal.ciudad_id : undefined,
                            },
                        })
                            .then(r => { this.pedidos = r.data.pedidos; })
                            .catch(() => { this.aviso = 'No se pudieron cargar los pedidos.'; })
                            .finally(() => { this.cargando = false; });
                    },

                    avanzar(pedido, etapa) {
                        this.cargando = true;

                        this.$axios.patch('{{ url(config('app.admin_path').'/distribuidor/pedido') }}/' + pedido.id + '/etapa', { etapa })
                            .then(r => { this.reemplazar(r.data.pedido); })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo cambiar la etapa.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    // ── Entrega con foto ──
                    pedirFoto(pedido) {
                        this.entrega = { pedido, archivo: null, preview: null };
                        this.abrirCamara();
                    },

                    abrirCamara() {
                        // Se limpia el value para que elegir la MISMA foto dos veces
                        // vuelva a disparar el change.
                        this.$refs.camara.value = '';
                        this.$refs.camara.click();
                    },

                    fotoTomada(e) {
                        const archivo = e.target.files && e.target.files[0];

                        if (! archivo) return;

                        this.entrega.archivo = archivo;
                        this.entrega.preview = URL.createObjectURL(archivo);
                    },

                    cancelarEntrega() {
                        if (this.entrega.preview) URL.revokeObjectURL(this.entrega.preview);
                        this.entrega = { pedido: null, archivo: null, preview: null };
                    },

                    confirmarEntrega() {
                        if (! this.entrega.archivo) return;

                        const pedido = this.entrega.pedido;

                        // multipart: la foto es un archivo, no un string base64 — que
                        // infla el payload un 33% y revienta el límite de POST.
                        const form = new FormData();
                        form.append('etapa', 'entregado');
                        form.append('foto', this.entrega.archivo);
                        form.append('_method', 'PATCH');

                        this.cargando = true;

                        this.$axios.post('{{ url(config('app.admin_path').'/distribuidor/pedido') }}/' + pedido.id + '/etapa', form)
                            .then(r => {
                                this.reemplazar(r.data.pedido);
                                this.cancelarEntrega();
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo confirmar la entrega.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    abrirCobro(pedido) {
                        this.cobro = { pedido, amount: pedido.saldo, method: 'efectivo' };
                    },

                    registrarCobro() {
                        const pedido = this.cobro.pedido;

                        this.cargando = true;

                        this.$axios.post('{{ url(config('app.admin_path').'/distribuidor/pedido') }}/' + pedido.id + '/cobro', {
                            amount: this.cobro.amount,
                            method: this.cobro.method,
                        })
                            .then(r => {
                                this.reemplazar(r.data.pedido);
                                this.cobro.pedido = null;
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo registrar el cobro.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    reemplazar(pedido) {
                        const i = this.pedidos.findIndex(p => p.id === pedido.id);
                        if (i >= 0) this.pedidos.splice(i, 1, pedido);
                    },

                    colorEtapa(code) {
                        return {
                            'prospectos': 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
                            'pedidos-confirmado': 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
                            'pedido-entregado': 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
                            'pedidos-cancelado': 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                        }[code] || 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
                    },

                    /** El motivo real del backend, que es lo que el asesor puede corregir. */
                    mensaje(e, porDefecto) {
                        return e?.response?.data?.message || porDefecto;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
