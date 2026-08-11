<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Whatsapp\Exceptions\AttachmentStorageException;
use Webkul\Whatsapp\Exceptions\UnsupportedMessageException;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Kommo\KommoGateway;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\OutboundAttachment;

/**
 * Outbound attachments and location pins over the Cloud API driver. Everything
 * here talks to a faked Graph, so no test ever reaches Meta.
 */
function cloudGateway(): CloudApiGateway
{
    return new CloudApiGateway([
        'token'           => 'test-token',
        'phone_number_id' => '1234567890',
        'api_version'     => 'v21.0',
    ]);
}

function fakeConversation(): Conversation
{
    // Never saved: the driver only reads wa_phone off it.
    return (new Conversation)->forceFill(['id' => 1, 'wa_phone' => '59175640698']);
}

function stageMedia(string $type, string $name, string $contents = 'binary'): OutboundMedia
{
    Storage::fake('public');
    Storage::disk('public')->put('whatsapp/media/1/'.$name, $contents);

    return new OutboundMedia(
        type: $type,
        path: 'whatsapp/media/1/'.$name,
        mime: 'image/jpeg',
        filename: $name,
        caption: null,
    );
}

it('uploads the file first and then references the returned media id', function () {
    Http::fake([
        '*/1234567890/media'    => Http::response(['id' => 'MEDIA-999'], 200),
        '*/1234567890/messages' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200),
    ]);

    $result = cloudGateway()->sendMedia(fakeConversation(), stageMedia('image', 'foto.jpg'));

    expect($result->providerMessageId)->toBe('wamid.ABC');

    // Two calls, in order: upload then send.
    $sent = Http::recorded();
    expect($sent)->toHaveCount(2)
        ->and($sent[0][0]->url())->toContain('/media')
        ->and($sent[1][0]->url())->toContain('/messages');

    $payload = $sent[1][0]->data();
    expect($payload['type'])->toBe('image')
        ->and($payload['image']['id'])->toBe('MEDIA-999');
});

it('sends a document with its original filename so the recipient sees it', function () {
    Http::fake([
        '*/media'    => Http::response(['id' => 'MEDIA-1'], 200),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.DOC']]], 200),
    ]);

    $media = stageMedia('document', 'cotizacion.pdf');
    $media->caption = 'Te paso la cotización';

    cloudGateway()->sendMedia(fakeConversation(), $media);

    $payload = Http::recorded()[1][0]->data();

    expect($payload['document']['filename'])->toBe('cotizacion.pdf')
        ->and($payload['document']['caption'])->toBe('Te paso la cotización');
});

it('never puts a caption on audio, which Meta rejects outright', function () {
    Http::fake([
        '*/media'    => Http::response(['id' => 'MEDIA-2'], 200),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.AUD']]], 200),
    ]);

    $media = stageMedia('audio', 'nota.ogg');
    $media->caption = 'esto no puede viajar acá';

    cloudGateway()->sendMedia(fakeConversation(), $media);

    expect(Http::recorded()[1][0]->data()['audio'])->not->toHaveKey('caption');
});

it('fails loudly when the staged file is gone instead of uploading nothing', function () {
    Storage::fake('public');

    $media = new OutboundMedia(type: 'image', path: 'whatsapp/media/1/desaparecido.jpg');

    expect(fn () => cloudGateway()->sendMedia(fakeConversation(), $media))
        ->toThrow(RuntimeException::class, 'ya no está disponible');
});

it('sends a location with name and address, dropping the empty optionals', function () {
    Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.LOC']]], 200)]);

    cloudGateway()->sendLocation(fakeConversation(), new OutboundLocation(
        latitude: -17.78333,
        longitude: -63.18214,
        name: 'Sucursal Central',
        address: null,
    ));

    $payload = Http::recorded()[0][0]->data();

    expect($payload['type'])->toBe('location')
        ->and($payload['location']['name'])->toBe('Sucursal Central')
        ->and($payload['location'])->not->toHaveKey('address')
        ->and($payload['location']['latitude'])->toBe('-17.78333');
});

