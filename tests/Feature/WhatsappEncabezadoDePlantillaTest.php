<?php

use Webkul\Whatsapp\Http\Controllers\InboxController;
use Webkul\Whatsapp\Models\Message;

/*
 * El hilo muestra el archivo con el que salió una plantilla. Sólo arma el mensaje en
 * memoria, sin tocar la base.
 */
function mensajeDelHilo(array $atributos): array
{
    $mensaje = (new Message)->forceFill($atributos);

    $transform = new ReflectionMethod(InboxController::class, 'transform');
    $transform->setAccessible(true);

    return $transform->invoke(app(InboxController::class), $mensaje);
}

it('una plantilla con video expone el video para verlo en el hilo', function () {
    $mensaje = mensajeDelHilo(['type' => 'template', 'payload' => [
        'template' => 'bienvenida_sensia_video',
        'header'   => ['format' => 'video', 'link' => 'https://sensia.test/bienvenida.mp4'],
    ]]);

    expect($mensaje['encabezado'])->toBe(['format' => 'VIDEO', 'url' => 'https://sensia.test/bienvenida.mp4']);
});

it('una plantilla sin archivo no tiene encabezado', function () {
    expect(mensajeDelHilo(['type' => 'template', 'payload' => ['template' => 'bienvenida_sensia']])['encabezado'])->toBeNull();
});

it('un mensaje que no es plantilla no tiene encabezado', function () {
    expect(mensajeDelHilo(['type' => 'text', 'payload' => ['header' => ['link' => 'https://x.test/a.mp4']]])['encabezado'])->toBeNull();
});
