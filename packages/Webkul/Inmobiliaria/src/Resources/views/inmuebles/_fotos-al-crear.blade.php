{{--
    Carga de fotos junto con el alta.

    La galería completa —reordenar, portada, borrar— necesita el inmueble ya
    creado, así que vive en la edición. Pero obligar a guardar y volver a entrar
    solo para subir la primera foto hacía que los inmuebles quedaran sin
    imágenes, que es justo lo que el agente necesita para mostrarlos.
--}}
<div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-semibold dark:text-white">Fotos</p>
    <p class="mb-3 text-xs text-gray-500">
        Opcional. Se cargan al guardar; después, en la edición, las reordenás y elegís la portada.
    </p>

    @include('inmobiliaria::inmuebles._dropzone', [
        'nombre' => 'fotos[]',
        'id'     => 'input-fotos-alta',
        'acepta' => 'image/jpeg,image/png,image/webp',
        'ayuda'  => 'JPG, PNG o WEBP · hasta 15 MB cada una · hasta 20 por vez',
    ])
</div>
