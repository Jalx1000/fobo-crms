{{--
    Características propias de la categoría del producto.

    Dos arrays paralelos (clave[]/valor[]) en vez de un mapa: el asesor puede
    agregar filas con claves que no están en la config, y un mapa obligaría a
    conocer todas las claves de antemano — que es justo lo que no se puede.

    Los "sugeridos" son los campos declarados en config/caracteristicas.php para
    la categoría del producto. Son un atajo, no una restricción: guían para que
    dos personas no carguen el mismo dato como «gramaje» y «grs».
--}}
@php
    use Webkul\Product\Support\Caracteristicas;

    $producto = $product ?? null;

    $categorias = (function () use ($producto) {
        $valor = $producto?->{'product_type'} ?? null;

        return array_filter(is_array($valor) ? $valor : (array) $valor);
    })();

    $sugeridos = Caracteristicas::paraCategorias($categorias);

    $cargadas = old('caracteristicas.clave')
        ? array_combine(old('caracteristicas.clave'), old('caracteristicas.valor'))
        : (array) ($producto?->caracteristicas ?? []);
@endphp

<div class="box-shadow mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.products.caracteristicas.title')
    </p>

    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.caracteristicas.help')
    </p>

    {{--
        Marca que este formulario incluye las características.

        Sin esto, cualquier POST a products.update que no las traiga —el editor
        inline de atributos de la vista del producto— se leería como «el asesor
        las borró todas» y las borraría de verdad, en silencio.
    --}}
    <input type="hidden" name="caracteristicas_enviadas" value="1" />

    <div id="caracteristicas" class="flex flex-col gap-2">
        @foreach ($cargadas + ['' => ''] as $clave => $valor)
            <div class="flex items-center gap-2">
                <input
                    name="caracteristicas[clave][]"
                    value="{{ $clave }}"
                    list="caracteristicas-sugeridas"
                    placeholder="@lang('admin::app.products.caracteristicas.key-placeholder')"
                    class="w-1/3 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                />

                <input
                    name="caracteristicas[valor][]"
                    value="{{ is_array($valor) ? implode(', ', $valor) : $valor }}"
                    placeholder="@lang('admin::app.products.caracteristicas.value-placeholder')"
                    class="flex-1 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                />

                <button
                    type="button"
                    data-quitar-caracteristica
                    class="icon-delete cursor-pointer rounded p-1.5 text-xl text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                    title="@lang('admin::app.products.caracteristicas.remove')"
                ></button>
            </div>
        @endforeach
    </div>

    {{-- Los sugeridos alimentan el autocompletado del campo clave. --}}
    <datalist id="caracteristicas-sugeridas">
        @foreach ($sugeridos as $clave => $campo)
            <option value="{{ $clave }}">{{ $campo['label'] ?? $clave }}</option>
        @endforeach
    </datalist>

    <button
        type="button"
        id="agregar-caracteristica"
        class="secondary-button mt-3"
    >
        @lang('admin::app.products.caracteristicas.add')
    </button>

    @if ($sugeridos)
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            @lang('admin::app.products.caracteristicas.suggested'):
            @foreach ($sugeridos as $clave => $campo)
                <button
                    type="button"
                    class="mr-1 cursor-pointer rounded bg-gray-100 px-1.5 py-0.5 text-xs hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                    data-clave="{{ $clave }}"
                >{{ $campo['label'] ?? $clave }}</button>
            @endforeach
        </p>
    @endif
</div>

@push('scripts')
    <script>
        /*
         * Delegado en `document` por la misma razón que el resto: Vue monta
         * sobre #app después del stack de scripts y reemplaza los nodos.
         */
        (function () {
            const cont = () => document.getElementById('caracteristicas');

            const fila = (clave = '') => {
                const c = cont();

                if (! c || ! c.lastElementChild) return null;

                const nueva = c.lastElementChild.cloneNode(true);

                nueva.querySelectorAll('input').forEach(i => i.value = '');

                if (clave) nueva.querySelector('input').value = clave;

                c.appendChild(nueva);

                return nueva;
            };

            document.addEventListener('click', e => {
                // Quitar una fila.
                const quitar = e.target.closest?.('[data-quitar-caracteristica]');

                if (quitar) return quitar.closest('div').remove();

                // Agregar una fila vacía.
                if (e.target.closest?.('#agregar-caracteristica')) {
                    return fila()?.querySelector('input').focus();
                }

                /*
                 * Un sugerido rellena la primera fila vacía en vez de agregar una
                 * nueva: tocar tres sugeridos seguidos no debe dejar huecos.
                 */
                const chip = e.target.closest?.('[data-clave]');

                if (! chip) return;

                const c = cont();

                if (! c) return;

                const vacia = [...c.children].find(f => ! f.querySelector('input').value);

                (vacia || fila())?.querySelector('input').focus();

                (vacia || c.lastElementChild).querySelector('input').value = chip.dataset.clave;
            });
        })();
    </script>
@endpush
