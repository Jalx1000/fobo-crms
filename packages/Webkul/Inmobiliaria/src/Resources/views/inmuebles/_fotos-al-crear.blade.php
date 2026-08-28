{{--
    Carga de fotos en el mismo formulario de creación.

    La galería completa —reordenar, elegir portada, borrar— necesita que el
    inmueble exista, así que vive en la edición. Pero obligar a guardar y volver
    a entrar solo para subir la primera foto hacía que los inmuebles quedaran sin
    imágenes, que es justo lo que el agente necesita para mostrarlos.

    Acá se aceptan los archivos y se adjuntan apenas se crea el inmueble.
--}}
<div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-semibold dark:text-white">Fotos</p>
    <p class="mb-3 text-xs text-gray-500">
        Opcional. Podés arrastrarlas acá o elegirlas, y se cargan al guardar. Después, en la edición,
        vas a poder reordenarlas y elegir la portada.
    </p>

    <label id="zona-fotos"
           class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-8 text-center transition hover:border-brandColor dark:border-gray-700">
        <span class="icon-image text-2xl text-gray-400"></span>
        <span class="text-sm text-gray-600 dark:text-gray-300">
            Arrastrá las fotos acá o <span class="text-brandColor">elegilas</span>
        </span>
        <span class="text-xs text-gray-400">JPG, PNG o WEBP · hasta 15 MB cada una · hasta 20 por vez</span>

        <input type="file" name="fotos[]" id="input-fotos" multiple
               accept="image/jpeg,image/png,image/webp" class="hidden" />
    </label>

    <p id="lista-fotos" class="mt-2 hidden text-xs text-gray-600 dark:text-gray-300"></p>
</div>

@push('scripts')
<script>
    /*
     * Arrastrar y soltar sobre el recuadro.
     *
     * Un input de archivos no acepta que le asignen un FileList arbitrario, pero
     * sí uno construido con DataTransfer — que es la forma de que arrastrar y
     * elegir terminen en el mismo lugar y el formulario los envíe igual.
     */
    (function () {
        const zona = document.getElementById('zona-fotos');
        const input = document.getElementById('input-fotos');
        const lista = document.getElementById('lista-fotos');

        if (! zona || ! input) return;

        ['dragenter', 'dragover'].forEach(evento => {
            zona.addEventListener(evento, e => {
                e.preventDefault();
                zona.classList.add('border-brandColor', 'bg-gray-50', 'dark:bg-gray-800');
            });
        });

        ['dragleave', 'drop'].forEach(evento => {
            zona.addEventListener(evento, e => {
                e.preventDefault();
                zona.classList.remove('border-brandColor', 'bg-gray-50', 'dark:bg-gray-800');
            });
        });

        zona.addEventListener('drop', e => {
            const transferencia = new DataTransfer();

            [...input.files, ...e.dataTransfer.files]
                .filter(f => f.type.startsWith('image/'))
                .slice(0, 20)
                .forEach(f => transferencia.items.add(f));

            input.files = transferencia.files;
            mostrar();
        });

        input.addEventListener('change', mostrar);

        function mostrar() {
            const n = input.files.length;

            lista.classList.toggle('hidden', n === 0);
            lista.textContent = n === 0
                ? ''
                : `${n} foto${n > 1 ? 's' : ''} lista${n > 1 ? 's' : ''} para subir: `
                  + [...input.files].map(f => f.name).join(', ');
        }
    })();
</script>
@endpush
