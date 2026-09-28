{{--
    Códigos por variante: TR4, código de barra, medida, unidad, nombre en el ERP.

    Dependen de ALGUNOS ejes del producto (en bolsas, del tamaño; no del canal
    ni la zona). Primero se marca cuáles de los ejes de precio identifican la
    variante, y la tabla arma una fila por combinación de esos ejes.

    Server-side + script delegado, como la tabla de precios: si el JS no corre,
    el formulario igual manda los inputs y no borra nada.
--}}
@php
    use Webkul\Product\Support\FichaTecnica;
    use Webkul\Product\Support\Variantes;

    $producto = $product ?? null;

    $ejesPrecio = array_values(array_filter((array) old('ejes_precio', $producto?->ejes_precio ?? []), fn ($e) => trim((string) $e) !== ''));

    $ejesIdentidad = old('ejes_identidad', $producto?->ejes_identidad ?? []);
    $ejesIdentidad = array_values(array_filter((array) $ejesIdentidad, fn ($e) => trim((string) $e) !== ''));

    $filas = old('variantes');

    if ($filas === null) {
        $filas = ($producto?->variantes ?? collect())->map(fn ($v) => [
            'valores'      => (array) $v->valores,
            'codigo_tr4'   => $v->codigo_tr4,
            'codigo_barra' => $v->codigo_barra,
            'medida'       => $v->medida,
            'unidad'       => $v->unidad,
            'nombre_erp'   => $v->nombre_erp,
            'caracteristicas' => FichaTecnica::aTexto($v->caracteristicas),
            'tiempo_entrega' => $v->tiempo_entrega,
        ])->all();
    }

    $filas = array_values((array) $filas) ?: [[]];

    $campos = [
        'codigo_tr4'   => ['label' => 'COD TR4',      'ancho' => 'w-32', 'ph' => '020117-0035'],
        'codigo_barra' => ['label' => 'Código de barra', 'ancho' => 'w-36', 'ph' => '7779970880037'],
        'medida'       => ['label' => 'Medida',        'ancho' => 'w-28', 'ph' => '(65×80) cm'],
        'unidad'       => ['label' => 'Unid.',         'ancho' => 'w-16', 'ph' => 'PZA'],
        'nombre_erp'   => ['label' => 'Nombre en ERP', 'ancho' => 'min-w-56', 'ph' => 'BSA MULTIUSO MAGIA VERDE 50L 10UN'],
        // Ficha tecnica: una linea por dato, «Clave: valor». Una linea sin dos
        // puntos es un encabezado («Dimensiones del cono»).
        'caracteristicas' => [
            'label'    => 'Ficha técnica',
            'ancho'    => 'min-w-72',
            'ph'       => "Dimensiones del rollo\nColor: Transparente\nAncho: 500 mm ±5 mm",
            'textarea' => true,
        ],
        'tiempo_entrega' => ['label' => 'Entrega (si difiere)', 'ancho' => 'w-28', 'ph' => 'Ej. 20 días'],
    ];
@endphp

<div class="box-shadow mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.products.variantes.title')
    </p>

    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.variantes.help')
    </p>

    <input type="hidden" name="variantes_enviadas" value="1" />

    {{-- Ejes de identidad: casillas sobre los ejes de precio --}}
    <div class="mb-4">
        <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
            @lang('admin::app.products.variantes.axes')
        </p>

        @if ($ejesPrecio)
            <div id="variantes-ejes" class="flex flex-wrap gap-3">
                @foreach ($ejesPrecio as $eje)
                    <label class="flex cursor-pointer items-center gap-1.5 text-sm dark:text-gray-300">
                        <input
                            type="checkbox"
                            name="ejes_identidad[]"
                            value="{{ $eje }}"
                            data-eje-identidad
                            @checked(in_array($eje, $ejesIdentidad, true))
                        />
                        {{ $eje }}
                    </label>
                @endforeach
            </div>
        @else
            <p class="text-xs italic text-gray-400">@lang('admin::app.products.variantes.no-axes')</p>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="variantes-tabla">
            <thead>
                <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    @foreach ($ejesIdentidad as $i => $eje)
                        <th class="pb-2 pr-2" data-eje-idx="{{ $i }}">{{ $eje }}</th>
                    @endforeach

                    <th class="pb-2 pr-2" data-col-fija>{{ $campos['codigo_tr4']['label'] }}</th>
                    @foreach (array_slice($campos, 1, null, true) as $campo)
                        <th class="pb-2 pr-2">{{ $campo['label'] }}</th>
                    @endforeach
                    <th class="pb-2"></th>
                </tr>
            </thead>

            <tbody id="variantes-filas">
                @foreach ($filas as $i => $fila)
                    <tr data-fila>
                        @foreach ($ejesIdentidad as $j => $eje)
                            <td class="py-1 pr-2" data-eje-idx="{{ $j }}">
                                <input
                                    name="variantes[{{ $i }}][valores][{{ $eje }}]"
                                    value="{{ $fila['valores'][$eje] ?? '' }}"
                                    placeholder="{{ $eje }}"
                                    class="w-28 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                />
                            </td>
                        @endforeach

                        @foreach ($campos as $campo => $def)
                            <td class="py-1 pr-2 align-top" @if ($campo === 'codigo_tr4') data-col-fija @endif>
                                @if (! empty($def['textarea']))
                                    <textarea
                                        name="variantes[{{ $i }}][{{ $campo }}]"
                                        data-campo="{{ $campo }}"
                                        rows="3"
                                        placeholder="{{ $def['ph'] }}"
                                        class="{{ $def['ancho'] }} rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                    >{{ $fila[$campo] ?? '' }}</textarea>
                                @else
                                    <input
                                        name="variantes[{{ $i }}][{{ $campo }}]"
                                        data-campo="{{ $campo }}"
                                        value="{{ $fila[$campo] ?? ($campo === 'unidad' ? 'PZA' : '') }}"
                                        placeholder="{{ $def['ph'] }}"
                                        class="{{ $def['ancho'] }} rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                    />
                                @endif
                            </td>
                        @endforeach

                        <td class="py-1">
                            <button type="button" data-quitar-variante class="icon-delete cursor-pointer rounded p-1.5 text-xl text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="@lang('admin::app.products.variantes.remove-row')"></button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <button type="button" id="variantes-agregar-fila" class="secondary-button mt-3">
        @lang('admin::app.products.variantes.add-row')
    </button>
