@php
    $cfg = config('inmobiliaria.inmuebles');
    $v = fn ($campo, $default = null) => old($campo, $inmueble->{$campo} ?? $default);
    $caracteristicas = old('caracteristicas.clave')
        ? array_combine(old('caracteristicas.clave'), old('caracteristicas.valor'))
        : (array) ($inmueble->caracteristicas ?? []);
@endphp

<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-900">
        <div class="text-xl font-bold dark:text-white">{{ $titulo }}</div>

        <div class="flex gap-x-2.5">
            <a href="{{ route('admin.inmuebles.index') }}" class="secondary-button">Cancelar</a>
            <button type="submit" class="primary-button">Guardar</button>
        </div>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
            <b>Revisá estos campos:</b>
            <ul class="ml-4 mt-1 list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="flex gap-4 max-lg:flex-wrap">
        <div class="flex flex-1 flex-col gap-4">

            {{-- Identificación --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold dark:text-white">Identificación</p>

                <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Código <span class="text-red-500">*</span></span>
                        <input name="codigo" value="{{ $v('codigo', $codigo) }}"
                               class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        <span class="text-xs text-gray-500">
                            Es el código que el cliente ve en el anuncio y el único dato del inmueble que
                            maneja el agente. Si el anuncio ya tiene uno, poné ese.
                        </span>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Asesor titular <span class="text-red-500">*</span></span>
                        <select name="user_id" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Seleccioná…</option>
                            @foreach ($asesores as $asesor)
                                <option value="{{ $asesor->id }}" @selected($v('user_id') == $asesor->id)>{{ $asesor->name }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-gray-500">Recibe el handoff cuando el agente deriva este inmueble.</span>
                    </label>

                    <label class="col-span-2 flex flex-col gap-1 max-sm:col-span-1">
                        <span class="text-xs font-medium dark:text-gray-300">Título <span class="text-red-500">*</span></span>
                        <input name="titulo" value="{{ $v('titulo') }}"
                               class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                    </label>

                    <label class="col-span-2 flex flex-col gap-1 max-sm:col-span-1">
                        <span class="text-xs font-medium dark:text-gray-300">Descripción</span>
                        <textarea name="descripcion" rows="6"
                                  class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">{{ $v('descripcion') }}</textarea>
                    </label>
                </div>
            </div>

            {{-- Ubicación --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-white">Ubicación</p>
                <p class="mb-4 text-xs text-gray-500">
                    Zona, barrio y referencia son lo que el agente usa para ubicar al cliente en la
                    conversación. La dirección exacta y las coordenadas le sirven para responder
                    consultas puntuales y para enviarle la ubicación al cliente por WhatsApp.
                </p>

                <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Zona <span class="text-red-500">*</span></span>
                        <input name="zona" value="{{ $v('zona') }}" placeholder="Zona Norte"
                               class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Barrio</span>
                        <input name="barrio" value="{{ $v('barrio') }}" placeholder="Barrio Hamacas"
                               class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                    </label>

                    <label class="col-span-2 flex flex-col gap-1 max-sm:col-span-1">
                        <span class="text-xs font-medium dark:text-gray-300">Referencia</span>
                        <input name="referencia" value="{{ $v('referencia') }}" placeholder="Sobre Av. Banzer, a la altura del Km 11"
                               class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                    </label>
                </div>

                <div class="mt-4 rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900/50">
                    <p class="mb-3 text-xs font-semibold text-gray-700 dark:text-gray-300">
                        Ubicación exacta
                    </p>

                    <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                        <label class="col-span-2 flex flex-col gap-1 max-sm:col-span-1">
                            <span class="text-xs font-medium dark:text-gray-300">Dirección exacta</span>
                            <input name="direccion_exacta" value="{{ $v('direccion_exacta') }}"
                                   class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        </label>

                        <label class="col-span-2 flex flex-col gap-1 max-sm:col-span-1">
                            <span class="text-xs font-medium dark:text-gray-300">Link de Google Maps</span>
                            <input name="google_maps_url" value="{{ $v('google_maps_url') }}" placeholder="https://maps.app.goo.gl/…"
                                   class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        </label>

                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium dark:text-gray-300">Latitud</span>
                            <input name="latitud" value="{{ $v('latitud') }}"
                                   class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        </label>

                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium dark:text-gray-300">Longitud</span>
                            <input name="longitud" value="{{ $v('longitud') }}"
                                   class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        </label>

                        <label class="col-span-2 flex flex-col gap-1 max-sm:col-span-1">
                            <span class="text-xs font-medium dark:text-gray-300">Punto de encuentro</span>
                            <input name="punto_encuentro" value="{{ $v('punto_encuentro') }}"
                                   class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                            <span class="text-xs text-gray-500">Dónde se encuentra el asesor con el cliente para la visita.</span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Características --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-white">Características</p>
                <p class="mb-4 text-xs text-gray-500">
                    Lo que no entra en los campos fijos: altura de un galpón, frente de un lote, m² de
                    oficinas. El agente las <b>muestra</b> al cliente, pero no filtra por ellas.
                    Podés agregar las que quieras y borrar las que no uses.
                </p>

                <div id="caracteristicas" class="flex flex-col gap-2">
                    @foreach ($caracteristicas + ['' => ''] as $clave => $valor)
                        <div class="flex items-center gap-2" data-fila>
                            <input name="caracteristicas[clave][]" value="{{ $clave }}" placeholder="Nombre (ej. altura)"
                                   class="w-1/3 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />

                            <input name="caracteristicas[valor][]" value="{{ is_array($valor) ? implode(', ', $valor) : $valor }}" placeholder="Valor (ej. 6,5 m)"
                                   class="flex-1 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />

                            {{-- Borrar la fila. Es lo que faltaba: sin esto, una
                                 característica cargada por error solo se podía
                                 vaciar a mano y quedaba ocupando lugar. --}}
                            <button type="button"
                                    onclick="borrarCaracteristica(this)"
                                    aria-label="Borrar esta característica"
                                    title="Borrar"
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded text-gray-400 transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400 dark:hover:bg-red-950">
                                <span class="icon-delete text-lg"></span>
                            </button>
                        </div>
                    @endforeach
                </div>

                <button type="button" class="secondary-button mt-3" onclick="agregarCaracteristica()">
                    Agregar característica
                </button>

                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-gray-500">Ejemplos según el tipo</summary>
                    <div class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-gray-500 max-sm:grid-cols-1">
                        @foreach ($cfg['caracteristicas_por_tipo'] as $tipoClave => $campos)
                            @if ($campos)
                                <p>
                                    <b>{{ $cfg['tipos'][$tipoClave] ?? $tipoClave }}:</b>
                                    {{ collect($campos)->keys()->implode(', ') }}
                                </p>
                            @endif
                        @endforeach
                    </div>
                </details>
            </div>

        </div>

        {{-- Columna lateral --}}
        <div class="flex w-[360px] flex-col gap-4 max-lg:w-full">
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold dark:text-white">Clasificación</p>

                <div class="flex flex-col gap-4">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Estado <span class="text-red-500">*</span></span>
                        <select name="estado" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            @foreach ($cfg['estados'] as $estado)
                                <option value="{{ $estado }}" @selected($v('estado', 'borrador') === $estado)>{{ ucfirst($estado) }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-gray-500">Solo <b>Disponible</b> lo ofrece el agente.</span>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Operación <span class="text-red-500">*</span></span>
                        <select name="operacion" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            @foreach ($cfg['operaciones'] as $op)
                                <option value="{{ $op }}" @selected($v('operacion') === $op)>{{ ucfirst($op) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Tipo <span class="text-red-500">*</span></span>
                        <select name="tipo"
                                class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            @foreach ($cfg['tipos'] as $clave => $label)
                                <option value="{{ $clave }}" @selected($v('tipo') === $clave)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-white">Precio</p>
                <p class="mb-4 text-xs text-gray-500">
                    Se muestra siempre en la moneda que elijas. El sistema <b>no convierte</b> entre
                    dólares y bolivianos en ningún momento.
                </p>

                <div class="flex flex-col gap-4">
                    <div class="flex gap-2">
                        <label class="flex flex-1 flex-col gap-1">
                            <span class="text-xs font-medium dark:text-gray-300">Precio <span class="text-red-500">*</span></span>
                            <input name="precio" value="{{ $v('precio') }}"
                                   class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        </label>

                        <label class="flex w-28 flex-col gap-1">
                            <span class="text-xs font-medium dark:text-gray-300">Moneda</span>
                            <select name="moneda" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                @foreach ($cfg['monedas'] as $m)
                                    <option value="{{ $m }}" @selected($v('moneda', $cfg['moneda_por_defecto']) === $m)>{{ $m }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Precio anterior</span>
                        <input name="precio_anterior" value="{{ $v('precio_anterior') }}"
                               class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        <span class="text-xs text-gray-500">Si hubo rebaja. El agente puede mencionarla.</span>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium dark:text-gray-300">Modalidad de cambio</span>
                        <select name="modalidad_cambio" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Sin especificar</option>
                            <option value="solo_usd" @selected($v('modalidad_cambio') === 'solo_usd')>Solo dólares</option>
                            <option value="tc_oficial" @selected($v('modalidad_cambio') === 'tc_oficial')>Dólares o al T/C oficial BCB</option>
                            <option value="tc_paralelo" @selected($v('modalidad_cambio') === 'tc_paralelo')>Dólares o al cambio paralelo</option>
                        </select>
                    </label>

                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="negociable" value="1" @checked($v('negociable'))
                               class="rounded border-gray-300" />
                        <span class="text-xs font-medium dark:text-gray-300">Precio negociable</span>
                    </label>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold dark:text-white">Detalles del inmueble</p>

                <div class="grid grid-cols-2 gap-4">
                    @foreach ([
                        'dormitorios'    => 'Dormitorios',
                        'banos'          => 'Baños',
                        'parqueos'       => 'Parqueos',
                        'm2_construidos' => 'm² construidos',
                        'm2_terreno'     => 'm² de terreno',
                    ] as $campo => $label)
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium dark:text-gray-300">{{ $label }}</span>
                            <input name="{{ $campo }}" value="{{ $v($campo) }}"
                                   class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
                        </label>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    Estos sí son filtrables: el agente busca por ellos.
                </p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function agregarCaracteristica() {
        const cont = document.getElementById('caracteristicas');
        const fila = cont.lastElementChild.cloneNode(true);

        fila.querySelectorAll('input').forEach(i => i.value = '');
        cont.appendChild(fila);
        fila.querySelector('input').focus();
    }

    /*
     * Borra la fila. Si es la última que queda, la vacía en lugar de sacarla:
     * quedarse sin ninguna dejaría el formulario sin dónde escribir la próxima.
     */
    function borrarCaracteristica(boton) {
        const cont = document.getElementById('caracteristicas');
        const fila = boton.closest('[data-fila]');

        if (cont.querySelectorAll('[data-fila]').length > 1) {
            fila.remove();
        } else {
            fila.querySelectorAll('input').forEach(i => i.value = '');
        }
    }
</script>
@endpush
