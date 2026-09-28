{{--
    Lista de precios por variante.

    Para los productos que no tienen un precio sino varios: Bolsas Magia Verde
    se cotiza por tamaño y canal, Hules por color, Tapas por si lleva impresión.

    Primero se declaran los EJES (las columnas por las que varía el precio) y
    después una fila por combinación. Los ejes se editan arriba y la tabla se
    reacomoda sola: si se cargaran al revés habría que saber de antemano cuántas
    columnas se necesitan, que es lo que el asesor está decidiendo justo ahí.

    Dejar la tabla vacía es lo normal: la mayoría de los productos se venden al
    precio fijo del campo Precio y no necesitan nada de esto.
--}}
@php
    $producto = $product ?? null;

    $ejes = old('ejes_precio', $producto?->ejes_precio ?? []);
    $ejes = array_values(array_filter((array) $ejes, fn ($e) => trim((string) $e) !== ''));

    $filas = old('precios');

    if ($filas === null) {
        $filas = ($producto?->precios ?? collect())->map(fn ($p) => [
            'valores' => (array) $p->valores,
            'precio'  => $p->precio === null ? '' : rtrim(rtrim((string) $p->precio, '0'), '.'),
            'unidad'  => $p->unidad,
            'minimo'  => $p->minimo,
            'nota'    => $p->nota,
        ])->all();
    }

    $filas = array_values((array) $filas);
@endphp

