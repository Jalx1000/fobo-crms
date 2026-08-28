@php
    $porTipo = $inmueble->medias->groupBy('tipo');
    $etiquetas = ['foto' => 'Fotos', 'video' => 'Videos', 'plano' => 'Planos', 'tour' => 'Tours virtuales'];
@endphp

<div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-semibold dark:text-white">Galería</p>
    <p class="mb-4 text-xs text-gray-500">
        Podés subir todas las imágenes que quieras, hasta <b>20 por vez</b>. El agente envía como
        máximo {{ config('inmobiliaria.inmuebles.media_cantidad_max') }} por mensaje para no saturar
        el chat del cliente, empezando por la portada y siguiendo este orden —
        <b>arrastrá las fotos para cambiarlo</b>.
        <br>
        Fotos hasta 15&nbsp;MB · planos (imagen o PDF) hasta 25&nbsp;MB · videos hasta 200&nbsp;MB.
    </p>

    <p id="aviso-orden" class="mb-2 hidden text-xs font-medium text-emerald-600"></p>

    <form method="POST" action="{{ route('admin.inmuebles.medias.store', $inmueble->id) }}"
          enctype="multipart/form-data" class="mb-6 flex flex-wrap items-end gap-3">
        @csrf

        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium dark:text-gray-300">Tipo</span>
            <select name="tipo" id="tipo-media" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    onchange="document.getElementById('campo-url').classList.toggle('hidden', this.value !== 'tour');
                              document.getElementById('campo-archivos').classList.toggle('hidden', this.value === 'tour');
                              document.getElementById('input-archivos').accept = {
                                  foto:  'image/jpeg,image/png,image/webp',
                                  plano: 'image/jpeg,image/png,image/webp,application/pdf',
                                  video: 'video/mp4,video/quicktime,video/webm',
                                  tour:  ''
                              }[this.value];">
                <option value="foto">Fotos</option>
                <option value="plano">Planos</option>
                <option value="video">Video</option>
                <option value="tour">Tour virtual (URL)</option>
            </select>
        </label>

        <label class="flex flex-col gap-1" id="campo-archivos">
            <span class="text-xs font-medium dark:text-gray-300">Archivos</span>
            <input type="file" name="archivos[]" id="input-archivos" multiple
                   accept="image/jpeg,image/png,image/webp"
                   class="rounded border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
        </label>

        <label class="hidden flex-col gap-1" id="campo-url">
            <span class="text-xs font-medium dark:text-gray-300">URL del tour</span>
            <input name="url" placeholder="https://…"
                   class="w-80 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" />
        </label>

        <button type="submit" class="primary-button">Subir</button>
    </form>

    @forelse ($porTipo as $tipo => $archivos)
        <p class="mb-2 mt-4 text-sm font-semibold dark:text-gray-200">
            {{ $etiquetas[$tipo] ?? ucfirst($tipo) }}
            <span class="font-normal text-gray-500">({{ $archivos->count() }})</span>
        </p>

        <div class="grid grid-cols-4 gap-3 max-sm:grid-cols-2"
             data-galeria="{{ $tipo }}"
             data-url-orden="{{ route('admin.inmuebles.medias.orden', $inmueble->id) }}">
            @foreach ($archivos as $media)
                <div class="group relative cursor-move overflow-hidden rounded border border-gray-200 transition dark:border-gray-800"
                     draggable="true"
                     data-media="{{ $media->id }}">
                    @if ($media->tipo === 'tour')
                        <a href="{{ $media->url }}" target="_blank" rel="noopener"
                           class="block truncate p-3 text-xs text-blue-600 dark:text-blue-400">{{ $media->url }}</a>
                    @elseif ($media->tipo === 'video')
                        <video src="{{ route('admin.inmuebles.medias.archivo', $media->id) }}" controls class="h-32 w-full object-cover"></video>
                    @else
                        <img src="{{ route('admin.inmuebles.medias.archivo', $media->id) }}"
                             alt="{{ $media->caption ?? $inmueble->titulo }}" class="h-32 w-full object-cover" />
                    @endif

                    @if ($media->es_portada)
                        <span class="absolute left-1 top-1 rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] text-white">Portada</span>
                    @endif

                    {{-- Señal de que se puede arrastrar: sin esto nadie descubre
                         que el orden se cambia moviendo las fotos. --}}
                    <span class="absolute right-1 top-1 rounded bg-black/50 px-1.5 py-0.5 text-[10px] text-white opacity-0 transition group-hover:opacity-100">
                        ⠿ arrastrá
                    </span>

                    <div class="flex items-center justify-between border-t border-gray-200 px-2 py-1 dark:border-gray-800">
                        @if ($media->tipo === 'foto' && ! $media->es_portada)
                            <button type="button" class="text-xs text-blue-600 dark:text-blue-400"
                                    onclick="mediaAccion('{{ route('admin.inmuebles.medias.portada', [$inmueble->id, $media->id]) }}', 'PATCH')">
                                Hacer portada
                            </button>
                        @else
                            <span></span>
                        @endif

                        <button type="button" class="text-xs text-red-600 dark:text-red-400"
                                onclick="if (confirm('¿Eliminar este archivo?')) mediaAccion('{{ route('admin.inmuebles.medias.destroy', [$inmueble->id, $media->id]) }}', 'DELETE')">
                            Eliminar
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @empty
        <div class="rounded border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700">
            Todavía no hay archivos. Sin fotos, el agente puede describir el inmueble pero no mostrarlo.
        </div>
    @endforelse
