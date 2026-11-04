{{--
    Carga de imágenes junto con el alta.

    La galería completa -reordenar, portada, borrar, documentos, enlaces-
    necesita el producto ya creado, así que vive en la edición. Pero obligar a
    guardar y volver a entrar solo para subir la primera imagen hacía que los
    productos quedaran sin fotos, que es justo lo que hace falta para poder
    mandarlos por WhatsApp.
--}}
<div class="box-shadow mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.products.medias.images')
    </p>

    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.medias.at-create-help')
    </p>

    @include('admin::products._dropzone', [
        'nombre' => 'imagenes[]',
        'id'     => 'input-imagenes-alta',
        'acepta' => 'image/jpeg,image/png,image/webp',
        'ayuda'  => trans('admin::app.products.medias.at-create-hint'),
    ])
</div>
