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

    <span data-dropzone-aviso class="hidden text-xs font-medium text-gray-700 dark:text-gray-200"></span>
</label>

{{-- Vista previa de lo elegido. Fuera del label: hacer clic en una miniatura no
     debe reabrir el selector de archivos. --}}
<div data-dropzone-preview data-para="{{ $id }}" class="mt-3 hidden flex-wrap gap-2"></div>

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

                // Si el área sabe a dónde subir, sube al soltar. No depende de
                // DataTransfer, que Safari soporta recién desde 14.1.
                if (zona.dataset.subirA) return subir(zona, sueltos);

                if (! input) return;

                // Camino del alta: los archivos viajan con el formulario, así que
                // hay que meterlos en el input.
                try {
                    const dt = new DataTransfer();

                    // Soltar dos veces suma, no reemplaza.
                    [...input.files, ...sueltos].slice(0, 20).forEach(f => dt.items.add(f));
                    input.files = dt.files;
                    listar(input);
                } catch (error) {
                    avisar(zona, 'Tu navegador no permite soltar archivos. Hacé clic para elegirlos.');
                }
            });

            document.addEventListener('change', e => {
                if (! e.target.matches?.('input[type="file"]')) return;

                listar(e.target);
            });

            /**
             * Dibuja las miniaturas de lo que se va a subir.
             *
             * Las URLs de objeto se liberan antes de rehacer la lista: cada una
             * retiene el archivo en memoria hasta que se revoca, y con veinte
             * fotos de varios MB eso se nota.
             */
            function listar(input) {
                const preview = document.querySelector(`[data-dropzone-preview][data-para="${input.id}"]`);

                if (! preview) return;

                preview.querySelectorAll('img[src^="blob:"]').forEach(img => URL.revokeObjectURL(img.src));
                preview.innerHTML = '';

                const archivos = [...input.files];

                preview.classList.toggle('hidden', ! archivos.length);
                preview.classList.toggle('flex', archivos.length > 0);

                archivos.forEach(archivo => preview.appendChild(miniatura(archivo)));
            }

            /**
             * Una miniatura: la imagen si se puede previsualizar, y si no —un PDF,
             * un video— el nombre, que es lo único que ayuda a reconocerlo.
             */
            function miniatura(archivo) {
                const caja = document.createElement('div');

                caja.className = 'relative h-16 w-16 shrink-0 overflow-hidden rounded border border-gray-200 dark:border-gray-700';
                caja.title = `${archivo.name} · ${(archivo.size / 1048576).toFixed(1)} MB`;

                if (archivo.type.startsWith('image/')) {
                    const img = document.createElement('img');

                    img.src = URL.createObjectURL(archivo);
                    img.alt = archivo.name;
                    img.className = 'h-full w-full object-cover';
                    caja.appendChild(img);
                } else {
                    const etiqueta = document.createElement('span');

                    etiqueta.className = 'flex h-full w-full items-center justify-center px-1 text-center text-[9px] leading-tight text-gray-500';
                    etiqueta.textContent = archivo.name;
                    caja.appendChild(etiqueta);
                }

                return caja;
            }

            /**
             * Sube lo soltado sin esperar a ningún botón.
             *
             * El token va explícito: este admin no tiene <meta name="csrf-token">,
             * así que leerlo de ahí devuelve null y la petición nunca sale.
             */
            function subir(zona, archivos) {
                const datos = new FormData();

                archivos.slice(0, 20).forEach(f => datos.append('archivos[]', f));
                datos.append('tipo', zona.dataset.dropzoneTipo || 'foto');

                avisar(zona, `Subiendo ${archivos.length} archivo(s)…`);

                // Se muestran mientras suben: da señal de que algo está pasando.
                const preview = document.querySelector(`[data-dropzone-preview][data-para="${zona.getAttribute('for')}"]`);

                if (preview) {
                    preview.innerHTML = '';
                    preview.classList.remove('hidden');
                    preview.classList.add('flex');
                    archivos.forEach(a => preview.appendChild(miniatura(a)));
                }

                window.axios.post(zona.dataset.subirA, datos, { headers: { 'X-CSRF-TOKEN': CSRF } })
                    .then(() => location.reload())
                    .catch(err => avisar(zona, err.response?.data?.message || 'No se pudieron subir los archivos.'));
            }
        })();
    </script>
    @endpush
@endonce
