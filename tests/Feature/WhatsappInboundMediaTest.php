<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\DownloadInboundMedia;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Descarga de la media que manda el cliente.
 *
 * WhatsApp no manda el archivo en el webhook, solo un id, y ese id CADUCA.
 * Verificado contra la cuenta real: un audio recibido el 31/08 ya no se puede
 * descargar. Por eso el job corre al ingresar el mensaje y no cuando alguien
 * abre la conversación.
 */
uses(DatabaseTransactions::class);

function conversacionEntrante()
{
    return ConversationProxy::modelClass()::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Cliente media',
        'status'     => 'open',
        'ai_enabled' => false,
    ]);
}

it('extrae la referencia del nodo que manda Meta', function () {
    $gateway = app(GatewayManager::class)->active();

    $referencia = $gateway->mediaReference([
        'type'  => 'image',
        'image' => ['id' => '123456', 'mime_type' => 'image/jpeg', 'sha256' => 'x'],
    ]);

    expect($referencia)->toBe(['id' => '123456', 'mime' => 'image/jpeg', 'filename' => null]);
});

it('toma el nombre original de un documento', function () {
    $referencia = app(GatewayManager::class)->active()->mediaReference([
        'type'     => 'document',
        'document' => ['id' => '99', 'mime_type' => 'application/pdf', 'filename' => 'factura.pdf'],
    ]);

    expect($referencia['filename'])->toBe('factura.pdf');
});

it('devuelve null para un mensaje de texto', function () {
    $referencia = app(GatewayManager::class)->active()->mediaReference([
        'type' => 'text',
        'text' => ['body' => 'hola'],
    ]);

    expect($referencia)->toBeNull();
});

it('descarga el archivo y lo deja accesible en el mensaje', function () {
    Storage::fake('public');

    Http::fake([
        'graph.facebook.com/*/778899' => Http::response(['url' => 'https://lookaside.fb/x', 'mime_type' => 'image/jpeg']),
        'lookaside.fb/*'              => Http::response('BYTES-DE-LA-IMAGEN', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $conversacion = conversacionEntrante();

    $mensaje = MessageProxy::modelClass()::create([
        'conversation_id' => $conversacion->id,
        'direction'       => 'inbound',
        'type'            => 'image',
        'status'          => 'delivered',
        'sender'          => 'contact',
        'payload'         => ['type' => 'image', 'image' => ['id' => '778899', 'mime_type' => 'image/jpeg']],
    ]);

    app(DownloadInboundMedia::class, ['messageId' => $mensaje->id])->handle(app(GatewayManager::class));

    $mensaje->refresh();

    expect($mensaje->media_path)->toContain('/whatsapp/media/')
        ->and($mensaje->media_mime)->toBe('image/jpeg')
        ->and($mensaje->media_name)->toBe('Imagen');

    // El archivo quedó guardado con la extensión que le corresponde al mime.
    $guardados = Storage::disk('public')->files('whatsapp/inbound');

    expect($guardados)->toHaveCount(1)
        ->and($guardados[0])->toEndWith('.jpg')
        ->and(Storage::disk('public')->get($guardados[0]))->toBe('BYTES-DE-LA-IMAGEN');
});

it('limpia los parámetros del mime, que Meta manda pegados', function () {
    Storage::fake('public');

    Http::fake([
        'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fb/a']),
        // Así viene una nota de voz: `audio/ogg; codecs=opus`.
        'lookaside.fb/*'       => Http::response('OGG', 200, ['Content-Type' => 'audio/ogg; codecs=opus']),
    ]);

    $mensaje = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionEntrante()->id,
        'direction'       => 'inbound',
        'type'            => 'audio',
        'status'          => 'delivered',
        'sender'          => 'contact',
        'payload'         => ['type' => 'audio', 'audio' => ['id' => '55', 'mime_type' => 'audio/ogg; codecs=opus']],
    ]);

    app(DownloadInboundMedia::class, ['messageId' => $mensaje->id])->handle(app(GatewayManager::class));

    $mensaje->refresh();

    // Sin el `; codecs=opus`: eso va en la cabecera, no en una columna que
    // después se compara y se usa para elegir la extensión.
    expect($mensaje->media_mime)->toBe('audio/ogg')
        ->and($mensaje->media_name)->toBe('Nota de voz')
        ->and(Storage::disk('public')->files('whatsapp/inbound')[0])->toEndWith('.ogg');
});

it('no rompe el mensaje si la referencia ya caducó', function () {
    Storage::fake('public');

    // Es lo que devuelve Meta para un id vencido.
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'expired']], 400)]);

    $mensaje = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionEntrante()->id,
        'direction'       => 'inbound',
        'type'            => 'image',
        'body'            => 'mirá esto',
        'status'          => 'delivered',
        'sender'          => 'contact',
        'payload'         => ['type' => 'image', 'image' => ['id' => 'vencido']],
    ]);

    app(DownloadInboundMedia::class, ['messageId' => $mensaje->id])->handle(app(GatewayManager::class));

    $mensaje->refresh();

    // El mensaje sigue existiendo: se perdió el archivo, no la conversación.
    expect($mensaje->media_path)->toBeNull()
        ->and($mensaje->body)->toBe('mirá esto')
        ->and(Storage::disk('public')->files('whatsapp/inbound'))->toBeEmpty();
});
