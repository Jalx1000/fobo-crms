{{--
    Área para cargar archivos: toda la tarjeta es el control.

    El input vive FUERA del label y se asocia con `for`. La asociación explícita
    funciona en todos los navegadores; la implícita —el input adentro— es la que
    antes no abría el selector al hacer clic.

    Sin JavaScript la tarjeta sigue abriendo el selector, porque eso lo resuelve
    el propio HTML. Arrastrar es lo que se agrega encima.

    Parámetros: $nombre, $id, $acepta, $ayuda, y opcional $subirA (+ $subirTipo)
    para que soltar suba directo en vez de esperar al botón del formulario.
--}}
@php
    $id = $id ?? 'archivos-'.\Illuminate\Support\Str::random(6);
@endphp

<input type="file"
       name="{{ $nombre }}"
       id="{{ $id }}"
       multiple
       accept="{{ $acepta }}"
       class="sr-only" />

<label for="{{ $id }}"
       data-dropzone
       @isset($subirA) data-subir-a="{{ $subirA }}" data-dropzone-tipo="{{ $subirTipo ?? 'foto' }}" @endisset
       class="flex w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-6 py-10 text-center transition hover:border-brandColor hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">

    <span class="icon-image text-3xl text-gray-400"></span>

    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
        Arrastrá los archivos acá o <span class="text-brandColor underline">hacé clic para elegirlos</span>
    </span>

    <span class="text-xs text-gray-400">{{ $ayuda }}</span>

    <span data-dropzone-lista class="hidden text-xs font-medium text-gray-700 dark:text-gray-200"></span>
</label>

@once
    @push('scripts')
    <script>
        (function () {
            const CSRF = @json(csrf_token());

            document.querySelectorAll('[data-dropzone]').forEach(zona => {
                const input = document.getElementById(zona.getAttribute('for'));
                const lista = zona.querySelector('[data-dropzone-lista]');

                if (! input) return;

                const encender = () => zona.classList.add('border-brandColor', 'bg-gray-50', 'dark:bg-gray-800');
                const apagar   = () => zona.classList.remove('border-brandColor', 'bg-gray-50', 'dark:bg-gray-800');

                ['dragenter', 'dragover'].forEach(evento =>
                    zona.addEventListener(evento, e => { e.preventDefault(); e.stopPropagation(); encender(); })
                );

                ['dragleave', 'dragend'].forEach(evento =>
                    zona.addEventListener(evento, e => { e.preventDefault(); apagar(); })
                );

                zona.addEventListener('drop', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    apagar();

                    const sueltos = [...e.dataTransfer.files];

                    if (! sueltos.length) return;

                    // Si el área sabe a dónde subir, sube al soltar. No depende de
                    // DataTransfer, que Safari soporta recién desde 14.1.
                    if (zona.dataset.subirA) {
                        subir(zona, lista, sueltos);

                        return;
                    }

                    // Camino del alta: los archivos viajan con el formulario, así
                    // que hay que meterlos en el input.
                    try {
                        const dt = new DataTransfer();

                        // Soltar dos veces suma, no reemplaza.
                        [...input.files, ...sueltos].slice(0, 20).forEach(f => dt.items.add(f));
                        input.files = dt.files;
                        mostrar();
                    } catch (error) {
                        avisar(lista, 'Tu navegador no permite soltar archivos. Hacé clic para elegirlos.');
                    }
                });

                input.addEventListener('change', mostrar);

                function mostrar() {
                    const n = input.files.length;

                    if (! n) return lista.classList.add('hidden');

                    avisar(lista, `${n} archivo${n > 1 ? 's' : ''}: ` + [...input.files].map(f => f.name).join(', '));
                }
            });

            function avisar(lista, texto) {
                lista.textContent = texto;
                lista.classList.remove('hidden');
            }

            /**
             * Sube lo soltado sin esperar a ningún botón.
             *
             * El token va explícito: este admin no tiene <meta name="csrf-token">,
             * así que leerlo de ahí devuelve null y la petición nunca sale.
             */
            function subir(zona, lista, archivos) {
                const datos = new FormData();

                archivos.slice(0, 20).forEach(f => datos.append('archivos[]', f));
                datos.append('tipo', zona.dataset.dropzoneTipo || 'foto');

                avisar(lista, `Subiendo ${archivos.length} archivo(s)…`);

                window.axios.post(zona.dataset.subirA, datos, { headers: { 'X-CSRF-TOKEN': CSRF } })
                    .then(() => location.reload())
                    .catch(err => avisar(lista, err.response?.data?.message || 'No se pudieron subir los archivos.'));
            }
        })();
    </script>
    @endpush
@endonce
