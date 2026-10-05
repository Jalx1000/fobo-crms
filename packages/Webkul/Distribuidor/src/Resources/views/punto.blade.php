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
        :mapa-centro='@json($mapaCentro)'
        :formas-pago='@json($formasPago)'
        productos-url="{{ route('admin.distribuidor.productos') }}"
        pedidos-url="{{ route('admin.distribuidor.pedidos') }}"
        buscar-contacto-url="{{ route('admin.distribuidor.contacto.buscar') }}"
        guardar-contacto-url="{{ route('admin.distribuidor.contacto.store') }}"
        guardar-pedido-url="{{ route('admin.distribuidor.pedido.store') }}"
        recientes-url="{{ route('admin.distribuidor.recientes') }}"
        caja-url="{{ route('admin.distribuidor.caja') }}"
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

                {{-- ── Tres pestañas grandes ── --}}
                <div class="mb-4 grid grid-cols-3 gap-2">
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

                    <button type="button" @click="irACaja"
                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 text-lg font-bold transition-colors duration-200"
                            :class="vista === 'caja'
                                ? 'border-brandColor bg-brandColor/10 text-brandColor'
                                : 'border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-400'">
                        Caja
                    </button>
                </div>

                <div v-if="aviso" class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-base text-red-700 dark:bg-red-900/25 dark:text-red-300">
                    @{{ aviso }}
                </div>

                {{-- ══════════════ NUEVO PEDIDO ══════════════ --}}
                <template v-if="vista === 'nuevo'">
                    <div v-if="!sucursal" class="rounded-xl border-2 border-dashed border-gray-300 px-4 py-10 text-center text-base text-gray-600 dark:text-gray-400 dark:border-gray-700">
                        Elegí una sucursal para empezar.
                    </div>

                    <template v-else>
                        {{-- Paso 1: el cliente, por teléfono --}}
                        <section class="mb-4 rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                            <h2 class="mb-3 text-lg font-bold text-gray-800 dark:text-gray-200">1 · Cliente</h2>

                            {{--
                                Clientes recientes: el atajo que saltea el teclado.

                                Escribir 10 dígitos con una mano, parado en la calle, es el
                                primer paso de TODOS los pedidos. Ordenados por último
                                pedido y no alfabéticamente: a quien le vendiste ayer es
                                mucho más probable que sea el de hoy.
                            --}}
                            <div v-if="!cliente && recientes.length" class="mb-3">
                                <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Últimos atendidos
                                </span>

                                <div class="grid grid-cols-2 gap-2">
                                    <button v-for="r in recientes" :key="r.id" type="button"
                                            @click="elegirReciente(r)"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-200 px-3 py-2 text-left transition-colors duration-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800">
                                        <span class="block truncate text-base font-semibold text-gray-800 dark:text-gray-200">@{{ r.name }}</span>
                                        <span class="block truncate text-sm text-gray-600 dark:text-gray-400">@{{ r.telefono }}</span>
                                    </button>
                                </div>
                            </div>

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
                                {{--
                                    Los bidones que el cliente YA tiene. Se pide al cargarlo
                                    porque es el único momento en que alguien lo sabe: después
                                    nadie va a abrir una pantalla aparte a completarlo, y el
                                    saldo arranca mal para siempre.
                                --}}
                                <label for="punto-bidones" class="mb-1 block text-sm font-medium text-amber-900 dark:text-amber-200">
                                    Bidones que ya tiene <span class="font-normal">(opcional)</span>
                                </label>
                                <input id="punto-bidones" v-model="nuevo.bidones" type="number" min="0" step="1"
                                       inputmode="numeric" placeholder="0"
                                       class="mb-2 min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-xl focus:border-brandColor focus:outline-none focus:ring-2 focus:ring-brandColor dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">

                                {{--
                                    El domicilio sobre el mapa. Se toca el mapa para poner el
                                    pin, o se usa el GPS si el distribuidor está en la puerta.
                                    Los dos caminos sirven: a veces se carga el cliente ahí
                                    mismo y a veces por teléfono desde el depósito.
                                --}}
                                <div class="mb-2 flex flex-col justify-between gap-2">
                                    <span class="text-sm font-medium text-amber-900 dark:text-amber-200">
                                        Domicilio <span class="font-normal">(opcional)</span>
                                    </span>
                                    <button type="button" @click="ubicacionDelGps" :disabled="ubicando"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[44px] shrink-0 rounded-xl border-2 border-brandColor py-3 text-sm font-bold text-brandColor disabled:opacity-40">
                                        @{{ ubicando ? 'Tomando…' : 'Estoy acá' }}
                                    </button>
                                </div>

                                <div ref="mapaNuevo" class="mb-1 h-56 w-full overflow-hidden rounded-xl border-2 border-gray-300 bg-gray-100 dark:border-gray-700 dark:bg-gray-800"
                                     role="application" aria-label="Mapa para marcar el domicilio del cliente"></div>

                                <p v-if="mapaError" class="mb-2 text-base text-red-700 dark:text-red-400">@{{ mapaError }}</p>
                                <p v-else class="mb-2 text-sm text-amber-800 dark:text-amber-300">
                                    @{{ nuevo.lat ? 'Domicilio marcado. Tocá el mapa para corregirlo.' : 'Tocá el mapa donde vive el cliente.' }}
                                </p>

                                <button type="button" @click="crearCliente" :disabled="cargando || !nombre.trim()"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] w-full rounded-xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-50">
                                    Crear contacto
                                </button>
                            </div>

                            {{-- Elegido --}}
                            <div v-if="cliente" class="flex flex-col items-center justify-between gap-3 rounded-xl bg-emerald-50 p-4 dark:bg-emerald-900/20">
                                <div class="min-w-0">
                                    <div class="truncate text-xl font-bold text-gray-800 dark:text-gray-100">@{{ cliente.name }}</div>
                                    <div class="text-base text-gray-600 dark:text-gray-400">@{{ cliente.telefono }}</div>
                                    {{--
                                        Los bidones que tiene en su casa, acá y no en otra
                                        pantalla: es el momento en que el distribuidor decide
                                        cuántos bajar de la camioneta. Sólo si tiene: un "0
                                        bidones" en cada cliente es ruido.
                                    --}}
                                    <div v-if="cliente.bidones" class="mt-1 flex items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-0.5 text-base font-bold text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                            @{{ cliente.bidones }} bidón@{{ cliente.bidones === 1 ? '' : 'es' }} sin devolver
                                        </span>
                                    </div>

                                    {{--
                                        El domicilio marcado SE VE, no se anuncia.
                                        Antes acá había un texto que decía "Domicilio marcado":
                                        confirmaba que había un dato pero no dejaba comprobar si
                                        estaba bien, y un pin a dos cuadras se ve igual que uno
                                        correcto cuando sólo hay una etiqueta.
                                        El mapa es chico y no interactivo —tocarlo abre el editor—
                                        porque acá el trabajo es RECONOCER la cuadra de un
                                        vistazo, no navegar.
                                    --}}
                                    <div v-if="cliente.ubicacion" class="mt-2">
                                        <button type="button" @click="abrirMapaCliente()"
                                                aria-label="Ver y editar el domicilio en el mapa"
                                                class="block w-full cursor-pointer overflow-hidden rounded-xl border-2 border-emerald-300 focus:outline-none focus:ring-2 focus:ring-brandColor dark:border-emerald-800">
                                            <div ref="mapaVista" class="h-32 w-full bg-gray-100 dark:bg-gray-800"
                                                 role="img" aria-label="Mapa con el domicilio del cliente"></div>
                                        </button>

                                        <a :href="cliente.ubicacion.url" target="_blank" rel="noopener"
                                           class="cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mt-1 inline-flex items-center gap-1 text-base font-semibold text-brandColor hover:underline">
                                            <svg class="h-5 w-full shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z"/></svg>
                                            Abrir en el mapa
                                        </a>
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-4">
                                    {{--
                                        Repetir: en este rubro el mismo cliente pide lo mismo
                                        cada semana. Convierte un pedido de seis toques en dos.
                                    --}}
                                    <button type="button" @click="repetirPedido" :disabled="cargando"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-brandColor px-5 text-base font-bold text-brandColor transition-colors duration-200 disabled:opacity-40">
                                        Repetir
                                    </button>
                                    {{--
                                        Marcar el domicilio con el GPS. Va acá, en la ficha del
                                        cliente, porque el domicilio es del cliente y no del
                                        pedido: se marca una vez y sirve para todos los repartos
                                        siguientes.
                                    --}}
                                    <button type="button" @click="abrirMapaCliente()"
                                            :aria-label="cliente.ubicacion ? 'Editar el domicilio en el mapa' : 'Marcar el domicilio en el mapa'"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-brandColor px-4 text-base font-bold text-brandColor transition-colors duration-200 disabled:opacity-40">
                                        <svg class="inline h-6 w-full shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                        <span class="sr-only">@{{ cliente.ubicacion ? 'Editar domicilio' : 'Marcar domicilio' }}</span>
                                    </button>

                                    <button type="button" @click="limpiarCliente"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] shrink-0 rounded-xl border-2 border-gray-300 px-5 text-base font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                        Cambiar
                                    </button>
                                </div>
                            </div>
                        </section>

                        {{-- Paso 2: los productos de ESA sucursal --}}
                        <section class="mb-4 rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                            <h2 class="mb-1 text-lg font-bold text-gray-800 dark:text-gray-200">2 · Productos</h2>
                            <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">Precios de @{{ sucursal.name }}</p>

                            <div v-if="!productos.length" class="rounded-xl border-2 border-dashed border-gray-300 px-4 py-8 text-center text-base text-gray-600 dark:text-gray-400 dark:border-gray-700">
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
                            <h2 class="mb-3 text-lg font-bold text-gray-800 dark:text-gray-200">3 · Dirección y referencia <span class="text-sm font-normal text-gray-600 dark:text-gray-400">(opcional)</span></h2>
                            <label for="punto-nota" class="sr-only">Dirección y referencia de entrega</label>
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
                            Para entregar
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
                        No hay selector de alcance: el pedido se ve SÓLO desde su propia
                        sucursal. Los de Jujuy no ven los de Palpalá ni al revés — son
                        operaciones distintas, con su catálogo y sus precios. Un "Todas"
                        acá era una invitación a tocar el pedido de otra ciudad.
                    --}}

                    <div v-if="cargando" class="py-10 text-center text-base text-gray-600 dark:text-gray-400">Cargando…</div>

                    <div v-else-if="!pedidos.length" class="rounded-xl border-2 border-dashed border-gray-300 px-4 py-10 text-center text-base text-gray-600 dark:text-gray-400 dark:border-gray-700">
                        @{{ filtro === 'pendientes'
                            ? 'No hay pedidos confirmados para entregar acá. Los que acabás de cargar están en "Mis pedidos de hoy", para confirmarlos.'
                            : 'Todavía no tomaste pedidos hoy.' }}
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

                        {{--
                            QUÉ hay que entregar.

                            Faltaba: la tarjeta mostraba cliente, etapa y plata, pero no el
                            contenido del pedido. El distribuidor salía con la lista y
                            ninguna tarjeta decía si eran dos botellones o una bomba — que
                            es el dato central del reparto. Encontrado recorriendo el flujo.
                        --}}
                        <ul v-if="pe.productos && pe.productos.length" class="mb-3 space-y-1">
                            <li v-for="(pr, k) in pe.productos" :key="k"
                                class="flex items-baseline justify-between gap-2 border-b border-dashed border-gray-200 pb-1 last:border-0 dark:border-gray-800">
                                <span class="text-base text-gray-800 dark:text-gray-200">
                                    <span class="font-bold">@{{ pr.quantity }}×</span> @{{ pr.name }}
                                </span>
                                <span class="shrink-0 text-base text-gray-600 dark:text-gray-400">@{{ money(pr.price * pr.quantity) }}</span>
                            </li>
                        </ul>

                        {{-- El saldo es el número que importa: va grande --}}
                        {{--
                            Dos columnas en el celular y tres desde sm.
                        
                            Con tres a 375px cada celda queda en unos 105px, y un importe de seis
                            cifras en text-2xl necesita mas del doble: el saldo se partia en dos
                            lineas en el medio del numero. El saldo pasa a ocupar la fila entera, que
                            ademas es lo primero que hay que leer.
                        --}}
                        <div class="mb-3 grid grid-cols-2 gap-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-800/50 sm:grid-cols-3">
                            <div>
                                <div class="text-xs uppercase text-gray-600 dark:text-gray-400">Total</div>
                                <div class="text-lg font-bold text-gray-800 dark:text-gray-200">@{{ money(pe.total) }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase text-gray-600 dark:text-gray-400">Cobrado</div>
                                <div class="text-lg font-bold text-emerald-600">@{{ money(pe.cobrado) }}</div>
                            </div>
                            <div class="col-span-2 sm:col-span-1">
                                <div class="text-xs uppercase text-gray-600 dark:text-gray-400">Saldo</div>
                                <div class="text-2xl font-extrabold" :class="pe.saldo > 0 ? 'text-red-600' : 'text-emerald-600'">
                                    @{{ money(pe.saldo) }}
                                </div>
                            </div>
                        </div>

                        {{--
                            Dónde entregar.

                            La referencia escrita va PRIMERO y el mapa después, no al revés:
                            "portón verde y hay un árbol afuera" es lo que de verdad usa
                            quien toca el timbre, y un pin puede estar a 20 metros.
                        --}}
                        <div v-if="pe.ubicacion && (pe.ubicacion.referencia || pe.ubicacion.url)"
                             class="mb-3 rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-800/50">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                Dónde entregar
                            </span>

                            <p v-if="pe.ubicacion.referencia" class="mb-2 break-words text-base text-gray-800 dark:text-gray-200">
                                @{{ pe.ubicacion.referencia }}
                            </p>

                            <a v-if="pe.ubicacion.url" :href="pe.ubicacion.url" target="_blank" rel="noopener"
                               class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 flex min-h-[56px] items-center justify-center gap-2 rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-white dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                                </svg>
                                @{{ pe.ubicacion.modo === 'pin' ? 'Ver en el mapa' : 'Buscar en el mapa' }}
                            </a>

                            {{--
                                Decir cuál de los dos es: un pin exacto y una búsqueda por
                                dirección no dan la misma confianza, y el distribuidor tiene
                                que saber si llega al punto o a la cuadra.
                            --}}
                            <p v-if="pe.ubicacion.modo === 'busqueda'" class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                Sin coordenadas: el mapa busca la dirección, puede no ser exacta.
                            </p>
                            <p v-else-if="pe.ubicacion.fuente === 'whatsapp'" class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">
                                Ubicación que mandó el cliente por WhatsApp.
                            </p>
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

                        {{--
                            UNA acción principal por paso.

                            Antes la tarjeta mostraba Confirmar, Cobrar, WhatsApp y Cancelar
                            al mismo tiempo: cuatro botones del mismo tamaño obligaban a
                            decidir cuál toca ahora, en cada tarjeta, con el pedido en la
                            mano. El avance de un pedido es lineal, así que la tarjeta
                            muestra sólo el paso que sigue:

                              Prospecto              → Confirmar
                              Confirmado, sin cobrar → Cobrar
                              Confirmado y cobrado   → Entregar (abre la cámara)
                              Entregado              → nada, está cerrado

                            No hay paso de cobro parcial: en el Punto siempre se cobra al
                            contado, el total. Un descuento se hace ajustando el precio al
                            armar el pedido, no cobrando de menos.

                            Se cobra ANTES de entregar: la plata se recibe y después se
                            baja la mercadería. Entregar primero y cobrar después deja al
                            distribuidor sin respaldo si el cliente no paga.

                            WhatsApp y Cancelar quedan abajo y más chicos: no son pasos del
                            pedido, son salidas laterales.
                        --}}
                        <button v-if="pe.etapa_code === 'prospectos'" type="button" @click="avanzar(pe, 'confirmado')"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-2 min-h-[64px] w-full rounded-xl bg-brandColor text-xl font-bold text-white transition-colors duration-200">
                            Confirmar
                        </button>

                        {{--
                            Entregar abre el diálogo de confirmación, no la cámara: la foto
                            es la mejor prueba de que el pedido llegó pero NO es obligatoria,
                            y abrirla sola tapaba los campos de bidones antes de que se
                            pudieran ver. Dentro del diálogo, `capture="environment"` abre la
                            cámara trasera nativa — más confiable que getUserMedia en iOS, y
                            no pide permisos hasta que el usuario toca el botón.
                        --}}
                        <button v-else-if="pe.etapa_code === 'pedidos-confirmado' && pe.cobrado <= 0" type="button" @click="abrirCobro(pe)"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-2 min-h-[64px] w-full rounded-xl bg-brandColor text-xl font-bold text-white transition-colors duration-200">
                            Cobrar @{{ money(pe.total) }}
                        </button>

                        {{--
                            La condición dice `cobrado > 0` explícitamente aunque la cadena
                            v-else-if ya lo garantice: quien reordene los bloques más
                            adelante rompería el orden cobrar→entregar sin notarlo.
                        --}}
                        <button v-else-if="pe.etapa_code === 'pedidos-confirmado' && pe.cobrado > 0" type="button" @click="abrirEntrega(pe)"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-2 min-h-[64px] w-full rounded-xl bg-emerald-600 text-xl font-bold text-white transition-colors duration-200">
                            <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.9 47.9 0 0 0-1.134-.175a2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0a2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg>Entregar
                        </button>

                        
                        <p v-else-if="pe.etapa_code === 'pedido-entregado'" class="mb-2 rounded-xl bg-emerald-50 py-3 text-center text-base font-bold text-emerald-800 dark:bg-emerald-900/25 dark:text-emerald-300">
                            Entregado y cobrado
                        </p>

                        {{-- Salidas laterales: no son pasos del pedido. --}}
                        <div class="grid grid-cols-2 gap-2">
                            <a :href="'{{ url(config('app.admin_path').'/distribuidor/whatsapp') }}/' + pe.id"
                               class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 flex min-h-[56px] items-center justify-center rounded-xl border-2 border-gray-300 text-base font-semibold text-gray-700 transition-colors duration-200 dark:border-gray-700 dark:text-gray-300">
                                WhatsApp
                            </a>

                            <button v-if="pe.etapa_code !== 'pedidos-cancelado' && pe.etapa_code !== 'pedido-entregado'"
                                    type="button" @click="avanzar(pe, 'cancelado')"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-red-300 text-base font-semibold text-red-600 transition-colors duration-200 dark:border-red-800">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </template>

                {{-- ══════════════ CAJA DEL DÍA ══════════════ --}}
                <template v-if="vista === 'caja'">
                    {{--
                        El número que necesita quien cierra la jornada entregando
                        efectivo. Cortado por forma de pago porque lo que se rinde en
                        mano es sólo la parte en efectivo.
                    --}}
                    <div class="mb-4 rounded-2xl border-2 border-brandColor p-5 text-center">
                        <span class="block text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                            Cobrado hoy
                        </span>
                        <span class="block text-4xl font-extrabold text-gray-900 dark:text-white">@{{ money(caja.total) }}</span>
                        <span class="block text-base text-gray-600 dark:text-gray-400">
                            @{{ caja.cobros }} cobro(s) · @{{ caja.pedidos_del_dia }} pedido(s)
                        </span>
                    </div>

                    <div v-for="f in caja.por_forma" :key="f.key"
                         class="mb-2 flex items-center justify-between rounded-xl border-2 border-gray-200 px-4 py-3 dark:border-gray-800">
                        <span class="text-lg font-semibold text-gray-800 dark:text-gray-200">@{{ f.label }}</span>
                        <span class="text-right">
                            <span class="block text-xl font-bold text-gray-900 dark:text-white">@{{ money(f.total) }}</span>
                            <span class="block text-sm text-gray-600 dark:text-gray-400">@{{ f.cobros }} cobro(s)</span>
                        </span>
                    </div>

                    {{--
                        Los bidones se cuadran igual que la plata: lo que dejó salió de
                        la camioneta y lo que retiró tiene que volver a ella. La
                        diferencia es el número que se mira al cerrar el día, así que va
                        destacado y no como un dato más.
                    --}}
                    <div class="mt-4 rounded-2xl border-2 border-sky-300 p-4 dark:border-sky-800">
                        <span class="mb-2 block text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                            Bidones de hoy
                        </span>

                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div>
                                <span class="block text-2xl font-extrabold text-gray-900 dark:text-white">@{{ caja.bidones.entregados }}</span>
                                <span class="block text-sm text-gray-600 dark:text-gray-400">dejados</span>
                            </div>
                            <div>
                                <span class="block text-2xl font-extrabold text-gray-900 dark:text-white">@{{ caja.bidones.retirados }}</span>
                                <span class="block text-sm text-gray-600 dark:text-gray-400">retirados</span>
                            </div>
                            <div>
                                <span class="block text-2xl font-extrabold" :class="caja.bidones.en_la_calle > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
                                    @{{ caja.bidones.en_la_calle }}
                                </span>
                                <span class="block text-sm text-gray-600 dark:text-gray-400">sin volver</span>
                            </div>
                        </div>
                    </div>

                    {{-- A quién hay que ir a buscarle los envases. Ordenado por cantidad. --}}
                    <div v-if="caja.a_recuperar.length" class="mt-4">
                        <span class="mb-2 block text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                            Bidones a recuperar
                        </span>

                        <div v-for="c in caja.a_recuperar" :key="c.person_id"
                             class="mb-2 flex items-center justify-between rounded-xl border-2 border-gray-200 px-4 py-3 dark:border-gray-800">
                            <span class="min-w-0 truncate text-lg font-semibold text-gray-800 dark:text-gray-200">@{{ c.nombre }}</span>
                            <span class="ml-2 shrink-0 rounded-full bg-sky-100 px-3 py-1 text-lg font-bold text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                @{{ c.saldo }}
                            </span>
                        </div>
                    </div>
                </template>

                {{-- ── Barra fija: el total y la acción, al alcance del pulgar ── --}}
                <div v-if="vista === 'nuevo' && sucursal"
                     class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                    <div class="mx-auto flex max-w-5xl items-center gap-3">
                        <div class="shrink-0">
                            <div class="text-xs uppercase text-gray-600 dark:text-gray-400">Total</div>
                            {{--
                                text-xl en el celular: el total es shrink-0, asi que cada pixel que ocupa
                                se lo saca al boton de al lado. Con seis cifras en text-2xl el boton
                                quedaba por debajo del ancho usable.
                            --}}
                            <div class="text-xl font-extrabold text-gray-900 dark:text-white sm:text-2xl">@{{ money(total) }}</div>
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
                        <div role="dialog" aria-modal="true" aria-labelledby="titulo-entrega"
                             class="w-full max-w-md rounded-t-2xl bg-white p-5 dark:bg-gray-900 sm:rounded-2xl">
                            <h3 id="titulo-entrega" class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">Confirmar entrega</h3>
                            <p class="mb-3 text-base text-gray-600 dark:text-gray-400">
                                Pedido #@{{ entrega.pedido.id }} · @{{ entrega.pedido.cliente }}
                            </p>

                            <img v-if="entrega.preview" :src="entrega.preview" alt="Foto tomada"
                                 class="mb-3 max-h-64 w-full rounded-xl object-contain">

                            {{--
                                Se dice que es opcional en vez de dejarlo adivinar: antes el
                                botón quedaba gris sin explicar por qué, y el distribuidor
                                creía que la app estaba trabada.
                            --}}
                            <p v-if="!entrega.preview" class="mb-3 text-base text-gray-500 dark:text-gray-400">
                                La foto es opcional, pero es la mejor prueba de que el pedido llegó.
                            </p>

                            {{--
                                Los bidones de esta visita. Dos cajas y no una: lo que se deja y
                                lo que se retira son cantidades distintas —se dejan 2 llenos y se
                                traen 3 vacíos de la semana pasada— y con un solo número no hay
                                forma de saber cuántos quedaron en la casa.

                                inputmode="numeric" para que el celular abra el teclado de
                                números: son dos campos que se completan parado en la puerta.
                            --}}
                            <div class="mb-3 rounded-xl border-2 border-sky-200 bg-sky-50 p-3 dark:border-sky-900 dark:bg-sky-900/20">
                                <div class="mb-2 flex items-baseline justify-between">
                                    <span class="text-base font-bold text-gray-800 dark:text-gray-100">Bidones</span>
                                    <span v-if="entrega.pedido.bidones" class="text-base text-gray-600 dark:text-gray-400">
                                        Tenía @{{ entrega.pedido.bidones }}
                                    </span>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <label class="block">
                                        <span class="mb-1 block text-base font-semibold text-gray-700 dark:text-gray-300">Dejo</span>
                                        <input v-model="entrega.dejo" type="number" min="0" step="1" inputmode="numeric"
                                               placeholder="0"
                                               class="min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-3 text-center text-xl font-bold text-gray-900 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-base font-semibold text-gray-700 dark:text-gray-300">Retiro</span>
                                        <input v-model="entrega.retiro" type="number" min="0" step="1" inputmode="numeric"
                                               placeholder="0"
                                               class="min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-3 text-center text-xl font-bold text-gray-900 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                    </label>
                                </div>

                                {{-- El saldo que va a quedar, calculado en vivo: así se ve el error antes de confirmar. --}}
                                <p v-if="saldoBidonesResultante !== null" class="mt-2 text-base text-gray-700 dark:text-gray-300">
                                    Le quedan <span class="font-bold">@{{ saldoBidonesResultante }}</span> bidón@{{ saldoBidonesResultante === 1 ? '' : 'es' }}
                                </p>
                            </div>

                            <button type="button" @click="abrirCamara"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-3 flex min-h-[56px] w-full cursor-pointer items-center justify-center rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">
                                <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.9 47.9 0 0 0-1.134-.175a2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0a2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg>@{{ entrega.preview ? 'Sacar otra foto' : 'Sacar la foto' }}
                            </button>

                            {{--
                                Marcar el domicilio acá y no sólo en la pestaña de pedido
                                nuevo: entregar es el único momento en que el teléfono está
                                de verdad en la puerta del cliente, y un distribuidor que
                                reparte un pedido ya cargado nunca pasa por esa pestaña.
                            --}}
                            <button v-if="entrega.pedido.person_id" type="button"
                                    @click="marcarUbicacion(entrega.pedido.person_id)"
                                    :disabled="ubicando || entrega.ubicacionMarcada"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-3 flex min-h-[56px] w-full items-center justify-center rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                @{{ entrega.ubicacionMarcada ? 'Domicilio marcado' : (ubicando ? 'Tomando ubicación…' : 'Marcar este domicilio') }}
                            </button>

                            {{-- Marcado: se puede abrir para comprobar dónde cayó. --}}
                            <a v-if="entrega.ubicacion" :href="entrega.ubicacion.url" target="_blank" rel="noopener"
                               class="cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-3 -mt-2 block text-base font-semibold text-brandColor hover:underline">
                                Ver dónde quedó marcado
                            </a>

                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="cancelarEntrega"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="confirmarEntrega" :disabled="cargando"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-40">
                                    @{{ cargando ? 'Enviando…' : (entrega.archivo ? 'Entregado' : 'Entregado sin foto') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                {{--
                    ── Editar el domicilio sobre el mapa ──

                    Modal y no un bloque fijo en la ficha: el mapa necesita alto para ser
                    usable con el dedo, y robarle media pantalla al armado del pedido para
                    algo que se toca una vez por cliente es un mal canje.

                    Ofrece los dos caminos juntos, que es la razón de ser de esta pantalla:
                    arrastrar el pin sirve desde el depósito con el cliente al teléfono, y
                    "Estoy acá" sirve parado en la puerta.
                --}}
                <Teleport to="body">
                    <div v-if="mapaCliente" class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 sm:items-center" @click.self="cerrarMapaCliente">
                        <div role="dialog" aria-modal="true" aria-labelledby="titulo-mapa"
                             class="w-full max-w-lg rounded-t-2xl bg-white p-5 dark:bg-gray-900 sm:rounded-2xl">
                            <h3 id="titulo-mapa" class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">
                                @{{ mapaCliente.ubicacion ? 'Editar domicilio' : 'Marcar domicilio' }}
                            </h3>
                            <p class="mb-3 text-base text-gray-600 dark:text-gray-400">@{{ mapaCliente.name }}</p>

                            <div ref="mapaEditar" class="mb-2 h-72 w-full overflow-hidden rounded-xl border-2 border-gray-300 bg-gray-100 dark:border-gray-700 dark:bg-gray-800"
                                 role="application" aria-label="Mapa para editar el domicilio del cliente"></div>

                            <p v-if="mapaError" class="mb-2 text-base text-red-700 dark:text-red-400">@{{ mapaError }}</p>
                            <p v-else class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                                Tocá el mapa o arrastrá el pin. Si estás en la puerta, usá el GPS.
                            </p>

                            {{--
                                Poner el pin en el centro del mapa.

                                No es un atajo: es el ÚNICO camino con teclado. Tocar el mapa y
                                arrastrar el marcador son gestos de mouse o dedo, así que sin
                                esto un usuario de teclado no podía marcar ningún domicilio
                                —Leaflet mueve el mapa con las flechas, pero no el pin—.
                                De paso sirve con el dedo: mover el mapa y tocar un botón grande
                                es más preciso que acertarle a un punto exacto en la pantalla.
                            --}}
                            <button type="button" @click="pinAlCentro"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-2 flex min-h-[56px] w-full items-center justify-center rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                Poner el pin en el centro
                            </button>

                            <button type="button" @click="gpsAlMapa" :disabled="ubicando"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-3 flex min-h-[56px] w-full items-center justify-center rounded-xl border-2 border-brandColor text-base font-bold text-brandColor transition-colors duration-200 disabled:opacity-40">
                                <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                @{{ ubicando ? 'Tomando ubicación…' : 'Estoy acá (GPS)' }}
                            </button>

                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="cerrarMapaCliente"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="guardarMapaCliente" :disabled="cargando || !nuevo.lat"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-600 text-lg font-bold text-white disabled:opacity-40">
                                    @{{ cargando ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                {{-- ── Cobro: dentro del pedido, no en otra sección ── --}}
                <Teleport to="body">
                    <div v-if="cobro.pedido" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center" @click.self="cobro.pedido = null">
                        {{--
                            role/aria-modal/aria-labelledby: sin eso un lector de pantalla no
                            anuncia que se abrió un diálogo ni lo nombra, y lee el fondo como
                            si siguiera disponible. El cierre con Escape lo maneja un listener
                            global (ver mounted), que funciona sin depender de dónde esté el foco.
                        --}}
                        <div role="dialog" aria-modal="true" aria-labelledby="titulo-cobro"
                             class="w-full max-w-md rounded-t-2xl bg-white p-5 dark:bg-gray-900 sm:rounded-2xl">
                            <h3 id="titulo-cobro" class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">¿Cómo pagó?</h3>

                            {{--
                                Sin campo de importe y sin atajos.

                                En el Punto siempre se cobra al contado: el importe es el
                                total del pedido y lo calcula el SERVIDOR. Un input editable
                                acá era una forma de equivocarse sin ninguna ventaja —el
                                precio ya es ajustable al armar el pedido—, y los atajos
                                "Todo el saldo / La mitad" ofrecían un cobro parcial que no
                                existe en este negocio.

                                Queda en dos toques: forma de pago y confirmar.
                            --}}
                            <div class="mb-4 rounded-xl bg-gray-50 px-4 py-3 text-center dark:bg-gray-800/50">
                                <span class="block text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                    A cobrar
                                </span>
                                <span class="block text-3xl font-extrabold text-gray-900 dark:text-white">
                                    @{{ money(cobro.pedido.saldo) }}
                                </span>
                                <span class="block truncate text-base text-gray-600 dark:text-gray-400">
                                    @{{ cobro.pedido.cliente || cobro.pedido.title }}
                                </span>
                            </div>

                            <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Forma de pago</span>

                            {{--
                                Apiladas en el celular. En tres columnas cada boton queda en 106px y
                                "Transferencia" no entra: se partia en dos lineas o desbordaba segun la
                                fuente del sistema. Tres botones de ancho completo ademas son lo mas
                                facil de acertar con una mano.
                            --}}
                            <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <button v-for="(label, key) in formasPago" :key="key" type="button"
                                        @click="cobro.method = key"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 text-base font-semibold transition-colors duration-200"
                                        :class="cobro.method === key
                                            ? 'border-brandColor bg-brandColor text-white'
                                            : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                                    @{{ label }}
                                </button>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="cobro.pedido = null"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 transition-colors duration-200 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="registrarCobro" :disabled="!cobro.method || cargando"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-600 text-lg font-bold text-white transition-colors duration-200 disabled:opacity-40">
                                    @{{ cargando ? 'Registrando…' : 'Cobrado' }}
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
                    mapaCentro: { type: Object, default: () => ({ lat: -24.19, lng: -65.3, zoom: 11 }) },
                    formasPago: { type: Object, default: () => ({}) },
                    productosUrl: { type: String, default: '' },
                    pedidosUrl: { type: String, default: '' },
                    buscarContactoUrl: { type: String, default: '' },
                    guardarContactoUrl: { type: String, default: '' },
                    guardarPedidoUrl: { type: String, default: '' },
                    recientesUrl: { type: String, default: '' },
                    cajaUrl: { type: String, default: '' },
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
                        telefono: '',
                        nombre: '',
                        cliente: null,
                        buscado: false,
                        encontrado: false,
                        nota: '',
                        pedidos: [],
                        recientes: [],
                        caja: {
                            total: 0, cobros: 0, pedidos_del_dia: 0, por_forma: [],
                            bidones: { entregados: 0, retirados: 0, visitas: 0, en_la_calle: 0 },
                            a_recuperar: [],
                        },
                        // Sin `amount`: el importe lo calcula el servidor (siempre el total).
                        cobro: { pedido: null, method: 'efectivo' },
                        // dejo/retiro arrancan vacíos y no en 0: un 0 precargado se
                        // confirma sin leerlo, y entonces el control no sirve de nada.
                        entrega: { pedido: null, archivo: null, preview: null, dejo: '', retiro: '', ubicacionMarcada: false, ubicacion: null },
                        ubicando: false,
                        // El contacto que se está creando: domicilio y envases iniciales.
                        nuevo: { bidones: '', lat: null, lng: null },
                        mapaError: null,
                        // El cliente cuyo domicilio se está editando en el mapa.
                        mapaCliente: null,
                        /*
                         * La vista del domicilio en la ficha. Estado PROPIO y no el de
                         * `mapa`: la vista y el editor pueden estar en pantalla a la vez
                         * —se abre el editor desde la vista— y un solo objeto de Leaflet no
                         * puede dibujarse en dos contenedores.
                         */
                        vistaMapa: null,
                        vistaPin: null,
                        mapa: null,
                        pin: null,
                        leafletPromise: null,
                        cargando: false,
                        aviso: null,
                    };
                },

                computed: {
                    /** Si está visible el formulario de contacto nuevo. */
                    mostrarFormularioNuevo() {
                        return ! this.cliente && this.buscado && ! this.encontrado;
                    },

                    /*
                     * Cuántos bidones le quedan al cliente si se confirma así.
                     *
                     * Se muestra en vivo para que el error se vea ANTES de confirmar:
                     * cargar "retiro 5" cuando sólo tenía 2 deja un saldo negativo, y
                     * verlo en pantalla es lo que evita que se guarde mal.
                     *
                     * null cuando no se tocó ninguna de las dos cajas: no hay nada que
                     * anticipar y un número de más distrae.
                     */
                    saldoBidonesResultante() {
                        if (! this.entrega.pedido) return null;
                        if (this.entrega.dejo === '' && this.entrega.retiro === '') return null;

                        const tenia = Number(this.entrega.pedido.bidones) || 0;

                        return tenia + this.enteroDe(this.entrega.dejo) - this.enteroDe(this.entrega.retiro);
                    },

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
                    /*
                     * Escape cierra el modal abierto.
                     *
                     * Listener en window y no @keydown en el diálogo: el foco puede estar en
                     * el overlay, en un input o en ningún lado, y un handler atado al
                     * elemento sólo dispara si el foco está adentro. Con teclado, un modal
                     * que no se cierra con Escape es un modal del que no se sale.
                     */
                    this.alEscape = e => {
                        if (e.key !== 'Escape') return;

                        // El mapa primero: es el que puede quedar abierto ENCIMA de otro,
                        // y Escape tiene que cerrar el de arriba.
                        if (this.mapaCliente) this.cerrarMapaCliente();
                        else if (this.entrega.pedido) this.cancelarEntrega();
                        else if (this.cobro.pedido) this.cobro.pedido = null;
                    };

                    window.addEventListener('keydown', this.alEscape);

                    // Una sola sucursal: no se pregunta lo obvio.
                    if (this.sucursales.length === 1) {
                        this.elegirSucursal(this.sucursales[0]);
                    }
                },

                beforeUnmount() {
                    // Sin esto el listener sobrevive al componente y se acumula.
                    if (this.alEscape) window.removeEventListener('keydown', this.alEscape);
                },

                watch: {
                    /*
                     * El mapa se monta cuando el formulario APARECE y no al cargar la
                     * pantalla: Leaflet mide el contenedor al crearse, y sobre un div que
                     * todavía está oculto lo mide en 0px y el mapa queda en blanco. Además
                     * así no se pide un solo tile mientras nadie esté cargando un contacto.
                     */
                    mostrarFormularioNuevo(visible) {
                        if (visible) this.$nextTick(() => this.montarMapa('mapaNuevo'));
                    },

                    /*
                     * Deep porque el domicilio se escribe DENTRO del cliente
                     * (cliente.ubicacion) al guardarlo: un watcher plano no se enteraría y
                     * la vista seguiría mostrando el pin viejo justo después de corregirlo,
                     * que es el único momento en que alguien la está mirando.
                     */
                    cliente: {
                        deep: true,
                        handler() {
                            this.$nextTick(() => this.montarVistaDomicilio());
                        },
                    },
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

                        this.cargarRecientes();

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

                    cargarRecientes() {
                        if (! this.sucursal) return;

                        this.$axios.get(this.recientesUrl, { params: { ciudad: this.sucursal.ciudad_id } })
                            .then(r => { this.recientes = r.data.recientes; })
                            // Silencioso a propósito: es un atajo, no el camino. Si falla,
                            // el campo de teléfono sigue estando.
                            .catch(() => { this.recientes = []; });
                    },

                    elegirReciente(r) {
                        this.cliente = r;
                        this.telefono = r.telefono || '';
                        this.buscado = true;
                        this.encontrado = true;
                    },

                    /** Trae las líneas del último pedido del cliente al carrito. */
                    repetirPedido() {
                        if (! this.cliente || ! this.sucursal) return;

                        this.cargando = true;
                        this.aviso = null;

                        this.$axios.get('{{ url(config('app.admin_path').'/distribuidor/repetir') }}/' + this.cliente.id, {
                            params: { sucursal: this.sucursal.id },
                        })
                            .then(r => {
                                if (! r.data.lineas.length) {
                                    this.aviso = r.data.motivo || 'No hay un pedido anterior para repetir.';

                                    return;
                                }

                                const carrito = {};
                                const precios = {};

                                r.data.lineas.forEach(l => {
                                    carrito[l.product_id] = l.quantity;
                                    precios[l.product_id] = l.price;
                                });

                                this.carrito = carrito;
                                this.precios = precios;
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo traer el pedido anterior.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    irACaja() {
                        this.vista = 'caja';
                        this.cargarCaja();
                    },

                    cargarCaja() {
                        this.$axios.get(this.cajaUrl)
                            .then(r => { this.caja = r.data; })
                            .catch(() => { this.aviso = 'No se pudo cargar la caja.'; });
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

                        this.$axios.post(this.guardarContactoUrl, {
                            telefono: this.telefono,
                            nombre: this.nombre,
                            // El domicilio y los envases viajan con el alta: el contacto
                            // todavía no existe, así que no hay a quién adjuntarlos después.
                            lat: this.nuevo.lat,
                            lng: this.nuevo.lng,
                            bidones: this.enteroDe(this.nuevo.bidones) || null,
                        })
                            .then(r => {
                                this.cliente = r.data.persona;
                                this.nuevo = { bidones: '', lat: null, lng: null };
                                this.limpiarMapa();
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo crear el contacto.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    limpiarCliente() {
                        this.cliente = null;
                        this.buscado = false;
                        this.encontrado = false;
                        this.nombre = '';
                        // El domicilio y los envases son del cliente que se descartó: dejarlos
                        // se los adjudicaría al siguiente.
                        this.nuevo = { bidones: '', lat: null, lng: null };
                        this.limpiarMapa();
                        this.limpiarVistaDomicilio();
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
                                this.cargarRecientes();
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

                    cargarPedidos() {
                        if (! this.sucursal) return;

                        this.cargando = true;

                        this.$axios.get(this.pedidosUrl, {
                            params: {
                                filtro: this.filtro,
                                // Obligatoria: el backend rechaza la consulta sin sucursal,
                                // porque sin ella devolvería pedidos de otras ciudades.
                                ciudad: this.sucursal ? this.sucursal.ciudad_id : null,
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

                    // ── Entrega ──
                    /*
                     * Abre el diálogo y NADA MÁS.
                     *
                     * Antes disparaba la cámara en el mismo paso, de cuando la foto era
                     * obligatoria. Con la foto opcional eso es un salto de contexto que
                     * nadie pidió: en el celular se abría la cámara encima del diálogo y
                     * en el escritorio el explorador de archivos, tapando los campos de
                     * bidones y el botón de domicilio antes de que se pudieran ver.
                     *
                     * Sacar la foto es ahora una acción más del diálogo, como las otras.
                     */
                    abrirEntrega(pedido) {
                        this.entrega = {
                            pedido,
                            archivo: null,
                            preview: null,
                            dejo: '',
                            retiro: '',
                            ubicacionMarcada: false,
                            ubicacion: null,
                        };
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

                        this.aviso = null;

                        this.comprimir(archivo)
                            .then(comprimida => {
                                this.entrega.archivo = comprimida;
                                this.entrega.preview = URL.createObjectURL(comprimida);
                            })
                            .catch(() => {
                                // Si la compresión falla se manda el original: es mejor
                                // intentar con el archivo grande que dejar al distribuidor
                                // sin poder confirmar la entrega.
                                this.entrega.archivo = archivo;
                                this.entrega.preview = URL.createObjectURL(archivo);
                            });
                    },

                    /**
                     * Reduce la foto antes de subirla.
                     *
                     * La cámara de un celular saca 3 a 8 MB, y nginx corta el cuerpo de la
                     * petición en 1 MB: el 413 llegaba como HTML, sin JSON, y el asesor
                     * sólo veía "No se pudo confirmar la entrega".
                     *
                     * Se comprime en el cliente y no se sube el original porque también es
                     * lo correcto para el caso de uso: esto es una prueba de entrega, no
                     * una foto de catálogo. A 1600px de lado mayor y JPEG 0.75 queda en
                     * 200-400 KB —se ve perfecto quién recibió y dónde— y sube rápido con
                     * datos móviles, que es la red real de un distribuidor en la calle.
                     */
                    comprimir(archivo, ladoMaximo = 1600, calidad = 0.75) {
                        return new Promise((resolve, reject) => {
                            const img = new Image();
                            const url = URL.createObjectURL(archivo);

                            img.onload = () => {
                                URL.revokeObjectURL(url);

                                const escala = Math.min(1, ladoMaximo / Math.max(img.width, img.height));

                                const canvas = document.createElement('canvas');
                                canvas.width = Math.round(img.width * escala);
                                canvas.height = Math.round(img.height * escala);
                                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);

                                canvas.toBlob(
                                    blob => blob
                                        ? resolve(new File([blob], 'entrega.jpg', { type: 'image/jpeg' }))
                                        : reject(new Error('toBlob vacío')),
                                    'image/jpeg',
                                    calidad,
                                );
                            };

                            img.onerror = () => {
                                URL.revokeObjectURL(url);
                                reject(new Error('no se pudo leer la imagen'));
                            };

                            img.src = url;
                        });
                    },

                    /*
                     * Leaflet desde el CDN, una sola vez por página.
                     *
                     * Diferido y no en el bundle: el mapa lo usa una pantalla de las tres, y
                     * el distribuidor abre el Punto desde la calle con datos móviles. Cargar
                     * la librería y su CSS sólo cuando hacen falta es la diferencia entre que
                     * la pantalla abra rápido o no.
                     */
                    cargarLeaflet() {
                        if (window.L) return Promise.resolve();
                        if (this.leafletPromise) return this.leafletPromise;

                        this.leafletPromise = new Promise((resolve, reject) => {
                            const css = document.createElement('link');
                            css.rel = 'stylesheet';
                            css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                            document.head.appendChild(css);

                            const js = document.createElement('script');
                            js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                            js.onload = resolve;
                            js.onerror = () => reject(new Error('No se pudo cargar el mapa. Revisá la conexión.'));
                            document.head.appendChild(js);
                        });

                        return this.leafletPromise;
                    },

                    /*
                     * Monta un mapa en el contenedor que se le pase.
                     *
                     * Recibe el ref y el punto inicial en vez de leerlos de la pantalla
                     * porque hay DOS mapas: el del alta de contacto y el de editar el
                     * domicilio de un cliente que ya existe. Son excluyentes —uno necesita
                     * que no haya cliente elegido y el otro que sí— así que comparten el
                     * estado; lo que cambia es dónde se dibujan y dónde arrancan.
                     */
                    montarMapa(ref = 'mapaNuevo', inicial = null) {
                        this.cargarLeaflet()
                            .then(() => {
                                const caja = this.$refs[ref];

                                if (! caja) return;

                                if (this.mapa) {
                                    // Reabierto: Leaflet lo midió mientras estaba oculto y
                                    // cree que mide 0px de alto.
                                    this.$nextTick(() => this.mapa.invalidateSize());

                                    return;
                                }

                                // Arranca en el domicilio que ya tiene, si lo tiene: abrir en
                                // el centro de la provincia obligaría a buscar la casa de cero
                                // cada vez que se corrige un pin de 20 metros.
                                const centro = inicial
                                    ? [inicial.lat, inicial.lng]
                                    : [this.mapaCentro.lat, this.mapaCentro.lng];

                                this.mapa = window.L.map(caja)
                                    .setView(centro, inicial ? 17 : this.mapaCentro.zoom);

                                window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    attribution: '© OpenStreetMap',
                                    maxZoom: 19,
                                }).addTo(this.mapa);

                                this.mapa.on('click', e => this.ponerPin(e.latlng.lat, e.latlng.lng, false));

                                if (inicial) this.ponerPin(inicial.lat, inicial.lng, false);

                                this.$nextTick(() => this.mapa.invalidateSize());
                            })
                            .catch(e => { this.mapaError = e.message; });
                    },

                    /** Pone (o mueve) el pin del domicilio y guarda la coordenada. */
                    ponerPin(lat, lng, centrar = true) {
                        this.nuevo.lat = lat;
                        this.nuevo.lng = lng;

                        if (! this.mapa) return;

                        if (! this.pin) {
                            this.pin = window.L.marker([lat, lng], { draggable: true }).addTo(this.mapa);
                            // Arrastrable además de tocable: corregir 20 metros con el dedo es
                            // más preciso que volver a tocar el punto exacto.
                            this.pin.on('dragend', () => {
                                const p = this.pin.getLatLng();
                                this.nuevo.lat = p.lat;
                                this.nuevo.lng = p.lng;
                            });
                        } else {
                            this.pin.setLatLng([lat, lng]);
                        }

                        if (centrar) this.mapa.setView([lat, lng], Math.max(this.mapa.getZoom(), 16));
                    },

                    /**
                     * La vista chica del domicilio en la ficha del cliente.
                     *
                     * No interactiva a propósito: acá el trabajo es reconocer la cuadra de un
                     * vistazo, y un mapa que se mueve con el dedo dentro de una pantalla que
                     * también se desplaza con el dedo pelea con el scroll. Tocarlo abre el
                     * editor, que sí es interactivo.
                     */
                    montarVistaDomicilio() {
                        const u = this.cliente?.ubicacion;

                        if (! u) {
                            this.limpiarVistaDomicilio();

                            return;
                        }

                        this.cargarLeaflet()
                            .then(() => {
                                const caja = this.$refs.mapaVista;

                                if (! caja) return;

                                const punto = [Number(u.lat), Number(u.lng)];

                                if (this.vistaMapa) {
                                    // Mismo contenedor, otro domicilio: se mueve en vez de
                                    // recrearse, así no vuelve a pedir los tiles.
                                    this.vistaMapa.setView(punto, 16);
                                    this.vistaPin.setLatLng(punto);
                                    this.$nextTick(() => this.vistaMapa.invalidateSize());

                                    return;
                                }

                                this.vistaMapa = window.L.map(caja, {
                                    zoomControl: false,
                                    dragging: false,
                                    scrollWheelZoom: false,
                                    doubleClickZoom: false,
                                    touchZoom: false,
                                    boxZoom: false,
                                    keyboard: false,
                                }).setView(punto, 16);

                                window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    attribution: '© OpenStreetMap',
                                    maxZoom: 19,
                                }).addTo(this.vistaMapa);

                                this.vistaPin = window.L.marker(punto).addTo(this.vistaMapa);

                                this.$nextTick(() => this.vistaMapa.invalidateSize());
                            })
                            .catch(() => { /* la vista es un extra: si no carga, el link sigue estando */ });
                    },

                    limpiarVistaDomicilio() {
                        if (this.vistaPin) {
                            this.vistaPin.remove();
                            this.vistaPin = null;
                        }

                        if (this.vistaMapa) {
                            this.vistaMapa.remove();
                            this.vistaMapa = null;
                        }
                    },

                    /** Abre el mapa para marcar o corregir el domicilio del cliente. */
                    abrirMapaCliente(persona = null) {
                        const cliente = persona ?? this.cliente;

                        if (! cliente) return;

                        this.mapaCliente = cliente;
                        this.mapaError = null;

                        // El pin arranca donde ya está guardado, si está: corregir 20 metros
                        // no debería obligar a buscar la casa desde el centro de la provincia.
                        const actual = cliente.ubicacion;
                        this.nuevo.lat = actual ? Number(actual.lat) : null;
                        this.nuevo.lng = actual ? Number(actual.lng) : null;

                        this.$nextTick(() => this.montarMapa('mapaEditar', actual
                            ? { lat: Number(actual.lat), lng: Number(actual.lng) }
                            : null));
                    },

                    cerrarMapaCliente() {
                        this.mapaCliente = null;
                        this.nuevo.lat = null;
                        this.nuevo.lng = null;
                        // Desmontar siempre: el mapa es el mismo objeto, y si queda vivo el
                        // pin de este cliente reaparece sobre el domicilio del siguiente.
                        this.limpiarMapa();
                    },

                    /**
                     * Pone el pin donde está centrado el mapa.
                     *
                     * Es el único camino con teclado: tocar el mapa y arrastrar el marcador
                     * son gestos de puntero, y Leaflet con las flechas mueve el mapa pero no
                     * el pin. Sin esto, marcar un domicilio era imposible sin mouse.
                     */
                    pinAlCentro() {
                        if (! this.mapa) return;

                        const c = this.mapa.getCenter();

                        // Sin recentrar: el mapa ya está donde el usuario lo dejó, y moverlo
                        // debajo del dedo justo al confirmar es desorientador.
                        this.ponerPin(c.lat, c.lng, false);
                    },

                    /** Lleva el GPS al pin del mapa, sin guardar todavía. */
                    gpsAlMapa() {
                        if (! navigator.geolocation) {
                            this.mapaError = 'Este teléfono no permite tomar la ubicación.';

                            return;
                        }

                        this.ubicando = true;
                        this.mapaError = null;

                        navigator.geolocation.getCurrentPosition(
                            pos => {
                                this.ubicando = false;
                                /*
                                 * Va al pin y no directo al servidor, aunque sea un GPS: así se
                                 * VE dónde cayó antes de guardarlo, y si el GPS erró se corrige
                                 * con el dedo en vez de quedar un domicilio malo guardado.
                                 */
                                this.ponerPin(pos.coords.latitude, pos.coords.longitude);
                            },
                            err => {
                                this.ubicando = false;
                                this.mapaError = err.code === err.PERMISSION_DENIED
                                    ? 'Tenés que permitir el acceso a la ubicación en el navegador.'
                                    : 'No se pudo tomar la ubicación. Probá a cielo abierto o tocá el mapa.';
                            },
                            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
                        );
                    },

                    guardarMapaCliente() {
                        if (! this.mapaCliente || ! this.nuevo.lat) return;

                        const id = this.mapaCliente.id;
                        this.cargando = true;

                        this.$axios.post(
                            '{{ url(config('app.admin_path').'/distribuidor/cliente') }}/' + id + '/ubicacion',
                            {
                                lat: this.nuevo.lat,
                                lng: this.nuevo.lng,
                                /*
                                 * origen 'mapa' aunque el punto haya salido del GPS: lo que
                                 * importa es que una persona lo VIO en el mapa y lo confirmó,
                                 * así que no se le mide precisión. El chequeo de precisión
                                 * existe para atajar un GPS que nadie miró.
                                 */
                                origen: 'mapa',
                            },
                        )
                            .then(r => {
                                if (this.cliente && this.cliente.id === id) {
                                    this.cliente.ubicacion = r.data.ubicacion;
                                }

                                this.aviso = 'Domicilio guardado.';
                                this.cerrarMapaCliente();
                            })
                            .catch(e => { this.mapaError = this.mensaje(e, 'No se pudo guardar el domicilio.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    /** El GPS del celular, para cuando se carga al cliente en su puerta. */
                    ubicacionDelGps() {
                        if (! navigator.geolocation) {
                            this.mapaError = 'Este teléfono no permite tomar la ubicación.';

                            return;
                        }

                        this.ubicando = true;
                        this.mapaError = null;

                        navigator.geolocation.getCurrentPosition(
                            pos => {
                                this.ubicando = false;
                                // Va al mapa y no directo al servidor: acá el contacto todavía
                                // no existe, así que el punto viaja junto con el alta.
                                this.ponerPin(pos.coords.latitude, pos.coords.longitude);
                            },
                            err => {
                                this.ubicando = false;
                                this.mapaError = err.code === err.PERMISSION_DENIED
                                    ? 'Tenés que permitir el acceso a la ubicación en el navegador.'
                                    : 'No se pudo tomar la ubicación. Probá a cielo abierto o tocá el mapa.';
                            },
                            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
                        );
                    },

                    /*
                     * Desmonta el mapa al cerrar el formulario.
                     *
                     * Sin esto, el pin del cliente anterior reaparece sobre el domicilio del
                     * siguiente: el mapa es el mismo objeto y Leaflet no sabe que cambió el
                     * cliente. Un domicilio heredado del contacto de antes es el peor de los
                     * errores posibles acá, porque nadie lo cuestiona.
                     */
                    limpiarMapa() {
                        if (this.pin) {
                            this.pin.remove();
                            this.pin = null;
                        }

                        if (this.mapa) {
                            this.mapa.remove();
                            this.mapa = null;
                        }

                        this.mapaError = null;
                    },

                    /** Un entero no negativo a partir de lo que haya en la caja. */
                    enteroDe(valor) {
                        const n = parseInt(valor, 10);

                        return Number.isFinite(n) && n > 0 ? n : 0;
                    },

                    /**
                     * Marca el domicilio del cliente con el GPS del celular.
                     *
                     * enableHighAccuracy pide el GPS real y no la ubicación por wifi o
                     * por antena, que en Jujuy puede errar por kilómetros. El timeout
                     * es largo a propósito: engancharlo a cielo abierto tarda, y un
                     * timeout corto devuelve justamente la posición imprecisa que se
                     * quiere evitar.
                     */
                    marcarUbicacion(personId = null) {
                        /*
                         * El id puede venir del diálogo de entrega o de la ficha del
                         * cliente, y se pasa explícito para no depender de cuál de las dos
                         * pantallas esté abierta.
                         *
                         * Se valida que sea un número en vez de confiar en el argumento:
                         * un handler declarado en @click SIN paréntesis recibe el
                         * MouseEvent, que no es null y por lo tanto sobrevive al `??`. La
                         * URL terminaba siendo /cliente/[object MouseEvent]/ubicacion y el
                         * síntoma era "no me permite registrar ubicación", sin ninguna
                         * pista de que el problema fuera el llamado y no el GPS.
                         */
                        const candidato = Number(personId);
                        const id = Number.isInteger(candidato) && candidato > 0
                            ? candidato
                            : Number(this.cliente?.id) || null;

                        if (! id) return;

                        if (! navigator.geolocation) {
                            this.aviso = 'Este teléfono no permite tomar la ubicación.';

                            return;
                        }

                        this.ubicando = true;

                        navigator.geolocation.getCurrentPosition(
                            pos => {
                                this.$axios.post(
                                    '{{ url(config('app.admin_path').'/distribuidor/cliente') }}/' + id + '/ubicacion',
                                    {
                                        lat: pos.coords.latitude,
                                        lng: pos.coords.longitude,
                                        // El error en metros que informa el navegador: es lo
                                        // que distingue la puerta del cliente de la antena de
                                        // telefonía, y el servidor lo usa para decidir.
                                        precision: pos.coords.accuracy ?? null,
                                    },
                                )
                                    .then(r => {
                                        /*
                                         * Las dos pantallas guardan formas distintas: la ficha
                                         * del cliente trae la ubicación del CLIENTE y la tarjeta
                                         * del pedido la del PEDIDO, con referencia y modo. Se
                                         * actualiza cada una con lo suyo en vez de cruzarlas, que
                                         * dejaría la tarjeta sin los campos que usa para dibujar.
                                         */
                                        if (this.cliente && this.cliente.id === id) {
                                            this.cliente.ubicacion = r.data.ubicacion;
                                        }

                                        if (this.entrega.pedido && this.entrega.pedido.person_id === id) {
                                            this.entrega.ubicacionMarcada = true;
                                            // Se guarda lo devuelto y no sólo un booleano: marcar
                                            // sin poder ver dónde quedó no permite detectar un GPS
                                            // que cayó a dos cuadras.
                                            this.entrega.ubicacion = r.data.ubicacion;
                                        }

                                        this.aviso = 'Domicilio marcado.';
                                    })
                                    .catch(e => { this.aviso = this.mensaje(e, 'No se pudo guardar el domicilio.'); })
                                    .finally(() => { this.ubicando = false; });
                            },
                            err => {
                                this.ubicando = false;
                                // Se distingue el permiso denegado del resto: son dos
                                // problemas distintos y se arreglan de forma distinta.
                                this.aviso = err.code === err.PERMISSION_DENIED
                                    ? 'Tenés que permitir el acceso a la ubicación en el navegador.'
                                    : 'No se pudo tomar la ubicación. Probá a cielo abierto.';
                            },
                            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
                        );
                    },

                    cancelarEntrega() {
                        if (this.entrega.preview) URL.revokeObjectURL(this.entrega.preview);
                        this.entrega = { pedido: null, archivo: null, preview: null, dejo: '', retiro: '', ubicacionMarcada: false, ubicacion: null };
                    },

                    confirmarEntrega() {
                        // Sin exigir foto: es opcional. El guard que había acá era el
                        // segundo cerrojo —además del :disabled— y dejaba el botón
                        // habilitado pero sin efecto, que es peor que no dejar tocarlo.
                        const pedido = this.entrega.pedido;

                        // multipart: la foto es un archivo, no un string base64 — que
                        // infla el payload un 33% y revienta el límite de POST.
                        const form = new FormData();
                        form.append('etapa', 'entregado');
                        // Sólo si hay foto: un append con null manda el string "null",
                        // que la validación `image` rechaza y convierte una entrega
                        // sin foto —ahora válida— en un error.
                        if (this.entrega.archivo) {
                            form.append('foto', this.entrega.archivo);
                        }

                        // Los campos vacíos se mandan como 0: el servidor no guarda
                        // movimiento cuando los dos son 0, así que una entrega sin
                        // bidones no ensucia el libro.
                        form.append('bidones_entregados', this.enteroDe(this.entrega.dejo));
                        form.append('bidones_retirados', this.enteroDe(this.entrega.retiro));
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
                        this.cobro = { pedido, method: 'efectivo' };
                    },

                    registrarCobro() {
                        const pedido = this.cobro.pedido;

                        this.cargando = true;

                        this.$axios.post('{{ url(config('app.admin_path').'/distribuidor/pedido') }}/' + pedido.id + '/cobro', {
                            method: this.cobro.method,
                        })
                            .then(r => {
                                this.reemplazar(r.data.pedido);
                                this.cobro.pedido = null;
                                if (this.vista === 'caja') this.cargarCaja();
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

                    /**
                     * El motivo real, que es lo que el asesor puede corregir.
                     *
                     * Un 413 lo contesta nginx con HTML y sin `message`, así que sin este
                     * caso especial el error quedaba en un genérico que no decía nada —
                     * exactamente el síntoma que tuvimos con las fotos de entrega.
                     */
                    mensaje(e, porDefecto) {
                        const status = e?.response?.status;

                        if (status === 413) {
                            return 'El archivo es demasiado grande para el servidor. Sacá la foto de nuevo con menos resolución.';
                        }

                        if (status === 419) {
                            return 'La sesión expiró. Recargá la página e intentá otra vez.';
                        }

                        if (! e?.response) {
                            return 'Sin conexión con el servidor. Revisá la señal e intentá otra vez.';
                        }

                        return e.response.data?.message || porDefecto;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