</div>

@push('scripts')
<script>
    /*
     * Reordenar la galería arrastrando.
     *
     * El orden importa de verdad: es el que sigue el agente al mandarle fotos al
     * cliente, y como manda unas pocas, las primeras son las que deciden si
     * pide una visita. Ponerlo detrás de un formulario de números lo volvía
     * trabajo tedioso que nadie hacía.
     *
     * Se guarda apenas se suelta, sin botón de confirmar: es una acción de bajo
     * riesgo y perfectamente reversible arrastrando de nuevo.
     */
    (function () {
        let arrastrado = null;

        document.querySelectorAll('[data-galeria]').forEach(galeria => {
            galeria.addEventListener('dragstart', e => {
                arrastrado = e.target.closest('[data-media]');
                if (arrastrado) arrastrado.style.opacity = '0.4';
            });

            galeria.addEventListener('dragend', () => {
                if (arrastrado) arrastrado.style.opacity = '';
                arrastrado = null;
            });

            galeria.addEventListener('dragover', e => {
                e.preventDefault();     // sin esto el navegador no permite soltar

                const sobre = e.target.closest('[data-media]');
                if (! sobre || ! arrastrado || sobre === arrastrado) return;
                if (sobre.parentElement !== arrastrado.parentElement) return;

                // Insertar antes o después según de qué lado se está soltando,
                // para que el reordenamiento siga al cursor.
                const caja = sobre.getBoundingClientRect();
                const despues = (e.clientX - caja.left) > caja.width / 2;

                sobre.parentElement.insertBefore(arrastrado, despues ? sobre.nextSibling : sobre);
            });

            galeria.addEventListener('drop', e => {
                e.preventDefault();
                guardarOrden(galeria);
            });
        });

        function guardarOrden(galeria) {
            const orden = [...galeria.querySelectorAll('[data-media]')].map(el => el.dataset.media);
            const aviso = document.getElementById('aviso-orden');

            fetch(galeria.dataset.urlOrden, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ orden }),
            })
            .then(r => {
                if (! r.ok) throw new Error();
                aviso.textContent = 'Orden guardado.';
                aviso.classList.remove('hidden', 'text-red-600');
                aviso.classList.add('text-emerald-600');
            })
            .catch(() => {
                // Se avisa en vez de fallar en silencio: el asesor acaba de ver
                // moverse las fotos y creería que quedó guardado.
                aviso.textContent = 'No se pudo guardar el orden. Recargá y probá de nuevo.';
                aviso.classList.remove('hidden', 'text-emerald-600');
                aviso.classList.add('text-red-600');
            })
            .finally(() => setTimeout(() => aviso.classList.add('hidden'), 2500));
        }
    })();

    function mediaAccion(url, metodo) {
        fetch(url, {
            method: metodo,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        }).then(r => r.ok ? location.reload() : alert('No se pudo completar la acción.'));
    }
</script>
@endpush
