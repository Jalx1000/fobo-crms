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
        clientes-url="{{ route('admin.distribuidor.clientes') }}"
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
                {{--
                    Tres y no cuatro: Caja y Bidones se fueron.

                    Caja era un resumen que se mira UNA vez, al cerrar el día, y ocupaba
                    un cuarto de la barra de navegación todo el día; el número sigue en el
                    admin. Bidones listaba sólo a los que debían envases, que es un recorte
                    de la agenda: ahora son una columna de Clientes.

                    Tres caben en una fila incluso a 375px, así que no hace falta la
                    segunda fila que necesitaban cuatro. `text-base sm:text-lg` porque a
                    ese ancho la celda queda en ~105px y "Crear pedido" en text-lg se
                    parte por el medio.
                --}}
                <div class="mb-4 grid grid-cols-3 gap-2">
                    <button type="button" @click="vista = 'nuevo'"
                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 text-base font-bold transition-colors duration-200 sm:text-lg"
                            :class="vista === 'nuevo'
                                ? 'border-brandColor bg-brandColor/10 text-brandColor'
                                : 'border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-400'">
                        Crear pedido
                    </button>
                    <button type="button" @click="irAPedidos"
                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 text-base font-bold transition-colors duration-200 sm:text-lg"
                            :class="vista === 'pedidos'
                                ? 'border-brandColor bg-brandColor/10 text-brandColor'
                                : 'border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-400'">
                        Pedidos
                        <span v-if="pedidos.length" class="ml-1 rounded-full bg-brandColor px-2 py-0.5 text-sm text-white">
                            @{{ pedidos.length }}
                        </span>
                    </button>
                    <button type="button" @click="irAClientes"
                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 text-base font-bold transition-colors duration-200 sm:text-lg"
                            :class="vista === 'clientes'
                                ? 'border-brandColor bg-brandColor/10 text-brandColor'
                                : 'border-gray-200 text-gray-600 dark:border-gray-800 dark:text-gray-400'">
                        Clientes
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
                            <div v-if="!cliente" class="flex flex-col gap-2 sm:flex-row mb-3">
                                {{--
                                    Teléfono o nombre en el mismo campo: el que está en la
                                    puerta escribe lo que se acuerda. Con letras busca por
                                    nombre entre SUS clientes; con números, por teléfono.
                                    Sin inputmode="tel" porque ese teclado no tiene letras.
                                --}}
                                <div class="flex-1">
                                    <label for="punto-telefono" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Teléfono o nombre del cliente
                                    </label>
                                    <input id="punto-telefono" v-model="telefono" type="search" autocomplete="off"
                                           placeholder="Ej. 3884123456 o María"
                                           @keyup.enter="buscarCliente"
                                           class="min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-xl focus:border-brandColor focus:outline-none focus:ring-2 focus:ring-brandColor dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                </div>
                                <button type="button" @click="buscarCliente" :disabled="cargando" aria-label="Buscar el contacto por teléfono o nombre"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-brandColor px-8 text-lg font-bold text-white disabled:opacity-50 sm:min-w-[160px]">
                                    @{{ cargando ? '…' : 'Buscar' }}
                                </button>
                            </div>

                            {{-- Los clientes que coinciden con el nombre buscado --}}
                            <div v-if="!cliente && coincidencias !== null" class="mb-3">
                                <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    @{{ coincidencias.length ? 'Coinciden con "' + telefono.trim() + '"' : 'Ningún cliente tuyo se llama así. Para cargar uno nuevo, buscalo por teléfono.' }}
                                </span>

                                <div class="grid grid-cols-2 gap-2">
                                    <button v-for="c in coincidencias" :key="c.person_id" type="button"
                                            @click="elegirCoincidencia(c)"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-200 px-3 py-2 text-left transition-colors duration-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800">
                                        <span class="block truncate text-base font-semibold text-gray-800 dark:text-gray-200">@{{ c.nombre }}</span>
                                        <span class="block truncate text-sm text-gray-600 dark:text-gray-400">@{{ c.telefono || 'Sin teléfono' }}</span>
                                    </button>
                                </div>
                            </div>

                            <div v-if="!cliente && recientes.length" class="">
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
                                {{--
                                    La dirección y la REFERENCIA, debajo del mapa.

                                    La dirección la precarga el geocodificador al mover el pin y
                                    queda EDITABLE: devuelve calle y barrio pero muchas veces sin
                                    altura, y una altura inventada que se guarda a ciegas no la
                                    cuestiona nadie nunca.

                                    La referencia no se deduce de ninguna coordenada. "Portón verde
                                    y hay un árbol afuera" es lo que de verdad usa quien toca el
                                    timbre, y vale más que un pin a veinte metros.
                                --}}
                                <label for="punto-direccion" class="mb-1 mt-2 block text-sm font-medium text-amber-900 dark:text-amber-200">
                                    Dirección <span class="font-normal">(se completa con el pin)</span>
                                </label>
                                <input id="punto-direccion" v-model="nuevo.direccion" type="text"
                                       placeholder="Calle y altura"
                                       class="mb-2 min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-4 text-base focus:border-brandColor focus:outline-none focus:ring-2 focus:ring-brandColor dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">

                                <label for="punto-referencia" class="mb-1 block text-sm font-medium text-amber-900 dark:text-amber-200">
                                    Referencia <span class="font-normal">(cómo encontrar la casa)</span>
                                </label>
                                <textarea id="punto-referencia" v-model="nuevo.referencia" rows="2"
                                          placeholder="Portón verde, hay un árbol afuera, timbre del fondo…"
                                          class="mb-2 w-full rounded-xl border-2 border-gray-300 px-4 py-3 text-base focus:border-brandColor focus:outline-none focus:ring-2 focus:ring-brandColor dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>


                                <button type="button" @click="crearCliente" :disabled="cargando || !nombre.trim()"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] w-full rounded-xl bg-emerald-700 text-lg font-bold text-white disabled:opacity-50">
                                    Crear contacto
                                </button>
                            </div>

                            {{-- Elegido --}}
                            {{--
                                La ficha del cliente elegido.
                            
                                En COLUMNA y a ancho completo, no en fila: antes era `flex flex-col items-center`
                                con hijos pensados para una fila —min-w-0, truncate, shrink-0— y esa mezcla rompía
                                las dos cosas. Centrado en columna, el bloque de datos se encoge al ancho del texto,
                                así que `truncate` no recorta nada y la miniatura del mapa queda del ancho del
                                nombre en lugar de la tarjeta.
                            
                                El botón de icono suelto del domicilio se quitó: la miniatura del mapa YA abre el
                                mismo editor, así que eran dos caminos a lo mismo, y uno de ellos un icono sin
                                texto apretado entre dos botones. Cuando no hay domicilio, un botón de ancho
                                completo CON texto dice qué hace.
                            --}}
                            <div v-if="cliente" class="flex flex-col gap-3 rounded-xl bg-emerald-50 p-4 dark:bg-emerald-900/20">
                                <div class="w-full min-w-0">
                                    <div class="truncate text-xl font-bold text-gray-800 dark:text-gray-100">@{{ cliente.name }}</div>
                                    <div class="text-base text-gray-600 dark:text-gray-400">@{{ cliente.telefono }}</div>

                                    {{--
                                        Los bidones que tiene en su casa, acá y no en otra pantalla: es el momento
                                        en que el distribuidor decide cuántos bajar de la camioneta. Sólo si tiene:
                                        un "0 bidones" en cada cliente es ruido.
                                    --}}
                                    <span v-if="cliente.bidones" class="mt-1 inline-flex items-center rounded-full bg-sky-100 px-2.5 py-0.5 text-base font-bold text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                        @{{ cliente.bidones }} bidón@{{ cliente.bidones === 1 ? '' : 'es' }} sin devolver
                                    </span>
                                </div>

                                {{--
                                    El domicilio marcado SE VE, no se anuncia: un pin a dos cuadras se ve igual que
                                    uno correcto cuando sólo hay una etiqueta. Tocar el mapa abre el editor.
                                --}}
                                <div v-if="cliente.ubicacion" class="w-full">
                                    <!--
                                        El mapa NO va adentro del boton. Leaflet le cuelga al
                                        contenedor el link de la atribucion, y un boton no admite
                                        contenido interactivo adentro: ademas de HTML invalido, el
                                        tap se lo quedaba el mapa y no llegaba al boton.

                                        Asi que el mapa queda decorativo (aria-hidden: lo que hay que
                                        anunciar es la accion, no los tiles) y el area tactil es un
                                        boton real encima.

                                        🔴 El `isolate` del contenedor es lo que hace que esto no
                                        tape los modales. Leaflet reparte z-index de 400 a 1000
                                        entre sus panes y controles, y `.leaflet-container` es
                                        `position: relative; z-index: auto`, asi que NO crea
                                        stacking context: esos 1000 competian en el contexto raiz y
                                        le ganaban a cualquier modal. `isolation: isolate` los
                                        encierra acá, y el contenedor participa afuera como una sola
                                        capa con z-index auto.

                                        Bajarle el z-index al boton no alcanza: el problema no es el
                                        boton, son los tiles del mapa, que con 400 ya tapaban el
                                        modal igual.
                                    -->
                                    <div class="relative isolate overflow-hidden rounded-xl border-2 border-emerald-300 dark:border-emerald-800">
                                        <div ref="mapaVista" class="h-32 w-full bg-gray-100 dark:bg-gray-800" aria-hidden="true"></div>

                                        <button type="button" @click="abrirMapaCliente()"
                                                aria-label="Corregir el domicilio en el mapa"
                                                class="group absolute inset-0 z-[1100] flex cursor-pointer items-start justify-end p-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor">
                                            <span class="rounded-lg bg-white/90 px-2 py-1 text-sm font-bold text-gray-800 shadow-sm transition-colors duration-200 group-hover:bg-white motion-reduce:transition-none dark:bg-gray-900/90 dark:text-gray-100 dark:group-hover:bg-gray-900">
                                                Corregir
                                            </span>
                                        </button>
                                    </div>

                                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                                        <a :href="cliente.ubicacion.url" target="_blank" rel="noopener"
                                           class="cursor-pointer rounded focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 inline-flex items-center gap-1 text-base font-semibold text-brandColor hover:underline">
                                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z"/></svg>
                                            Abrir en el mapa
                                        </a>

                                    </div>
                                </div>


                                {{--
                                    Dos acciones, dos columnas iguales. Repetir existe porque en este rubro el mismo
                                    cliente pide lo mismo cada semana: convierte un pedido de seis toques en dos.
                                --}}
                                {{--
                                        Las tres acciones del cliente en UNA fila, con el domicilio
                                        en el medio.

                                        Sin iconos y con px-2: a 375px la tarjeta deja unos 98px por
                                        celda y un icono más texto no entra en una línea. Repetir y
                                        Cambiar tampoco llevan icono, así que las tres se leen igual.

                                        El botón del domicilio sirve para marcarlo y para corregirlo.
                                        Antes había TRES caminos al mismo editor —la miniatura, un
                                        link "Corregir" y un botón suelto de ancho completo— y los dos
                                        últimos se quitaron. La miniatura sigue abriéndolo porque es
                                        lo natural: tocás lo que querés cambiar.
                                    --}}
                                    <div class="grid w-full grid-cols-3 gap-2">
                                    <button type="button" @click="repetirPedido" :disabled="cargando"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-brandColor px-2 text-base font-bold text-brandColor transition-colors duration-200 disabled:opacity-40">
                                        Repetir
                                    </button>

                                    <button type="button" @click="abrirMapaCliente()"
                                                :aria-label="cliente.ubicacion ? 'Editar el domicilio en el mapa' : 'Marcar el domicilio en el mapa'"
                                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 px-2 text-base font-bold transition-colors duration-200"
                                                :class="cliente.ubicacion
                                                    ? 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'
                                                    : 'border-brandColor text-brandColor'">
                                            Domicilio
                                        </button>

                                        <button type="button" @click="limpiarCliente"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-gray-300 px-2 text-base font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-300">
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

                        {{--
                            Acá estaba el paso "3 · Dirección y referencia", un textarea libre
                            que escribía en el `description` del pedido.

                            Se quita porque duplicaba —peor— lo que ahora son campos propios del
                            CLIENTE: dirección, referencia y coordenadas, cada uno por separado y
                            reutilizables en todos sus pedidos. Un solo cuadro de texto mezclando
                            las tres cosas obliga a parsearlo para mostrarlo, y lo que se parsea
                            se parsea mal: el `description` terminaba apareciendo entero como
                            "referencia" en la tarjeta del pedido.
                        --}}
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
                            Pedidos pendientes
                        </button>
                        <button type="button" @click="cambiarFiltro('historial')"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 text-base font-bold transition-colors duration-200"
                                :class="filtro === 'historial'
                                    ? 'border-brandColor bg-brandColor text-white'
                                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'">
                            Historial de pedidos
                        </button>
                    </div>

                    {{--
                        El rango de fechas del historial.

                        Sólo en el historial: en los pendientes la fecha no ordena nada
                        —un pedido de antes de ayer que sigue sin entregar es más urgente,
                        no menos— y un filtro de fecha ahí invita a esconder trabajo viejo.

                        Los dos extremos son opcionales por separado: "desde el 1" sin tope
                        y "hasta el 5" sin piso son las dos formas en que se usa de verdad.
                        Vacíos los dos = los últimos 100.

                        El botón "Todos" existe porque un <input type="date"> no se puede
                        vaciar con el teclado en todos los navegadores de celular.
                    --}}
                    <div v-if="filtro === 'historial'" class="mb-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="punto-desde" class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Desde
                                </label>
                                <input id="punto-desde" v-model="rangoHistorial.desde" type="date" @change="cargarPedidos"
                                       :max="rangoHistorial.hasta || null"
                                       class="min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-3 text-base dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div>
                                <label for="punto-hasta" class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Hasta
                                </label>
                                {{--
                                    `min` atado al otro extremo: el navegador no deja elegir un
                                    "hasta" anterior al "desde", así que el rango imposible no
                                    llega nunca al servidor. El 422 queda igual, para quien
                                    llame al endpoint sin pasar por esta pantalla.
                                --}}
                                <input id="punto-hasta" v-model="rangoHistorial.hasta" type="date" @change="cargarPedidos"
                                       :min="rangoHistorial.desde || null"
                                       class="min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-3 text-base dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                        </div>

                        <button v-if="rangoHistorial.desde || rangoHistorial.hasta" type="button" @click="limpiarRango"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mt-2 min-h-[56px] w-full rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 dark:border-gray-700 dark:text-gray-300">
                            Ver todos
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
                            ? 'No hay pedidos confirmados para entregar acá. Los que acabás de cargar están en "Historial de pedidos", para confirmarlos.'
                            : ((rangoHistorial.desde || rangoHistorial.hasta)
                                ? 'No hay pedidos de esas fechas en esta sucursal.'
                                : 'Todavía no hay pedidos en esta sucursal.') }}
                    </div>

                    {{--
                        Separado por día en el historial, plano en los pendientes.

                        Los pendientes NO se agrupan a propósito: ahí son una cola de
                        trabajo, y partirla en días sugiere que los de ayer son de otra
                        tanda cuando en realidad son los más urgentes.
                    --}}
                    <template v-for="grupo in pedidosAgrupados" :key="grupo.dia || 'todos'">
                        <div v-if="grupo.dia"
                             class="mb-2 mt-4 flex items-baseline justify-between gap-2 border-b-2 border-gray-200 pb-1 first:mt-0 dark:border-gray-800">
                            <span class="text-lg font-bold text-gray-800 dark:text-gray-100">@{{ grupo.etiqueta }}</span>
                            <span class="shrink-0 text-base text-gray-600 dark:text-gray-400">
                                @{{ grupo.pedidos.length }} pedido@{{ grupo.pedidos.length === 1 ? '' : 's' }}
                            </span>
                        </div>

                    <div v-for="pe in grupo.pedidos" :key="pe.id" class="mb-3 rounded-2xl border-2 border-gray-200 p-4 dark:border-gray-800">
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
                                <div class="text-xs uppercase text-gray-600 dark:text-gray-400">@{{ pe.saldo < 0 ? 'A devolver' : 'Saldo' }}</div>
                                {{--
                                    Tres estados y no dos. Un saldo NEGATIVO significa que se cobró más de lo que
                                    el pedido vale ahora —pasa al editar a la baja un pedido ya cobrado— y hay que
                                    devolverle plata al cliente. Antes el negativo caía en el verde del 'todo en
                                    orden', que es exactamente lo contrario de lo que pasa.
                                --}}
                                <div class="text-2xl font-extrabold" :class="pe.saldo > 0 ? 'text-red-600' : (pe.saldo < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600')">
                                    @{{ money(Math.abs(pe.saldo)) }}
                                </div>
                            </div>
                        </div>

                        {{--
                            Dónde entregar.

                            La referencia escrita va PRIMERO y el mapa después, no al revés:
                            "portón verde y hay un árbol afuera" es lo que de verdad usa
                            quien toca el timbre, y un pin puede estar a 20 metros.
                        --}}
                        <div v-if="pe.ubicacion && (pe.ubicacion.direccion || pe.ubicacion.referencia || pe.ubicacion.url)"
                             class="mb-3 rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-800/50">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                Dónde entregar
                            </span>

                            {{-- La dirección escrita, arriba de la referencia: primero dónde es, después cómo encontrarla. --}}
                            <p v-if="pe.ubicacion.direccion" class="mb-1 break-words text-base font-semibold text-gray-800 dark:text-gray-200">
                                @{{ pe.ubicacion.direccion }}
                            </p>

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
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-2 min-h-[64px] w-full rounded-xl bg-emerald-700 text-xl font-bold text-white transition-colors duration-200">
                            <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.9 47.9 0 0 0-1.134-.175a2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0a2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg>Entregar
                        </button>

                        
                        <p v-else-if="pe.etapa_code === 'pedido-entregado'" class="mb-2 rounded-xl bg-emerald-50 py-3 text-center text-base font-bold text-emerald-800 dark:bg-emerald-900/25 dark:text-emerald-300">
                            Entregado y cobrado
                        </p>

                        {{--
                            Editar los productos. Va en las salidas laterales y no en la cadena
                            de pasos: no es algo que haya que hacer en cada pedido, es una
                            corrección. Y sigue disponible después de cobrado y entregado, que es
                            lo que pidió el negocio —el caso real es el pedido que armó el agente
                            y el cliente corrige en la puerta—.

                            Cancelado no: ahí no hay nada que entregar, y el servidor lo rechaza
                            igual con un 422.
                        --}}
                        <button v-if="pe.etapa_code !== 'pedidos-cancelado'" type="button" @click="abrirEdicion(pe)"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-2 min-h-[56px] w-full rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                            <svg class="mr-1 inline h-6 w-6 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                            Editar productos
                        </button>

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
                </template>

                {{-- ══════════════ CLIENTES ══════════════ --}}
                {{--
                    La agenda del distribuidor, que antes era el apartado "Bidones".

                    Bidones listaba SÓLO a los que debían envases, y eso dejaba afuera el
                    caso más común de la calle: acordarse del cliente y no del teléfono.
                    Para llegar a su ficha había que pasar por un pedido. Ahora la lista es
                    la agenda entera, con buscador, y el saldo de envases es una columna —
                    los que deben siguen primeros, porque el servidor ordena por saldo.
                --}}
                <template v-if="vista === 'clientes'">
                    {{--
                        Sin sucursal no hay agenda que mostrar: la lista es la de UNA ciudad.
                        El cartel va antes del buscador y no después, porque un buscador que
                        no devuelve nada parece roto en vez de parecer vacío.
                    --}}
                    <div v-if="! sucursal" class="rounded-xl border-2 border-dashed border-gray-300 px-4 py-10 text-center text-base text-gray-600 dark:text-gray-400 dark:border-gray-700">
                        Elegí una sucursal para ver sus clientes.
                    </div>

                    <template v-else>
                    {{--
                        El total de envases afuera arriba y no al final: es el número con
                        el que se arma el recorrido, y abajo de una lista paginada nadie lo
                        ve. No se calcula sumando la página —la página son 30 de una lista
                        más larga— sino sobre la zona entera.
                    --}}
                    <div class="mb-4 flex items-center justify-between gap-3 rounded-2xl border-2 border-sky-300 px-5 py-4 dark:border-sky-800">
                        <div>
                            <span class="block text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                Envases en la calle
                            </span>
                            <span class="block text-sm text-gray-600 dark:text-gray-400">
                                Hoy dejaste @{{ clientes.del_dia.entregados }} y retiraste @{{ clientes.del_dia.retirados }}
                            </span>
                        </div>
                        <span class="shrink-0 text-4xl font-extrabold text-gray-900 dark:text-white">
                            @{{ clientes.en_la_calle }}
                        </span>
                    </div>

                    {{--
                        El buscador busca por nombre O por teléfono con el mismo campo: el
                        que está parado en la puerta no quiere elegir antes de escribir.

                        `type="search"` y no "text": el teclado del celular muestra la tecla
                        de buscar y el navegador agrega la cruz para vaciarlo.
                    --}}
                    <div class="mb-3 flex gap-2">
                        <label for="punto-buscar-cliente" class="sr-only">Buscar un cliente por nombre o teléfono</label>
                        <input id="punto-buscar-cliente" v-model="busquedaCliente" type="search"
                               @keyup.enter="buscarClientes" placeholder="Nombre o teléfono"
                               class="min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-4 text-lg dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <button type="button" @click="buscarClientes" :disabled="cargando"
                                class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] shrink-0 rounded-xl bg-brandColor px-5 text-lg font-bold text-white transition-colors duration-200 disabled:opacity-40">
                            Buscar
                        </button>
                    </div>

                    <div v-if="cargando && ! clientes.clientes.length" class="py-8 text-center text-base text-gray-600 dark:text-gray-400">
                        Cargando…
                    </div>

                    <div v-else-if="! clientes.clientes.length" class="rounded-xl border-2 border-dashed border-gray-300 py-10 text-center dark:border-gray-700">
                        <p class="text-lg font-semibold text-gray-700 dark:text-gray-300">
                            @{{ busquedaCliente.trim() ? 'Ningún cliente coincide' : 'Todavía no hay clientes en tu zona' }}
                        </p>
                        <p class="mt-1 text-base text-gray-600 dark:text-gray-400">
                            @{{ busquedaCliente.trim()
                                ? 'Probá con parte del nombre, o con los últimos números del teléfono.'
                                : 'Un contacto entra en la agenda al cargarle el primer pedido.' }}
                        </p>
                    </div>

                    <div v-for="c in clientes.clientes" :key="c.person_id"
                         class="mb-3 rounded-2xl border-2 border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-xl font-bold text-gray-800 dark:text-gray-100">@{{ c.nombre }}</div>
                                <div class="text-base text-gray-600 dark:text-gray-400">@{{ c.telefono || 'Sin teléfono' }}</div>

                                {{--
                                    Cuándo fue la última entrega.

                                    Sale de `distribuidor_entregas.entregado_at` y no del
                                    lead: la etapa dice QUE se entregó, pero `updated_at`
                                    se mueve con cualquier edición posterior y mostraría
                                    la fecha de la última corrección disfrazada de entrega.

                                    Sin fecha NO quiere decir "nunca compró": puede haber
                                    comprado antes de que existiera este registro. Por eso
                                    dice "sin entregas registradas" y no "nunca".
                                --}}
                                <div class="mt-1 text-base" :class="c.ultima_entrega
                                    ? 'text-gray-600 dark:text-gray-400'
                                    : 'text-gray-500 dark:text-gray-500'">
                                    @{{ c.ultima_entrega
                                        ? 'Última entrega: ' + fechaCorta(c.ultima_entrega)
                                        : 'Sin entregas registradas' }}
                                </div>
                            </div>

                            {{--
                                El saldo sólo cuando hay algo afuera: un "0" en todas las filas
                                es ruido que esconde a las que importan.

                                Con unidad y no un número pelado: "3" al lado de un nombre no
                                dice si son envases, pedidos o kilómetros.
                            --}}
                            <span v-if="c.saldo > 0"
                                  class="shrink-0 rounded-full bg-amber-100 px-3 py-1 text-base font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                @{{ c.saldo }} envase@{{ c.saldo === 1 ? '' : 's' }}
                            </span>
                        </div>

                        {{--
                            Las acciones abajo y en ancho completo, no apretadas contra el borde
                            derecho: "Recibí envases" en una columna de 100px se leía como una
                            etiqueta y no como un botón, y encima no decía qué iba a pasar.

                            "Devolvió envases" describe el hecho que se registra, desde el lado
                            del cliente, que es como lo cuenta el distribuidor: "me devolvió
                            tres". "Recibí" obligaba a resolver quién era el sujeto.
                        --}}
                        <div class="mt-3 grid gap-2" :class="c.saldo > 0 ? 'grid-cols-2' : 'grid-cols-1'">
                            <button type="button" @click="abrirEdicionCliente(c)"
                                    :aria-label="'Editar los datos de ' + c.nombre"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                <svg class="mr-1 inline h-5 w-5 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                                Editar cliente
                            </button>

                            <button v-if="c.saldo > 0" type="button" @click="abrirDevolucion(c)"
                                    :aria-label="'Registrar que ' + c.nombre + ' devolvió envases'"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] rounded-xl border-2 border-brandColor text-base font-bold text-brandColor transition-colors duration-200">
                                Devolvió envases
                            </button>
                        </div>

                        {{--
                            El recordatorio de recompra de este cliente.

                            🔴 Son DOS estados distintos y la pantalla no los mezcla:

                              baja = el CLIENTE pidió no recibir más. No hay interruptor,
                                     sólo el cartel. Se levanta cuando él lo vuelve a pedir
                                     por WhatsApp, no con un toque de alguien del equipo.
                              activo = el interruptor del EQUIPO, ese sí se toca acá.

                            Dejar que un operador deshaga un "no me escribas más" con un
                            toque es exactamente lo que no debe poder pasar.
                        --}}
                        <div v-if="c.recordatorio" class="mt-3 flex items-center justify-between gap-3 border-t border-gray-200 pt-3 dark:border-gray-800">
                            <div class="min-w-0">
                                <span class="block text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                    Recordatorio de recompra
                                </span>
                                <span class="block text-base" :class="c.recordatorio.baja
                                    ? 'text-amber-700 dark:text-amber-400'
                                    : 'text-gray-600 dark:text-gray-400'">
                                    @{{ textoRecordatorio(c.recordatorio) }}
                                </span>
                            </div>

                            <button v-if="! c.recordatorio.baja" type="button" @click="cambiarRecordatorio(c)"
                                    :aria-label="(c.recordatorio.activo ? 'Apagar' : 'Encender') + ' el recordatorio de ' + c.nombre"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] shrink-0 rounded-xl border-2 px-4 text-base font-bold transition-colors duration-200"
                                    :class="c.recordatorio.activo
                                        ? 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'
                                        : 'border-brandColor text-brandColor'">
                                @{{ c.recordatorio.activo ? 'Apagar' : 'Encender' }}
                            </button>
                        </div>
                    </div>

                    {{-- "Ver más" y no paginador numerado: se usa con una mano, de pie. --}}
                    <button v-if="clientes.hay_mas" type="button" @click="masClientes" :disabled="cargando"
                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] w-full rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 transition-colors duration-200 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300">
                        @{{ cargando ? 'Cargando…' : 'Ver más clientes' }}
                    </button>
                    </template>
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
                            @{{ cargando ? 'Guardando…' : 'Crear pedido' }}
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

                {{--
                    ── Confirmar la entrega con la foto a la vista ──

                    z-[10002] y no z-50: es LA ESCALA DEL ADMIN, no un número suelto.
                    Los modales de Krayin usan 10002/10003/10004
                    (components/modal/index.blade.php), así que un z-50 quedaba
                    debajo de cualquier componente del admin — y debajo de los panes
                    de Leaflet, que llegan a 1000. Los cinco modales del Punto van a
                    la misma capa: son mutuamente excluyentes, nunca compiten entre sí.
                --}}
                <Teleport to="body">
                    <div v-if="entrega.pedido" class="fixed inset-0 z-[10002] flex items-end justify-center bg-black/60 sm:items-center" @click.self="cancelarEntrega">
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
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-700 text-lg font-bold text-white disabled:opacity-40">
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
                    <div v-if="mapaCliente" class="fixed inset-0 z-[10002] flex items-end justify-center bg-black/60 sm:items-center" @click.self="cerrarMapaCliente">
                        <div role="dialog" style="max-width:90vw !important" aria-modal="true" aria-labelledby="titulo-mapa"
                             class="flex max-h-[90vh] w-full max-w-lg flex-col rounded-t-2xl bg-white dark:bg-gray-900 sm:rounded-2xl">
                            {{--
                                El contenido con scroll y el pie fijo.

                                Medido: con el mapa en h-72 el modal sumaba 830px. En una pantalla de
                                667 se pasaba por 163px y NO había scroll, así que —por el items-end
                                del contenedor— lo que se cortaba era ARRIBA: el título y media parte
                                del mapa quedaban fuera, sin forma de llegar. Y Guardar quedaba
                                visible pero el usuario no podía ver qué estaba guardando.
                            --}}
                            <div class="min-h-0 flex-1 overflow-y-auto p-5 pb-3">
                            <h3 id="titulo-mapa" class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">
                                @{{ mapaCliente.ubicacion ? 'Editar domicilio' : 'Marcar domicilio' }}
                            </h3>
                            <p class="mb-3 text-base text-gray-600 dark:text-gray-400">@{{ mapaCliente.name }}</p>

                            <div ref="mapaEditar" class="mb-2 h-56 w-full sm:h-72 overflow-hidden rounded-xl border-2 border-gray-300 bg-gray-100 dark:border-gray-700 dark:bg-gray-800"
                                 role="application" :aria-label="(mapaCliente && mapaCliente.ubicacion ? 'Editar' : 'Marcar') + ' el domicilio del cliente en el mapa'"></div>

                            <p v-if="mapaError" class="mb-2 text-base text-red-700 dark:text-red-400">@{{ mapaError }}</p>
                            <p v-else class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                                Tocá el mapa o arrastrá el pin. Si estás en la puerta, usá el GPS.
                            </p>

                            <label for="editar-direccion" class="mb-1 block text-base font-semibold text-gray-700 dark:text-gray-300">
                                Dirección
                            </label>
                            <input id="editar-direccion" v-model="nuevo.direccion" type="text" placeholder="Calle y altura"
                                   class="mb-2 min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-4 text-base focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">

                            <label for="editar-referencia" class="mb-1 block text-base font-semibold text-gray-700 dark:text-gray-300">
                                Referencia
                            </label>
                            <textarea id="editar-referencia" v-model="nuevo.referencia" rows="2"
                                      placeholder="Portón verde, hay un árbol afuera…"
                                      class="mb-3 w-full rounded-xl border-2 border-gray-300 px-4 py-3 text-base focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"></textarea>

                            {{--
                                Poner el pin en el centro del mapa.

                                No es un atajo: es el ÚNICO camino con teclado. Tocar el mapa y
                                arrastrar el marcador son gestos de mouse o dedo, así que sin
                                esto un usuario de teclado no podía marcar ningún domicilio
                                —Leaflet mueve el mapa con las flechas, pero no el pin—.
                                De paso sirve con el dedo: mover el mapa y tocar un botón grande
                                es más preciso que acertarle a un punto exacto en la pantalla.
                            --}}
                            {{--
                                Los dos caminos al pin, en una fila: apilados sumaban 132px de los
                                830 totales. "Pin al centro" es el único camino con teclado y además
                                sirve con el dedo; "Estoy acá" sirve parado en la puerta.
                            --}}
                            <div class="mb-3 grid grid-cols-2 gap-2">
                            <button type="button" @click="pinAlCentro"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-2 flex min-h-[56px] w-full items-center justify-center rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                <svg class="mr-1 inline h-5 w-5 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                Pin al centro
                            </button>

                            <button type="button" @click="gpsAlMapa" :disabled="ubicando"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-3 flex min-h-[56px] w-full items-center justify-center rounded-xl border-2 border-brandColor text-base font-bold text-brandColor transition-colors duration-200 disabled:opacity-40">
                                <svg class="mr-1 inline h-5 w-5 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                @{{ ubicando ? 'Tomando…' : 'Estoy acá' }}
                            </button>
                            </div>

                        </div>

                        {{--
                            El pie queda FIJO, fuera del scroll. Con el contenido más alto que la pantalla,
                            Cancelar y Guardar tienen que estar siempre al alcance: si viajaran con el scroll,
                            el distribuidor tendría que arrastrar hasta abajo para confirmar algo que ya decidió.
                        --}}
                        <div class="border-t border-gray-200 p-5 pt-3 dark:border-gray-800">
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="cerrarMapaCliente"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                {{--
                                    emerald-700 y no 600 en los botones primarios: blanco sobre emerald-600 da 3.77:1,
                                    y el texto es text-lg bold —18px, justo por debajo del umbral de "texto grande" de
                                    WCAG, que son 18.66px— así que necesita 4.5:1. Con 700 son 5.48:1.
                                
                                    Medido, no estimado: el saldo en text-2xl sí califica como texto grande y le basta
                                    3:1, pero un botón de 18px no.
                                --}}
                                <button type="button" @click="guardarMapaCliente" :disabled="cargando || !nuevo.lat"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-700 text-lg font-bold text-white disabled:opacity-40">
                                    @{{ cargando ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </div>
                        </div>
                    </div>
                </Teleport>

{{--
                    ── Editar los productos del pedido ──

                    Sólo cantidades y quitar líneas. Agregar un producto nuevo necesita el
                    catálogo de LA CIUDAD DEL PEDIDO con sus precios, que puede no ser la
                    sucursal elegida en el encabezado: ofrecerlo igual cargaría el precio de
                    otra sucursal sin que nadie lo note. La corrección real en la puerta es
                    "pedí 3 y no 4" o "esto no lo quiero", y eso sí está.
                --}}
                <Teleport to="body">
                    <div v-if="edicion.pedido" class="fixed inset-0 z-[10002] flex items-end justify-center bg-black/60 sm:items-center" @click.self="edicion.pedido = null">
                        <div role="dialog" aria-modal="true" aria-labelledby="titulo-edicion"
                             class="flex max-h-[90vh] w-full max-w-md flex-col rounded-t-2xl bg-white dark:bg-gray-900 sm:rounded-2xl">
                            {{--
                                Mismo armado que el modal del domicilio: el contenido scrollea y el pie
                                queda fijo. Acá el alto depende de cuántas líneas tenga el pedido, así
                                que con unos pocos productos Cancelar y Guardar se iban arriba y había
                                que arrastrar hasta el final para confirmar algo ya decidido.
                            --}}
                            <div class="min-h-0 flex-1 overflow-y-auto p-5 pb-3">
                            <h3 id="titulo-edicion" class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">Editar productos</h3>
                            <p class="mb-3 text-base text-gray-600 dark:text-gray-400">
                                Pedido #@{{ edicion.pedido.id }} · @{{ edicion.pedido.cliente }}
                            </p>

                            <div v-for="(l, i) in edicion.lineas" :key="l.product_id"
                                 class="mb-2 rounded-xl border-2 border-gray-200 p-3 dark:border-gray-800">
                                <div class="mb-2 flex items-start justify-between gap-2">
                                    <span class="min-w-0 flex-1 truncate text-base font-semibold text-gray-800 dark:text-gray-200">@{{ l.name }}</span>
                                    <button type="button" @click="quitarLinea(i)" :aria-label="'Quitar ' + l.name"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[44px] shrink-0 rounded-lg px-2 text-base font-bold text-red-600 transition-colors duration-200 hover:bg-red-50 dark:hover:bg-red-900/20">
                                        Quitar
                                    </button>
                                </div>

                                <div class="flex items-center gap-2">
                                    <button type="button" @click="l.quantity = Math.max(1, l.quantity - 1)"
                                            :aria-label="'Quitar uno de ' + l.name"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] w-14 shrink-0 rounded-xl border-2 border-gray-300 text-2xl font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                        −
                                    </button>

                                    <input v-model.number="l.quantity" type="number" min="1" step="1" inputmode="numeric"
                                           :aria-label="'Cantidad de ' + l.name"
                                           class="min-h-[56px] w-full rounded-xl border-2 border-gray-300 px-2 text-center text-xl font-bold text-gray-900 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">

                                    <button type="button" @click="l.quantity = (Number(l.quantity) || 0) + 1"
                                            :aria-label="'Agregar uno de ' + l.name"
                                            class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[56px] w-14 shrink-0 rounded-xl border-2 border-gray-300 text-2xl font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                        +
                                    </button>

                                    <span class="w-24 shrink-0 text-right text-base font-semibold text-gray-700 dark:text-gray-300">
                                        @{{ money(l.price * (Number(l.quantity) || 0)) }}
                                    </span>
                                </div>
                            </div>

                            <p v-if="!edicion.lineas.length" class="mb-3 rounded-xl bg-amber-50 p-3 text-base text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                                Un pedido sin productos no se puede guardar. Si ya no va, usá Cancelar.
                            </p>

                            {{-- El total de antes y el de ahora, para ver el efecto antes de guardar. --}}
                            <div class="mb-3 flex items-center justify-between gap-2 border-t-2 border-gray-200 pt-3 dark:border-gray-800">
                                <span class="text-base text-gray-600 dark:text-gray-400">
                                    Antes @{{ money(edicion.pedido.total) }}
                                </span>
                                <span class="text-2xl font-extrabold text-gray-900 dark:text-white">@{{ money(totalEditado) }}</span>
                            </div>

                            {{--
                                El aviso de la plata cobrada. Es lo que convierte la edición en algo
                                que alguien tiene que resolver: si no se ve acá, el distribuidor
                                guarda y se va sin devolver la diferencia.
                            --}}
                            <p v-if="edicion.pedido.cobrado > 0 && Math.abs(edicion.pedido.cobrado - totalEditado) >= 0.01"
                               class="mb-3 rounded-xl p-3 text-base font-semibold"
                               :class="edicion.pedido.cobrado > totalEditado
                                   ? 'bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300'
                                   : 'bg-red-50 text-red-800 dark:bg-red-900/20 dark:text-red-300'">
                                @{{ edicion.pedido.cobrado > totalEditado
                                    ? 'Ya cobraste ' + money(edicion.pedido.cobrado) + ': tenés que devolver ' + money(edicion.pedido.cobrado - totalEditado)
                                    : 'Ya cobraste ' + money(edicion.pedido.cobrado) + ': falta cobrar ' + money(totalEditado - edicion.pedido.cobrado) }}
                            </p>

                        </div>

                        <div class="border-t border-gray-200 p-5 pt-3 dark:border-gray-800">
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="edicion.pedido = null"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="guardarEdicion" :disabled="cargando || !edicion.lineas.length"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-700 text-lg font-bold text-white disabled:opacity-40">
                                    @{{ cargando ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </div>
                        </div>
                    </div>
                </Teleport>

                {{--
                    ── Editar el cliente ──

                    Nombre y teléfono, nada más. El domicilio tiene su propio editor con
                    mapa y se llega desde acá: es el único lugar donde se puede VER dónde
                    cae el pin antes de guardarlo, y un par de campos de texto para una
                    coordenada es cómo se marcan domicilios equivocados.

                    Hace falta desde la calle: el nombre se carga apurado con el cliente
                    delante —"Marcela", sin apellido— y el teléfono se tipea mal. Hasta
                    ahora corregirlo exigía entrar al CRM desde una computadora, así que
                    no se corregía.
                --}}
                <Teleport to="body">
                    <div v-if="edicionCliente" class="fixed inset-0 z-[10002] flex items-end justify-center bg-black/60 sm:items-center" @click.self="edicionCliente = null">
                        <div role="dialog" aria-modal="true" aria-labelledby="titulo-editar-cliente"
                             class="w-full max-w-md rounded-t-2xl bg-white p-5 dark:bg-gray-900 sm:rounded-2xl">
                            <h3 id="titulo-editar-cliente" class="mb-3 text-xl font-bold text-gray-800 dark:text-gray-100">
                                Editar cliente
                            </h3>

                            <label for="cliente-nombre" class="mb-1 block text-base font-semibold text-gray-700 dark:text-gray-300">
                                Nombre
                            </label>
                            <input id="cliente-nombre" v-model="edicionCliente.nombre" type="text"
                                   class="mb-3 min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-lg focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">

                            <label for="cliente-telefono" class="mb-1 block text-base font-semibold text-gray-700 dark:text-gray-300">
                                Teléfono
                            </label>
                            {{--
                                `type="tel"` y no "number": abre el teclado numérico pero deja
                                escribir el +, los espacios y los paréntesis que la gente usa, y
                                no se come los ceros de adelante como hace number.
                            --}}
                            <input id="cliente-telefono" v-model="edicionCliente.telefono" type="tel" inputmode="tel"
                                   class="mb-1 min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-lg focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                            <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                                Es con lo que se identifica al cliente en todo el sistema, incluido WhatsApp.
                            </p>

                            {{-- Al editor con mapa, que es donde el domicilio se puede ver. --}}
                            <button type="button" @click="editarDomicilioDelCliente"
                                    class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 mb-3 flex min-h-[56px] w-full items-center justify-center rounded-xl border-2 border-gray-300 text-base font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                <svg class="mr-1 inline h-5 w-5 shrink-0 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                @{{ edicionCliente.ubicacion ? 'Editar domicilio' : 'Marcar domicilio' }}
                            </button>

                            <p v-if="avisoCliente" class="mb-3 rounded-xl bg-red-50 px-4 py-3 text-base text-red-700 dark:bg-red-900/25 dark:text-red-300">
                                @{{ avisoCliente }}
                            </p>

                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="edicionCliente = null"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="guardarEdicionCliente"
                                        :disabled="cargando || ! edicionCliente.nombre.trim() || ! edicionCliente.telefono.trim()"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-700 text-lg font-bold text-white disabled:opacity-40">
                                    @{{ cargando ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                {{-- ── Registrar una devolución de envases ── --}}
                <Teleport to="body">
                    <div v-if="devolucion.cliente" class="fixed inset-0 z-[10002] flex items-end justify-center bg-black/60 sm:items-center" @click.self="devolucion.cliente = null">
                        <div role="dialog" aria-modal="true" aria-labelledby="titulo-devolucion"
                             class="w-full max-w-md rounded-t-2xl bg-white p-5 dark:bg-gray-900 sm:rounded-2xl">
                            <h3 id="titulo-devolucion" class="mb-1 text-xl font-bold text-gray-800 dark:text-gray-100">Devolución de envases</h3>
                            <p class="mb-3 text-base text-gray-600 dark:text-gray-400">
                                @{{ devolucion.cliente.nombre }} · tiene @{{ devolucion.cliente.saldo }} envase@{{ devolucion.cliente.saldo === 1 ? '' : 's' }}
                            </p>

                            <label for="punto-devueltos" class="mb-1 block text-base font-semibold text-gray-700 dark:text-gray-300">
                                ¿Cuántos recibiste?
                            </label>
                            <input id="punto-devueltos" v-model="devolucion.cantidad" type="number" min="1" step="1" inputmode="numeric"
                                   class="mb-2 min-h-[64px] w-full rounded-xl border-2 border-gray-300 px-4 text-center text-2xl font-bold text-gray-900 focus:border-brandColor focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">

                            {{-- Se anticipa el resultado: así un número de más se ve antes de guardarlo. --}}
                            <p class="mb-3 text-base text-gray-700 dark:text-gray-300">
                                Le quedan <span class="font-bold">@{{ devolucion.cliente.saldo - enteroDe(devolucion.cantidad) }}</span>
                            </p>

                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="devolucion.cliente = null"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Cancelar
                                </button>
                                <button type="button" @click="guardarDevolucion" :disabled="cargando || enteroDe(devolucion.cantidad) < 1"
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-700 text-lg font-bold text-white disabled:opacity-40">
                                    @{{ cargando ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                {{-- ── Cobro: dentro del pedido, no en otra sección ── --}}
                <Teleport to="body">
                    <div v-if="cobro.pedido" class="fixed inset-0 z-[10002] flex items-end justify-center bg-black/50 sm:items-center" @click.self="cobro.pedido = null">
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
                                        class="cursor-pointer focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:focus:ring-offset-gray-900 min-h-[64px] rounded-xl bg-emerald-700 text-lg font-bold text-white transition-colors duration-200 disabled:opacity-40">
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
                    clientesUrl: { type: String, default: '' },
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
                        // Resultado de buscar por nombre; null = no se buscó por nombre.
                        coincidencias: null,
                        pedidos: [],
                        recientes: [],
                        // Sin `amount`: el importe lo calcula el servidor (siempre el total).
                        cobro: { pedido: null, method: 'efectivo' },
                        // dejo/retiro arrancan vacíos y no en 0: un 0 precargado se
                        // confirma sin leerlo, y entonces el control no sirve de nada.
                        entrega: { pedido: null, archivo: null, preview: null, dejo: '', retiro: '', ubicacionMarcada: false, ubicacion: null },
                        ubicando: false,
                        // El contacto que se está creando: domicilio y envases iniciales.
                        nuevo: { bidones: '', lat: null, lng: null, direccion: '', referencia: '' },
                        mapaError: null,
                        direccionSugerida: null,
                        /*
                         * La agenda. `clientes.clientes` se lee raro pero es a propósito:
                         * el objeto es la RESPUESTA del servidor entera —filas, totales y
                         * si hay más— y desarmarla en cuatro propiedades sueltas abre la
                         * puerta a que queden desincronizadas entre sí.
                         */
                        clientes: { clientes: [], pagina: 1, hay_mas: false, en_la_calle: 0, del_dia: { entregados: 0, retirados: 0 } },
                        busquedaCliente: '',
                        // El cliente que se está editando: nombre y teléfono.
                        edicionCliente: null,
                        /*
                         * El error del modal, aparte de `aviso`. El aviso general se dibuja
                         * arriba de la pantalla, detrás del modal: un "ese teléfono ya es de
                         * otro" ahí no lo ve nadie y el usuario cree que no pasó nada.
                         */
                        avisoCliente: null,
                        // El rango del historial. Los dos vacíos = los últimos 100.
                        rangoHistorial: { desde: '', hasta: '' },
                        devolucion: { cliente: null, cantidad: '' },
                        edicion: { pedido: null, lineas: [] },
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
                    /**
                     * Los pedidos en grupos de día, para el historial.
                     *
                     * Los pendientes van en UN grupo sin día: ahí la lista es una cola de
                     * trabajo, y partirla por fecha sugiere que los de ayer son de otra tanda
                     * cuando son los más urgentes. `dia: null` es lo que la plantilla lee para
                     * no dibujar el encabezado.
                     *
                     * Se agrupa recorriendo en orden y no con un objeto indexado por día: el
                     * servidor ya los manda del más nuevo al más viejo, y un objeto reordena
                     * sus claves —las numéricas primero— así que un día podría saltar de lugar.
                     */
                    pedidosAgrupados() {
                        if (this.filtro !== 'historial') {
                            return [{ dia: null, etiqueta: null, pedidos: this.pedidos }];
                        }

                        const grupos = [];

                        this.pedidos.forEach(pe => {
                            const dia = pe.dia || 'sin-fecha';
                            const ultimo = grupos[grupos.length - 1];

                            if (ultimo && ultimo.dia === dia) {
                                ultimo.pedidos.push(pe);
                                return;
                            }

                            grupos.push({ dia, etiqueta: this.etiquetaDeDia(pe.dia), pedidos: [pe] });
                        });

                        return grupos;
                    },

                    /** El total que va a quedar con las líneas como están ahora. */
                    totalEditado() {
                        return this.edicion.lineas.reduce(
                            (t, l) => t + (Number(l.price) || 0) * (Number(l.quantity) || 0),
                            0,
                        );
                    },

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
                        if (this.edicion.pedido) this.edicion.pedido = null;
                        else if (this.devolucion.cliente) this.devolucion.cliente = null;
                        else if (this.edicionCliente) this.edicionCliente = null;
                        else if (this.mapaCliente) this.cerrarMapaCliente();
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

                        // La lista que esté en pantalla sigue al lugar elegido. Las dos son
                        // de la ciudad: dejarlas con los datos de la sucursal anterior es
                        // peor que vaciarlas, porque no se nota.
                        if (this.vista === 'pedidos') this.cargarPedidos();
                        if (this.vista === 'clientes') this.cargarClientes();
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

                    irAClientes() {
                        this.vista = 'clientes';
                        this.cargarClientes();
                    },

                    /**
                     * Trae una página de la agenda.
                     *
                     * `acumular` es lo que distingue "Ver más" de "Buscar": el primero
                     * agrega abajo, el segundo reemplaza. Un solo método con una bandera y
                     * no dos: la diferencia entre los dos son tres líneas, y duplicarlo
                     * garantiza que un arreglo se aplique a uno solo.
                     */
                    cargarClientes(pagina = 1, acumular = false) {
                        if (! this.sucursal) return;

                        this.cargando = true;

                        this.$axios.get(this.clientesUrl, {
                            params: {
                                // Obligatoria: el backend rechaza la consulta sin sucursal,
                                // porque sin ella devolvería clientes de otras ciudades.
                                ciudad: this.sucursal.ciudad_id,
                                busqueda: this.busquedaCliente.trim() || null,
                                pagina,
                            },
                        })
                            .then(r => {
                                this.clientes = acumular
                                    ? { ...r.data, clientes: this.clientes.clientes.concat(r.data.clientes) }
                                    : r.data;
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo cargar la agenda de clientes.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    buscarClientes() {
                        // Siempre desde la página 1: buscar con la página 3 puesta devuelve
                        // la tercera página de un resultado que recién empieza, o nada.
                        this.cargarClientes(1, false);
                    },

                    masClientes() {
                        this.cargarClientes(this.clientes.pagina + 1, true);
                    },

                    abrirEdicion(pedido) {
                        /*
                         * Se COPIAN las líneas en vez de editar las del pedido en el lugar:
                         * cancelar tiene que dejar la tarjeta como estaba, y mutar el objeto
                         * del listado la dejaría ya cambiada aunque no se haya guardado nada.
                         */
                        this.edicion = {
                            pedido,
                            lineas: (pedido.productos || []).map(l => ({
                                product_id: l.product_id,
                                name: l.name,
                                price: Number(l.price) || 0,
                                quantity: Number(l.quantity) || 1,
                            })),
                        };
                    },

                    quitarLinea(i) {
                        this.edicion.lineas.splice(i, 1);
                    },

                    guardarEdicion() {
                        const pedido = this.edicion.pedido;

                        if (! pedido || ! this.edicion.lineas.length) return;

                        this.cargando = true;

                        this.$axios.patch(
                            '{{ url(config('app.admin_path').'/distribuidor/pedido') }}/' + pedido.id + '/productos',
                            {
                                productos: this.edicion.lineas.map(l => ({
                                    product_id: l.product_id,
                                    quantity: Math.max(1, this.enteroDe(l.quantity)),
                                    price: l.price,
                                })),
                            },
                        )
                            .then(r => {
                                this.reemplazar(r.data.pedido);
                                this.edicion = { pedido: null, lineas: [] };
                                this.aviso = 'Pedido actualizado. Quedó registrado quién lo editó.';
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo editar el pedido.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    /** Qué dice la fila sobre el recordatorio de este cliente. */
                    textoRecordatorio(r) {
                        // La baja primero: pesa más que cualquier otro estado y es lo
                        // único que el equipo no puede deshacer desde acá.
                        if (r.baja) return 'El cliente pidió no recibir más';
                        if (! r.activo) return 'Apagado';
                        if (! r.proximo_at) return 'Sin fecha programada';

                        return 'Próximo: ' + this.fechaCorta(r.proximo_at)
                            + (r.intentos ? ' · intento ' + (r.intentos + 1) : '');
                    },

                    cambiarRecordatorio(cliente) {
                        const r = cliente.recordatorio;

                        // Defensivo: el botón no se dibuja si hay baja, pero el método es
                        // público y una baja revertida por accidente no se nota hasta que
                        // el cliente se queja.
                        if (! r || r.baja) return;

                        this.$axios.patch(
                            '{{ url(config('app.admin_path').'/distribuidor/cliente') }}/' + cliente.person_id + '/recordatorio',
                            { activo: ! r.activo },
                        )
                            .then(res => {
                                // Se parchea la fila en vez de recargar la página entera:
                                // es un cambio de una fila y recargar pierde el scroll en
                                // una lista de 30.
                                cliente.recordatorio = res.data.recordatorio;
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo cambiar el recordatorio.'); });
                    },

                    abrirEdicionCliente(cliente) {
                        /*
                         * Se COPIAN los campos en vez de editar la fila de la lista: cancelar
                         * tiene que dejar la fila como estaba, y atar los inputs al objeto del
                         * listado la deja ya cambiada aunque no se haya guardado nada. Mismo
                         * motivo que en `abrirEdicion` de un pedido.
                         */
                        this.edicionCliente = {
                            person_id: cliente.person_id,
                            nombre: cliente.nombre || '',
                            telefono: cliente.telefono || '',
                            ubicacion: cliente.ubicacion || null,
                        };
                        this.avisoCliente = null;
                    },

                    guardarEdicionCliente() {
                        const c = this.edicionCliente;

                        if (! c || ! c.nombre.trim() || ! c.telefono.trim()) return;

                        this.cargando = true;
                        this.avisoCliente = null;

                        this.$axios.patch(
                            '{{ url(config('app.admin_path').'/distribuidor/cliente') }}/' + c.person_id,
                            { nombre: c.nombre.trim(), telefono: c.telefono.trim() },
                        )
                            .then(() => {
                                this.edicionCliente = null;
                                this.aviso = 'Cliente actualizado.';
                                // Se recarga y no se parchea la fila en memoria: el teléfono lo
                                // normaliza el servidor, así que lo que quedó guardado puede no
                                // ser carácter por carácter lo que se tipeó.
                                this.cargarClientes(this.clientes.pagina);
                            })
                            .catch(e => { this.avisoCliente = this.mensaje(e, 'No se pudo guardar el cliente.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    /**
                     * Del modal de datos al editor con mapa.
                     *
                     * Se cierra el de datos antes de abrir el otro: dos modales apilados en el
                     * mismo z-index dejan el de abajo tocable por los bordes, y en un celular
                     * eso es un toque accidental garantizado. Lo que se haya escrito en los
                     * campos y no se haya guardado se pierde, y por eso el botón dice
                     * "domicilio" y no "guardar y seguir".
                     */
                    editarDomicilioDelCliente() {
                        const c = this.edicionCliente;

                        if (! c) return;

                        this.edicionCliente = null;

                        // `abrirMapaCliente` espera la forma de una persona del CRM
                        // (id/name/ubicacion), no la de una fila de la agenda.
                        this.abrirMapaCliente({
                            id: c.person_id,
                            name: c.nombre,
                            ubicacion: c.ubicacion,
                        });
                    },

                    abrirDevolucion(cliente) {
                        /*
                         * La cantidad arranca vacía y no en el saldo del cliente: precargarla
                         * invita a confirmar sin contar, y el saldo es justamente lo que hay
                         * que verificar contra los envases que tenés en la mano.
                         */
                        this.devolucion = { cliente, cantidad: '' };
                    },

                    guardarDevolucion() {
                        const cliente = this.devolucion.cliente;
                        const cantidad = this.enteroDe(this.devolucion.cantidad);

                        if (! cliente || cantidad < 1) return;

                        this.cargando = true;

                        this.$axios.post(
                            '{{ url(config('app.admin_path').'/distribuidor/cliente') }}/' + cliente.person_id + '/bidones',
                            { retirados: cantidad },
                        )
                            .then(() => {
                                this.devolucion = { cliente: null, cantidad: '' };
                                this.aviso = 'Devolución registrada.';
                                // Se recarga la lista en vez de descontar en memoria: el saldo lo
                                // calcula el servidor sumando movimientos, y restar acá abriría
                                // una segunda verdad que puede no coincidir.
                                //
                                // Desde la página 1 a propósito: la fila que se acaba de saldar
                                // sale del orden —se ordena por saldo— y pedir la página que
                                // estaba puesta dejaría un hueco.
                                this.cargarClientes();
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo registrar la devolución.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    /**
                     * El encabezado de un día del historial: "Hoy", "Ayer" o "lun 06/10".
                     *
                     * El día lo decide el SERVIDOR y llega ya calculado (`pe.dia`): acá sólo
                     * se le pone nombre. Si el reloj del celular está en otro huso, lo que
                     * puede quedar mal es la palabra "Hoy", nunca la agrupación — y abajo
                     * siempre está la fecha explícita.
                     */
                    etiquetaDeDia(dia) {
                        if (! dia) return 'Sin fecha';

                        const hoy = new Date();
                        const iso = d => [
                            d.getFullYear(),
                            String(d.getMonth() + 1).padStart(2, '0'),
                            String(d.getDate()).padStart(2, '0'),
                        ].join('-');

                        if (dia === iso(hoy)) return 'Hoy';

                        const ayer = new Date(hoy);
                        ayer.setDate(ayer.getDate() - 1);

                        if (dia === iso(ayer)) return 'Ayer';

                        // Con día de la semana: en un historial de reparto "¿qué vendimos el
                        // sábado?" es una pregunta más común que "¿qué vendimos el día 4?".
                        const [a, m, d] = dia.split('-').map(Number);

                        return new Date(a, m - 1, d).toLocaleDateString('es-AR', {
                            weekday: 'short', day: '2-digit', month: '2-digit',
                        });
                    },

                    /** Una fecha corta a partir de lo que devuelve el servidor. */
                    fechaCorta(valor) {
                        if (! valor) return '—';

                        const d = new Date(String(valor).replace(' ', 'T'));

                        return Number.isNaN(d.getTime())
                            ? '—'
                            : d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' });
                    },

                    buscarCliente() {
                        const texto = this.telefono.trim();

                        if (! texto) return;

                        this.cargando = true;
                        this.aviso = null;
                        this.coincidencias = null;

                        /*
                         * Con letras es un nombre: se busca en la agenda, que ya está
                         * acotada a los clientes del distribuidor y a esta ciudad. No
                         * ofrece crear: un cliente nuevo se identifica por su teléfono.
                         */
                        if (/\p{L}/u.test(texto)) {
                            this.buscado = false;

                            this.$axios.get(this.clientesUrl, {
                                params: { ciudad: this.sucursal?.ciudad_id, busqueda: texto, pagina: 1 },
                            })
                                .then(r => { this.coincidencias = r.data.clientes; })
                                .catch(e => { this.aviso = this.mensaje(e, 'No se pudo buscar el cliente.'); })
                                .finally(() => { this.cargando = false; });

                            return;
                        }

                        this.$axios.get(this.buscarContactoUrl, { params: { telefono: this.telefono } })
                            .then(r => {
                                this.buscado = true;
                                this.encontrado = r.data.encontrado;
                                if (r.data.encontrado) this.cliente = r.data.persona;
                            })
                            .catch(() => { this.aviso = 'No se pudo buscar el contacto.'; })
                            .finally(() => { this.cargando = false; });
                    },

                    /** Una fila de la agenda tiene los mismos datos que la ficha, con otros nombres. */
                    elegirCoincidencia(c) {
                        this.elegirReciente({
                            id: c.person_id,
                            name: c.nombre,
                            telefono: c.telefono,
                            bidones: c.saldo,
                            ubicacion: c.ubicacion,
                        });
                        this.coincidencias = null;
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
                            direccion: this.nuevo.direccion || null,
                            referencia: this.nuevo.referencia || null,
                        })
                            .then(r => {
                                this.cliente = r.data.persona;
                                this.nuevo = { bidones: '', lat: null, lng: null, direccion: '', referencia: '' };
                                this.limpiarMapa();
                            })
                            .catch(e => { this.aviso = this.mensaje(e, 'No se pudo crear el contacto.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    limpiarCliente() {
                        this.cliente = null;
                        this.coincidencias = null;
                        this.buscado = false;
                        this.encontrado = false;
                        this.nombre = '';
                        // El domicilio y los envases son del cliente que se descartó: dejarlos
                        // se los adjudicaría al siguiente.
                        this.nuevo = { bidones: '', lat: null, lng: null, direccion: '', referencia: '' };
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
                            productos: this.lineas,
                        })
                            .then(() => {
                                this.carrito = {};
                                this.precios = {};
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

                        // El rango es del historial: dejarlo puesto al volver a pendientes
                        // escondería trabajo pendiente de otros días sin que se vea por qué.
                        if (filtro !== 'historial') this.rangoHistorial = { desde: '', hasta: '' };

                        this.cargarPedidos();
                    },

                    limpiarRango() {
                        this.rangoHistorial = { desde: '', hasta: '' };
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
                                // Sólo tienen sentido en el historial, y se mandan sólo si están:
                                // un null en los pendientes sería un parámetro que no hace nada.
                                desde: this.filtro === 'historial' ? (this.rangoHistorial.desde || null) : null,
                                hasta: this.filtro === 'historial' ? (this.rangoHistorial.hasta || null) : null,
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
                        this.precargarDireccion(lat, lng);

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

                                this.vistaMapa.attributionControl.setPrefix(false);

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
                        // Los textos que ya tenía, para editarlos y no reescribirlos.
                        this.nuevo.direccion = actual?.direccion || '';
                        this.nuevo.referencia = actual?.referencia || '';
                        this.direccionSugerida = null;

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
                                direccion: this.nuevo.direccion || null,
                                referencia: this.nuevo.referencia || null,
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

                                /*
                                 * El editor también se abre desde la agenda, y ahí `cliente` es
                                 * otro —o ninguno—. Sin esto, volver de guardar el domicilio
                                 * mostraba la fila con el domicilio viejo y el botón seguía
                                 * diciendo "Marcar" para algo que ya estaba marcado.
                                 */
                                if (this.vista === 'clientes') {
                                    this.cargarClientes(this.clientes.pagina);
                                }

                                this.aviso = 'Domicilio guardado.';
                                this.cerrarMapaCliente();
                            })
                            .catch(e => { this.mapaError = this.mensaje(e, 'No se pudo guardar el domicilio.'); })
                            .finally(() => { this.cargando = false; });
                    },

                    /**
                     * Precarga la dirección a partir del pin, sin pisar lo escrito a mano.
                     *
                     * 🔴 No sobreescribe un texto que el distribuidor ya corrigió: el
                     * geocodificador devuelve calle y barrio pero muchas veces sin altura, así
                     * que la corrección a mano es MEJOR dato que lo que viene del servicio.
                     * Pisarla al reacomodar el pin dos metros borraría el trabajo bueno.
                     *
                     * Se recuerda qué texto puso el geocodificador para poder distinguir
                     * "vacío o puesto por mí" de "escrito por una persona".
                     */
                    precargarDireccion(lat, lng) {
                        const escritoAMano = this.nuevo.direccion
                            && this.nuevo.direccion !== this.direccionSugerida;

                        if (escritoAMano) return;

                        this.$axios.get('{{ route('admin.distribuidor.direccion') }}', { params: { lat, lng } })
                            .then(r => {
                                const d = r.data.direccion;

                                // Null es normal: hay coordenadas en medio del campo. Se deja el
                                // campo como está y el pin se guarda igual.
                                if (! d) return;

                                this.nuevo.direccion = d;
                                this.direccionSugerida = d;
                            })
                            .catch(() => { /* que no resuelva no puede trabar el guardado */ });
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
