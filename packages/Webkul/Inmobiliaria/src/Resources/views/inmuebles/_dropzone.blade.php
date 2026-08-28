{{--
    Área para soltar archivos.

    Se usa en el alta y en la edición para que subir fotos se haga igual en los
    dos lados. Sigue habiendo un input de archivos real adentro: arrastrar es un
    atajo, no el único camino — con el teclado o desde el móvil se abre el
    selector de siempre.

    Parámetros: $nombre (name del input), $id, $acepta, $ayuda
--}}
@php
    $id = $id ?? 'dropzone-'.\Illuminate\Support\Str::random(6);
@endphp

<label data-dropzone
       @isset($subirA) data-subir-a="{{ $subirA }}" data-dropzone-tipo="{{ $subirTipo ?? 'foto' }}" @endisset
       class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-8 text-center transition hover:border-brandColor focus-within:border-brandColor dark:border-gray-700">
    <span class="icon-image text-2xl text-gray-400"></span>

    <span class="text-sm text-gray-600 dark:text-gray-300">
        Arrastrá los archivos acá o <span class="text-brandColor underline">elegilos</span>
    </span>

    <span class="text-xs text-gray-400">{{ $ayuda }}</span>

    <input type="file"
           name="{{ $nombre }}"
           id="{{ $id }}"
           multiple
           accept="{{ $acepta }}"
           class="sr-only" />

    <span data-dropzone-lista class="hidden text-xs font-medium text-gray-700 dark:text-gray-200"></span>
</label>

@once
    @push('scripts')
    <script>
        /*
         * Cablea todas las áreas de la página.
         *
         * Un input de archivos no acepta que le asignen un FileList arbitrario,
         * pero sí uno construido con DataTransfer: es lo que permite que soltar
         * y elegir terminen en el mismo lugar y el formulario los envíe igual.
         */
        (function () {
            document.querySelectorAll('[data-dropzone]').forEach(zona => {
                const input = zona.querySelector('input[type="file"]');
                const lista = zona.querySelector('[data-dropzone-lista]');

                if (! input) return;

                ['dragenter', 'dragover'].forEach(evento =>
                    zona.addEventListener(evento, e => {
                        e.preventDefault();
                        e.stopPropagation();
                        zona.classList.add('border-brandColor', 'bg-gray-50', 'dark:bg-gray-800');
                    })
                );

                ['dragleave', 'dragend'].forEach(evento =>
                    zona.addEventListener(evento, e => {
                        e.preventDefault();
                        zona.classList.remove('border-brandColor', 'bg-gray-50', 'dark:bg-gray-800');
                    })
                );

                zona.addEventListener('drop', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    zona.classList.remove('border-brandColor', 'bg-gray-50', 'dark:bg-gray-800');

                    const sueltos = [...e.dataTransfer.files];

                    if (! sueltos.length) return;

                    /*
                     * Si el área declara a dónde subir, se sube al soltar y listo.
                     *
                     * Es el camino de la edición, y no depende de DataTransfer
                     * —que Safari soporta recién desde 14.1— ni de poder
                     * asignarle un FileList a un input, que es justamente lo que
                     * no funciona en los navegadores viejos.
                     */
                    if (zona.dataset.subirA) {
                        subirAhora(zona, sueltos);

                        return;
                    }

                    // Camino del alta: los archivos viajan con el formulario, así
                    // que hay que meterlos en el input. Acá sí hace falta
                    // DataTransfer; si el navegador no lo tiene, se avisa en vez
                    // de no hacer nada.
                    try {
                        const transferencia = new DataTransfer();

                        // Soltar dos veces suma, no reemplaza: es lo que uno
                        // espera al ir agregando fotos de a poco.
                        [...input.files, ...sueltos]
                            .slice(0, 20)
                            .forEach(f => transferencia.items.add(f));

                        input.files = transferencia.files;
                        mostrar();
                    } catch (error) {
                        lista.classList.remove('hidden');
                        lista.textContent = 'Tu navegador no permite soltar archivos acá. Tocá el área para elegirlos.';
                    }
                });

                input.addEventListener('change', mostrar);

                function mostrar() {
                    const n = input.files.length;

                    lista.classList.toggle('hidden', n === 0);
                    lista.textContent = n === 0
                        ? ''
                        : `${n} archivo${n > 1 ? 's' : ''} listo${n > 1 ? 's' : ''}: `
                          + [...input.files].map(f => f.name).join(', ');
                }
            });

            /*
             * Sube lo que se soltó, sin esperar a ningún botón.
             *
             * El token va explícito porque este admin no tiene
             * <meta name="csrf-token">: leerlo de ahí devuelve null y la
             * petición nunca sale.
             */
            function subirAhora(zona, archivos) {
                const lista = zona.querySelector('[data-dropzone-lista]');
                const datos = new FormData();

                archivos.slice(0, 20).forEach(f => datos.append('archivos[]', f));
                datos.append('tipo', zona.dataset.dropzoneTipo || 'foto');

                lista.classList.remove('hidden');
                lista.textContent = `Subiendo ${archivos.length} archivo(s)…`;

                window.axios.post(zona.dataset.subirA, datos, {
                    headers: { 'X-CSRF-TOKEN': @json(csrf_token()) },
                })
                    .then(() => location.reload())
                    .catch(err => {
                        lista.textContent = err.response?.data?.message
                            || 'No se pudieron subir los archivos.';
                    });
            }
        })();
    </script>
    @endpush
@endonce
