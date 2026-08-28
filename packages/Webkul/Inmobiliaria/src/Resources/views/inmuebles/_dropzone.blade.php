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
        /*
         * Los eventos se delegan en `document`, no se enganchan al elemento.
         *
         * Esta página monta Vue sobre todo el #app y el bundle es un módulo, así
         * que corre DESPUÉS de este script: al compilar la plantilla, Vue
         * reemplaza los nodos y se lleva puestos los listeners enganchados
         * directamente. Por eso los `onclick` en atributos seguían andando y
         * nada registrado con addEventListener respondía.
         *
         * Delegando en document el listener vive en un nodo que Vue no toca, y
         * funciona con los elementos que existan en cada momento.
         */
        (function () {
            const CSRF = @json(csrf_token());

            const zonaDe = e => e.target.closest?.('[data-dropzone]');
            const pintar = (zona, activa) => zona.classList[activa ? 'add' : 'remove'](
                'border-brandColor', 'bg-gray-50', 'dark:bg-gray-800'
            );

            ['dragenter', 'dragover'].forEach(evento =>
                document.addEventListener(evento, e => {
                    const zona = zonaDe(e);

                    if (! zona) return;

                    // Sin preventDefault el navegador no admite soltar acá y, al
                    // soltar, abre el archivo en una pestaña.
                    e.preventDefault();
                    pintar(zona, true);
                })
            );

            document.addEventListener('dragleave', e => {
                const zona = zonaDe(e);

                if (zona && ! zona.contains(e.relatedTarget)) pintar(zona, false);
            });

            document.addEventListener('drop', e => {
                const zona = zonaDe(e);

                if (! zona) return;

                e.preventDefault();
                pintar(zona, false);

                const sueltos = [...(e.dataTransfer?.files || [])];

                if (! sueltos.length) return;

                const input = document.getElementById(zona.getAttribute('for'));
                const lista = zona.querySelector('[data-dropzone-lista]');

                // Si el área sabe a dónde subir, sube al soltar. No depende de
                // DataTransfer, que Safari soporta recién desde 14.1.
                if (zona.dataset.subirA) return subir(zona, lista, sueltos);

                if (! input) return;

                // Camino del alta: los archivos viajan con el formulario, así que
                // hay que meterlos en el input.
                try {
                    const dt = new DataTransfer();

                    // Soltar dos veces suma, no reemplaza.
                    [...input.files, ...sueltos].slice(0, 20).forEach(f => dt.items.add(f));
                    input.files = dt.files;
                    listar(input, lista);
                } catch (error) {
                    avisar(lista, 'Tu navegador no permite soltar archivos. Hacé clic para elegirlos.');
                }
            });

            document.addEventListener('change', e => {
                if (! e.target.matches?.('input[type="file"]')) return;

                const zona = document.querySelector(`[data-dropzone][for="${e.target.id}"]`);

                if (zona) listar(e.target, zona.querySelector('[data-dropzone-lista]'));
            });

            function listar(input, lista) {
                const n = input.files.length;

                if (! n) return lista?.classList.add('hidden');

                avisar(lista, `${n} archivo${n > 1 ? 's' : ''}: ` + [...input.files].map(f => f.name).join(', '));
            }

            function avisar(lista, texto) {
                if (! lista) return;

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
