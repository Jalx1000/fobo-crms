<?php

use Illuminate\Support\Facades\Http;
use Webkul\Whatsapp\Gateways\Kommo\KommoGateway;

/**
 * Kommo recibe imágenes, audios, videos, documentos y stickers.
 *
 * Kommo manda el adjunto en `attachment` (link/type/file_name). El link viaja en
 * `mediaId` y downloadMedia() lo baja, el mismo camino que Messenger: la ingesta
 * no cambia. Mapeo de tipos validado contra payloads reales en odontoking
 * (commit 0e85239).
 *
 * Sin base de datos: Kommo está falseado.
 *
 * Spec: docs/specs/kommo-paridad-cloud-api.md (M6).
 */
beforeEach(function () {
    Http::fake([
        // Búsqueda del teléfono del contacto que hace parseWebhook().
        'acme.kommo.com/api/v4/contacts/*' => Http::response([
            'custom_fields_values' => [['field_code' => 'PHONE', 'values' => [['value' => '+59177655508']]]],
        ]),
        'amojo.kommo.com/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
    ]);
});

function receivingKommo(array $config = []): KommoGateway
{
    return new KommoGateway(array_merge(['subdomain' => 'acme', 'token' => 'kommo-token'], $config));
}

function kommoEvent(array $overrides = []): array
{
    return ['message' => ['add' => [array_merge([
        'id'         => 'msg-1',
        'type'       => 'incoming',
        'contact_id' => '42',
        'element_id' => '555',
        'text'       => '',
        'author'     => ['name' => 'Cliente'],
    ], $overrides)]]];
}

it('mapea cada tipo de adjunto de Kommo al vocabulario del inbox', function (string $kommoType, string $expected) {
    $message = receivingKommo()->parseWebhook(kommoEvent([
        'message_type' => $kommoType,
        'attachment'   => [
            'type'      => $kommoType,
            'link'      => 'https://amojo.kommo.com/attachments/abc',
            'file_name' => 'archivo.bin',
        ],
    ]))->messages[0];

    expect($message->type)->toBe($expected)
        ->and($message->mediaId)->toBe('https://amojo.kommo.com/attachments/abc')
        ->and($message->mediaFilename)->toBe('archivo.bin');
})->with([
    ['picture', 'image'],
    ['voice', 'audio'],
    ['audio', 'audio'],
    ['video', 'video'],
    ['file', 'document'],
    ['sticker', 'sticker'],
]);

it('conserva el pie de foto como cuerpo del mensaje', function () {
    $message = receivingKommo()->parseWebhook(kommoEvent([
        'text'       => 'esta es la muestra',
        'attachment' => ['type' => 'picture', 'link' => 'https://amojo.kommo.com/a/1'],
    ]))->messages[0];

    expect($message->type)->toBe('image')
        ->and($message->body)->toBe('esta es la muestra');
});

it('deja un texto legible para contacto y ubicación, que no traen archivo', function () {
    $contact = receivingKommo()->parseWebhook(kommoEvent(['message_type' => 'contact']))->messages[0];
    $location = receivingKommo()->parseWebhook(kommoEvent(['message_type' => 'location']))->messages[0];

    expect($contact->type)->toBe('text')
        ->and($contact->body)->toBe('[Contacto compartido]')
        ->and($contact->mediaId)->toBeNull()
        ->and($location->body)->toBe('[Ubicación compartida]');
});

it('un texto común sigue entrando como texto', function () {
    $message = receivingKommo()->parseWebhook(kommoEvent(['text' => 'Hola']))->messages[0];

    expect($message->type)->toBe('text')
        ->and($message->body)->toBe('Hola')
        ->and($message->mediaId)->toBeNull()
        ->and($message->contact->phone)->toBe('+59177655508');
});

it('descarga el adjunto desde el link de Kommo', function () {
    $file = receivingKommo()->downloadMedia('https://amojo.kommo.com/attachments/abc');

    expect($file['contents'])->toBe('JPEGBYTES')
        ->and($file['mime'])->toBe('image/jpeg');
});

it('no descarga nada fuera de Kommo: el webhook puede venir sin autenticar', function (string $url) {
    expect(receivingKommo()->downloadMedia($url))->toBeNull();

    Http::assertNotSent(fn ($request) => $request->url() === $url);
})->with([
    'http plano'        => 'http://amojo.kommo.com/a/1',
    'host interno'      => 'https://127.0.0.1/secret',
    'otro dominio'      => 'https://evil.example.com/a.jpg',
    'kommo de sufijo'   => 'https://kommo.com.evil.example/a.jpg',
]);

it('descarta un adjunto más grande que el máximo configurado', function () {
    // Host propio: el stub de beforeEach para amojo ganaría (Http::fake usa el primero que coincide).
    Http::fake(['drive-b.kommo.com/*' => Http::response(str_repeat('x', 2048), 200, ['Content-Type' => 'image/jpeg'])]);

    expect(receivingKommo(['max_media_kb' => 1])->downloadMedia('https://drive-b.kommo.com/a/1'))->toBeNull();
});

it('declara que recibe archivos', function () {
    $receive = receivingKommo()->capabilities()->receive;

    expect($receive)->toContain('text', 'image', 'audio', 'video', 'document', 'sticker');
});
