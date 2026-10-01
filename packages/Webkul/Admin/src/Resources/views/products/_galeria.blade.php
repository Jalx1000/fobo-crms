{{--
    Galería de adjuntos del producto: imágenes, documentos y enlaces.

    Portado de `inmobiliaria::inmuebles._galeria`. Vive solo en la edición
    porque reordenar, marcar portada y borrar necesitan el producto ya creado.
--}}
@php
    use Webkul\Product\Support\Variantes;

    $porTipo = $product->medias->groupBy('tipo');

    // Para asignar cada adjunto a una variante: la ficha técnica de 50 L es de
    // 50 L, no de «Magia Verde». Sin variantes el selector no se muestra.
    $variantes = $product->variantes->map(fn ($v) => [
        'id'       => $v->id,
        'etiqueta' => Variantes::etiqueta($product, $v),
    ]);

    $etiquetas = [
        'imagen'    => trans('admin::app.products.medias.images'),
        'documento' => trans('admin::app.products.medias.documents'),
        'enlace'    => trans('admin::app.products.medias.links'),
    ];
@endphp

<div class="box-shadow mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.products.medias.title')
    </p>

    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.medias.help')
    </p>

    <p id="aviso-orden" class="mb-2 hidden text-xs font-medium text-emerald-600"></p>

    <form
        method="POST"
        action="{{ route('admin.products.medias.store', $product->id) }}"
        enctype="multipart/form-data"
        class="mb-6 flex flex-col gap-3"
    >
        @csrf

        <label class="flex w-56 flex-col gap-1">
            <span class="text-xs font-medium dark:text-gray-300">
                @lang('admin::app.products.medias.type')
            </span>

            <select
                name="tipo"
                id="tipo-media"
                class="cursor-pointer rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            >
                <option value="imagen">@lang('admin::app.products.medias.images')</option>
                <option value="documento">@lang('admin::app.products.medias.documents')</option>
                <option value="enlace">@lang('admin::app.products.medias.link')</option>
            </select>
        </label>

        <div id="campo-archivos">
            @include('admin::products._dropzone', [
                'nombre'    => 'archivos[]',
                'id'        => 'input-archivos',
                'acepta'    => 'image/jpeg,image/png,image/webp',
                'ayuda'     => trans('admin::app.products.medias.upload-hint'),
                // Con esto, soltar sube directo en vez de esperar al botón.
                'subirA'    => route('admin.products.medias.store', $product->id),
                'subirTipo' => 'imagen',
            ])
        </div>

        <label class="hidden flex-col gap-1" id="campo-url">
            <span class="text-xs font-medium dark:text-gray-300">
                @lang('admin::app.products.medias.url')
            </span>

            <input
                name="url"
                placeholder="https://…"
                class="w-80 rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            />
        </label>

        <div class="flex items-center gap-3">
            <button type="submit" class="primary-button">
                @lang('admin::app.products.medias.upload')
            </button>

            <span class="text-xs text-gray-400">
                @lang('admin::app.products.medias.upload-optional')
            </span>
        </div>
    </form>

    @forelse ($porTipo as $tipo => $archivos)
        <p class="mb-2 mt-4 text-sm font-semibold dark:text-gray-200">
            {{ $etiquetas[$tipo] ?? ucfirst($tipo) }}
            <span class="font-normal text-gray-500">({{ $archivos->count() }})</span>
        </p>

        <div
            class="grid grid-cols-4 gap-3 max-sm:grid-cols-2"
            data-galeria="{{ $tipo }}"
        >
            @foreach ($archivos as $media)
                <div
                    class="relative rounded border border-gray-200 p-2 dark:border-gray-800"
                    data-media-id="{{ $media->id }}"
                    draggable="{{ $tipo === 'enlace' ? 'false' : 'true' }}"
                >
                    @if ($tipo === 'imagen')
                        <img
                            src="{{ $media->enlace }}"
                            alt=""
                            class="h-28 w-full rounded object-cover"
                        />
                    @elseif ($tipo === 'documento')
                        <a
                            href="{{ $media->enlace }}"
                            target="_blank"
                            class="flex h-28 items-center justify-center rounded bg-gray-50 text-sm text-blue-600 dark:bg-gray-800"
                        >
                            @lang('admin::app.products.medias.open-document')
                        </a>
                    @else
                        <a
                            href="{{ $media->url }}"
                            target="_blank"
                            class="flex h-28 items-center justify-center break-all rounded bg-gray-50 p-2 text-xs text-blue-600 dark:bg-gray-800"
                        >{{ $media->url }}</a>
                    @endif

                    @if ($variantes->isNotEmpty() && $tipo !== 'enlace')
                        <select
                            data-variante-de="{{ $media->id }}"
                            aria-label="@lang('admin::app.products.medias.variante')"
                            title="@lang('admin::app.products.medias.variante-help')"
                            class="mt-1 w-full rounded border border-gray-200 px-1.5 py-1 text-xs text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                        >
                            <option value="">@lang('admin::app.products.medias.variante-general')</option>

                            @foreach ($variantes as $v)
                                <option value="{{ $v['id'] }}" @selected((int) $media->variante_id === (int) $v['id'])>{{ $v['etiqueta'] }}</option>
                            @endforeach
                        </select>
                    @endif

                    <div class="mt-1 flex items-center justify-between">
                        @if ($tipo === 'imagen')
                            <button
                                type="button"
                                class="text-xs {{ $media->es_portada ? 'font-semibold text-emerald-600' : 'text-gray-500 hover:text-gray-700' }}"
                                data-portada="{{ $media->id }}"
                            >
                                {{ $media->es_portada
                                    ? trans('admin::app.products.medias.is-cover')
                                    : trans('admin::app.products.medias.make-cover') }}
                            </button>
                        @else
                            <span></span>
                        @endif

                        <button
                            type="button"
                            class="text-xs text-red-600 hover:text-red-700"
                            data-borrar="{{ $media->id }}"
                        >
                            @lang('admin::app.products.medias.delete')
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">
            @lang('admin::app.products.medias.empty')
        </p>
    @endforelse