<div class="box-shadow mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.products.precios.title')
    </p>

    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.precios.help')
    </p>

    {{--
        Marca que este formulario incluye la tabla de precios.

        Sin esto, cualquier POST a products.update que no traiga `precios` —el
        editor inline de atributos de la vista del producto, por ejemplo— se
        leería como «el asesor vació la tabla» y borraría la lista entera.
    --}}
    <input type="hidden" name="precios_enviados" value="1" />

    {{-- Ejes --}}
    <div class="mb-4">
        <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
            @lang('admin::app.products.precios.axes')
        </p>

        <div id="precios-ejes" class="flex flex-wrap items-center gap-2">
            @foreach ($ejes ?: [''] as $eje)
                <span class="flex items-center gap-1">
                    <input
                        name="ejes_precio[]"
                        value="{{ $eje }}"
                        data-eje
                        list="precios-ejes-sugeridos"
                        placeholder="@lang('admin::app.products.precios.axis-placeholder')"
                        class="w-44 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    />

                    <button
                        type="button"
                        data-quitar-eje
                        class="icon-delete cursor-pointer rounded p-1.5 text-lg text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                        title="@lang('admin::app.products.precios.remove-axis')"
                    ></button>
                </span>
            @endforeach
        </div>

        <datalist id="precios-ejes-sugeridos">
            @foreach (config('precios.ejes_sugeridos', []) as $sugerido)
                <option value="{{ $sugerido }}"></option>
            @endforeach
        </datalist>

        <button type="button" id="precios-agregar-eje" class="secondary-button mt-2">
            @lang('admin::app.products.precios.add-axis')
        </button>
    </div>

    {{-- Tabla de combinaciones --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="precios-tabla">
            <thead>
                <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    {{--
                        Las columnas de eje se dibujan acá Y las mantiene el script.
                        Server-side porque de lo contrario la tabla no dice a qué
                        combinación pertenece cada precio hasta que corre el JS —y
                        si el JS no corre, el formulario se envía sin los valores
                        y la lista se guarda vacía.
                    --}}
                    @foreach ($ejes as $i => $eje)
                        <th class="pb-2 pr-2" data-eje-idx="{{ $i }}">{{ $eje }}</th>
                    @endforeach

                    <th class="pb-2 pr-2" data-col-precio>@lang('admin::app.products.precios.price')</th>
                    <th class="pb-2 pr-2">@lang('admin::app.products.precios.unit')</th>
                    <th class="pb-2 pr-2">@lang('admin::app.products.precios.min')</th>
                    <th class="pb-2 pr-2">@lang('admin::app.products.precios.note')</th>
                    <th class="pb-2"></th>
                </tr>
            </thead>

            <tbody id="precios-filas">
                @foreach ($filas ?: [[]] as $i => $fila)
                    <tr data-fila>
                        @foreach ($ejes as $j => $eje)
                            <td class="py-1 pr-2" data-eje-idx="{{ $j }}">
                                <input
                                    name="precios[{{ $i }}][valores][{{ $eje }}]"
                                    value="{{ $fila['valores'][$eje] ?? '' }}"
                                    placeholder="{{ $eje }}"
                                    class="w-36 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                />
                            </td>
                        @endforeach

                        <td class="py-1 pr-2" data-col-precio>
                            <input
                                name="precios[{{ $i }}][precio]"
                                data-campo="precio"
                                value="{{ $fila['precio'] ?? '' }}"
                                inputmode="decimal"
                                placeholder="@lang('admin::app.products.precios.price-placeholder')"
                                class="w-28 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            />
                        </td>

                        <td class="py-1 pr-2">
                            <input
                                name="precios[{{ $i }}][unidad]"
                                data-campo="unidad"
                                value="{{ $fila['unidad'] ?? '' }}"
                                placeholder="@lang('admin::app.products.precios.unit-placeholder')"
                                class="w-36 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            />
                        </td>

                        <td class="py-1 pr-2">
                            <input
                                name="precios[{{ $i }}][minimo]"
                                data-campo="minimo"
                                value="{{ $fila['minimo'] ?? '' }}"
                                placeholder="@lang('admin::app.products.precios.min-placeholder')"
                                class="w-28 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            />
                        </td>

                        <td class="py-1 pr-2">
                            <input
                                name="precios[{{ $i }}][nota]"
                                data-campo="nota"
                                value="{{ $fila['nota'] ?? '' }}"
                                placeholder="@lang('admin::app.products.precios.note-placeholder')"
                                class="w-full min-w-48 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            />
                        </td>

                        <td class="py-1">
                            <button
                                type="button"
                                data-quitar-fila
                                class="icon-delete cursor-pointer rounded p-1.5 text-xl text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                                title="@lang('admin::app.products.precios.remove-row')"
                            ></button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <button type="button" id="precios-agregar-fila" class="secondary-button mt-3">
        @lang('admin::app.products.precios.add-row')
    </button>

    {{-- Los valores ya cargados, para que el script arme las columnas de eje. --}}
    <script type="application/json" id="precios-cargados">@json(array_map(fn ($f) => (array) ($f['valores'] ?? []), $filas ?: [[]]))</script>
</div>

@push('scripts')
    <script>
        /*
         * Delegado en `document`: Vue monta sobre #app después de este bloque y
         * reemplaza los nodos, así que un addEventListener directo sobre un
         * botón se pierde sin dejar error en consola.
         */
        (function () {
            const ejesCont  = () => document.getElementById('precios-ejes');
            const cuerpo    = () => document.getElementById('precios-filas');
            const cabecera  = () => document.querySelector('#precios-tabla thead tr');

            if (! ejesCont()) return;

            // Valores ya guardados, por posición de fila. Solo se usan en el
            // primer dibujado: después manda lo que hay en los inputs.
            let iniciales = [];

            try {
                iniciales = JSON.parse(document.getElementById('precios-cargados').textContent) || [];
            } catch (e) {
                iniciales = [];
            }

            let primeraVez = true;

            const ejes = () => [...ejesCont().querySelectorAll('[data-eje]')]
                .map(i => i.value.trim())
                .filter(Boolean);

            const celda = (tag, idx) => {
                const el = document.createElement(tag);

                el.dataset.ejeIdx = idx;
                el.className = tag === 'th' ? 'pb-2 pr-2' : 'py-1 pr-2';

                return el;
            };

            /*
             * Reconstruye las columnas de eje de la cabecera y de cada fila.
             *
             * Las celdas se identifican por POSICIÓN (data-eje-idx) y no por
             * nombre de eje: así renombrar "Canal" a "Segmento" conserva los
             * valores ya cargados en esa columna en vez de vaciarla.
             */
            const sincronizar = () => {
                const cols = ejes();

                // Cabecera.
                const head = cabecera();
                const anclaHead = head.querySelector('[data-col-precio]');

                head.querySelectorAll('[data-eje-idx]').forEach(th => {
                    if (Number(th.dataset.ejeIdx) >= cols.length) th.remove();
                });

                cols.forEach((nombre, i) => {
                    let th = head.querySelector(`th[data-eje-idx="${i}"]`);

                    if (! th) {
                        th = celda('th', i);
                        head.insertBefore(th, anclaHead);
                    }

                    th.textContent = nombre;
                });

                // Filas.
                [...cuerpo().querySelectorAll('[data-fila]')].forEach((tr, fila) => {
                    const ancla = tr.querySelector('[data-col-precio]');

                    tr.querySelectorAll('[data-eje-idx]').forEach(td => {
                        if (Number(td.dataset.ejeIdx) >= cols.length) td.remove();
                    });

                    cols.forEach((nombre, i) => {
                        let td = tr.querySelector(`td[data-eje-idx="${i}"]`);

                        if (! td) {
                            td = celda('td', i);

                            const input = document.createElement('input');

                            input.className = 'w-36 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
                            input.value = primeraVez ? (iniciales[fila]?.[nombre] ?? '') : '';

                            td.appendChild(input);
                            tr.insertBefore(td, ancla);
                        }

                        td.querySelector('input').name = `precios[${fila}][valores][${nombre}]`;
                        td.querySelector('input').placeholder = nombre;
                    });

                    // Los campos fijos también se renumeran: quitar una fila del
                    // medio dejaría índices con huecos y valores pisados.
                    tr.querySelectorAll('[data-campo]').forEach(i => {
                        i.name = `precios[${fila}][${i.dataset.campo}]`;
                    });
                });

                primeraVez = false;
            };

            document.addEventListener('input', e => {
                if (e.target.matches?.('[data-eje]')) sincronizar();
            });

            document.addEventListener('click', e => {
                const quitarEje = e.target.closest?.('[data-quitar-eje]');

                if (quitarEje) {
                    quitarEje.closest('span').remove();

                    return sincronizar();
                }

                if (e.target.closest?.('#precios-agregar-eje')) {
                    const molde = ejesCont().lastElementChild;

                    if (! molde) return;

                    const nuevo = molde.cloneNode(true);

                    nuevo.querySelector('input').value = '';
                    ejesCont().appendChild(nuevo);

                    return nuevo.querySelector('input').focus();
                }

                const quitarFila = e.target.closest?.('[data-quitar-fila]');

                if (quitarFila) {
                    quitarFila.closest('[data-fila]').remove();

                    return sincronizar();
                }

                if (e.target.closest?.('#precios-agregar-fila')) {
                    const molde = cuerpo().lastElementChild;

                    if (! molde) return;

                    const nueva = molde.cloneNode(true);

                    nueva.querySelectorAll('input').forEach(i => i.value = '');
                    cuerpo().appendChild(nueva);
                    sincronizar();

                    return nueva.querySelector('input')?.focus();
                }
            });

            sincronizar();
        })();
    </script>
@endpush