</div>

@push('scripts')
    <script>
        /*
         * Delegado en document: Vue monta sobre #app despues de este bloque y
         * reemplaza los nodos.
         */
        (function () {
            const cuerpo   = () => document.getElementById('variantes-filas');
            const cabecera = () => document.querySelector('#variantes-tabla thead tr');
            const ejes     = () => [...document.querySelectorAll('[data-eje-identidad]:checked')].map(c => c.value);

            if (! cuerpo()) return;

            const celda = (tag, idx) => {
                const el = document.createElement(tag);
                el.dataset.ejeIdx = idx;
                el.className = tag === 'th' ? 'pb-2 pr-2' : 'py-1 pr-2';
                return el;
            };

            /*
             * Reconstruye las columnas de eje segun las casillas marcadas. Por
             * POSICION, para que cambiar un eje conserve lo ya escrito.
             */
            const sincronizar = () => {
                const cols = ejes();
                const head = cabecera();
                const anclaHead = head.querySelector('[data-col-fija]');

                head.querySelectorAll('[data-eje-idx]').forEach(th => { if (Number(th.dataset.ejeIdx) >= cols.length) th.remove(); });

                cols.forEach((nombre, i) => {
                    let th = head.querySelector(`th[data-eje-idx="${i}"]`);
                    if (! th) { th = celda('th', i); head.insertBefore(th, anclaHead); }
                    th.textContent = nombre;
                });

                [...cuerpo().querySelectorAll('[data-fila]')].forEach((tr, fila) => {
                    const ancla = tr.querySelector('[data-col-fija]');

                    tr.querySelectorAll('[data-eje-idx]').forEach(td => { if (Number(td.dataset.ejeIdx) >= cols.length) td.remove(); });

                    cols.forEach((nombre, i) => {
                        let td = tr.querySelector(`td[data-eje-idx="${i}"]`);

                        if (! td) {
                            td = celda('td', i);
                            const input = document.createElement('input');
                            input.className = 'w-28 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
                            td.appendChild(input);
                            tr.insertBefore(td, ancla);
                        }

                        td.querySelector('input').name = `variantes[${fila}][valores][${nombre}]`;
                        td.querySelector('input').placeholder = nombre;
                    });

                    tr.querySelectorAll('[data-campo]').forEach(i => { i.name = `variantes[${fila}][${i.dataset.campo}]`; });
                });
            };

            document.addEventListener('change', e => { if (e.target.matches?.('[data-eje-identidad]')) sincronizar(); });

            document.addEventListener('click', e => {
                const quitar = e.target.closest?.('[data-quitar-variante]');
                if (quitar) { quitar.closest('[data-fila]').remove(); return sincronizar(); }

                if (e.target.closest?.('#variantes-agregar-fila')) {
                    const molde = cuerpo().lastElementChild;
                    if (! molde) return;
                    const nueva = molde.cloneNode(true);
                    nueva.querySelectorAll('input').forEach(i => { i.value = i.dataset.campo === 'unidad' ? 'PZA' : ''; });
                    // La ficha tecnica es un textarea: `value` no la limpia.
                    nueva.querySelectorAll('textarea').forEach(t => { t.value = ''; });
                    cuerpo().appendChild(nueva);
                    sincronizar();
                    nueva.querySelector('input')?.focus();
                }
            });

            sincronizar();
        })();
    </script>
@endpush