it('carries the reply context so attachments can quote a message', function () {
    Http::fake([
        '*/media'    => Http::response(['id' => 'MEDIA-3'], 200),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.RE']]], 200),
    ]);

    cloudGateway()->sendMedia(fakeConversation(), stageMedia('image', 'x.jpg'), 'wamid.ORIGINAL');

    expect(Http::recorded()[1][0]->data()['context']['message_id'])->toBe('wamid.ORIGINAL');
});

it('refuses media on a gateway that only relays text', function () {
    $kommo = new KommoGateway(['subdomain' => 'x', 'token' => 'y']);

    expect(fn () => $kommo->sendMedia(fakeConversation(), stageMedia('image', 'x.jpg')))
        ->toThrow(UnsupportedMessageException::class);

    expect($kommo->capabilities()->canSend('image'))->toBeFalse();
});

it('declares every type it can actually send', function () {
    $capabilities = cloudGateway()->capabilities();

    foreach (['image', 'audio', 'video', 'document', 'sticker', 'location'] as $type) {
        expect($capabilities->canSend($type))->toBeTrue("cloud_api debería poder enviar {$type}");
    }
});

it('only lets image, video and document carry a caption', function () {
    $attachments = new OutboundAttachment;

    expect($attachments->planCaption('image', 'hola')['caption'])->toBe('hola')
        ->and($attachments->planCaption('video', 'hola')['caption'])->toBe('hola')
        ->and($attachments->planCaption('document', 'hola')['caption'])->toBe('hola')
        // Audio and sticker cannot: the caption must not ride along.
        ->and($attachments->planCaption('audio', 'hola')['caption'])->toBeNull()
        ->and($attachments->planCaption('sticker', 'hola')['caption'])->toBeNull();

    expect(Capabilities::isCaptionable('audio'))->toBeFalse();
});

it('sends the advisor text as a follow-up message when the file cannot carry it', function () {
    $attachments = new OutboundAttachment;

    // Audio y sticker no aceptan caption, pero el texto no se descarta: sale
    // como mensaje propio encolado detras del archivo.
    expect($attachments->planCaption('audio', 'hola')['follow_up'])->toBe('hola')
        ->and($attachments->planCaption('sticker', 'hola')['follow_up'])->toBe('hola')
        // Los que si aceptan caption no duplican el texto en un segundo mensaje.
        ->and($attachments->planCaption('image', 'hola')['follow_up'])->toBeNull()
        ->and($attachments->planCaption('document', 'hola')['follow_up'])->toBeNull();
});

it('adds nothing extra when the advisor attaches a file without typing', function () {
    $attachments = new OutboundAttachment;

    expect($attachments->planCaption('audio', null))->toBe(['caption' => null, 'follow_up' => null])
        // Solo espacios equivale a no haber escrito nada.
        ->and($attachments->planCaption('audio', '   '))->toBe(['caption' => null, 'follow_up' => null]);
});

it('refuses to report success when the file could not be written', function () {
    Storage::fake('public');

    // putFileAs returns false on a write failure instead of throwing. Letting
    // that through persisted a message pointing at a file that never existed,
    // and the advisor only found out from the worker seconds later.
    Storage::shouldReceive('disk')->with('public')->andReturnSelf();
    Storage::shouldReceive('putFileAs')->once()->andReturnFalse();

    expect(fn () => (new OutboundAttachment)->stage(UploadedFile::fake()->image('x.png'), 7))
        ->toThrow(AttachmentStorageException::class);
});

it('stages an upload on the public disk and reports both paths', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('diseño.png', 400, 300);

    $staged = (new OutboundAttachment)->stage($file, 42);

    Storage::disk('public')->assertExists($staged['path']);

    expect($staged['path'])->toStartWith('whatsapp/media/42/')
        ->and($staged['name'])->toBe('diseño.png')
        ->and($staged['url'])->toContain($staged['path']);
});