</div>

@push('scripts')
    <script>
        /*
         * Todo delegado en `document`, igual que el dropzone: Vue monta sobre
         * #app DESPUÉS del stack de scripts y reemplaza los nodos, llevándose
         * puestos los listeners enganchados directamente a los elementos.
         */
        (function () {
            // Explícito: este admin NO tiene <meta name="csrf-token">, y leerlo
            // de ahí devuelve null → 419 en cada petición.
            const CSRF = @json(csrf_token());

            const pedir = (url, metodo, cuerpo) => fetch(url, {
                method:  metodo,
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-TOKEN':     CSRF,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: cuerpo ? JSON.stringify(cuerpo) : null,
            });

            // Se arman con el helper de rutas y un id centinela en vez de
            // concatenar strings: si el prefijo del admin cambia, esto sigue
            // apuntando bien.
            const urlPortada = id => @json(route('admin.products.medias.portada', ['id' => $product->id, 'media' => '__ID__'])).replace('__ID__', id);
            const urlBorrar  = id => @json(route('admin.products.medias.destroy', ['id' => $product->id, 'media' => '__ID__'])).replace('__ID__', id);
            const urlOrden   = @json(route('admin.products.medias.orden', $product->id));
            const urlVariante = id => @json(route('admin.products.medias.variante', ['id' => $product->id, 'media' => '__ID__'])).replace('__ID__', id);

            /*
             * El selector de tipo decide qué campo se muestra y qué formatos
             * acepta el input: ofrecer PDF cuando se eligió «imagen» hace que el
             * archivo se rechace recién al enviar.
             */
            const acepta = {
                imagen:    'image/jpeg,image/png,image/webp',
                documento: 'application/pdf,image/jpeg,image/png,image/webp',
                enlace:    '',
            };

            document.addEventListener('change', async e => {
                // Asignar el adjunto a una variante: se guarda al cambiar, sin
                // recargar, y el select mismo confirma (borde verde un momento).
                const sel = e.target.closest?.('[data-variante-de]');

                if (sel) {
                    const r = await pedir(urlVariante(sel.dataset.varianteDe), 'PATCH', { variante_id: sel.value || null });

                    sel.classList.toggle('border-emerald-500', r.ok);
                    sel.classList.toggle('border-red-500', ! r.ok);
                    setTimeout(() => sel.classList.remove('border-emerald-500', 'border-red-500'), 1200);

                    return;
                }

                if (e.target.id !== 'tipo-media') return;

                const esEnlace = e.target.value === 'enlace';

                document.getElementById('campo-url')?.classList.toggle('hidden', ! esEnlace);
                document.getElementById('campo-archivos')?.classList.toggle('hidden', esEnlace);

                const input = document.getElementById('input-archivos');

                if (input) input.accept = acepta[e.target.value];
            });

            document.addEventListener('click', async e => {
                const portada = e.target.closest?.('[data-portada]');

                if (portada) {
                    await pedir(urlPortada(portada.dataset.portada), 'PATCH');

                    return location.reload();
                }

                const borrar = e.target.closest?.('[data-borrar]');

                if (borrar) {
                    if (! confirm(@json(trans('admin::app.products.medias.delete-confirm')))) return;

                    await pedir(urlBorrar(borrar.dataset.borrar), 'DELETE');

                    return location.reload();
                }
            });

            /*
             * Reordenar arrastrando. Se persiste al soltar, no al salir de la
             * página: cerrar la pestaña no debe perder el orden que el asesor ya
             * acomodó.
             */
            let origen = null;

            document.addEventListener('dragstart', e => {
                origen = e.target.closest?.('[data-media-id]');
            });

            document.addEventListener('dragover', e => {
                if (e.target.closest?.('[data-media-id]')) e.preventDefault();
            });

            document.addEventListener('drop', async e => {
                const destino = e.target.closest?.('[data-media-id]');

                if (! destino || ! origen || origen === destino) return;

                const grid = destino.closest('[data-galeria]');

                // Solo dentro de la misma galería: mover una imagen al bloque de
                // documentos mezclaría dos secuencias de orden distintas.
                if (! grid || ! grid.contains(origen)) return;

                e.preventDefault();

                const items = [...grid.children];

                items.indexOf(origen) < items.indexOf(destino)
                    ? destino.after(origen)
                    : destino.before(origen);

                const ids = [...grid.children].map(n => Number(n.dataset.mediaId));

                await pedir(urlOrden, 'PATCH', { ids });

                const aviso = document.getElementById('aviso-orden');

                if (aviso) {
                    aviso.textContent = @json(trans('admin::app.products.medias.order-saved'));
                    aviso.classList.remove('hidden');

                    setTimeout(() => aviso.classList.add('hidden'), 2000);
                }

                origen = null;
            });
        })();
    </script>
@endpush
