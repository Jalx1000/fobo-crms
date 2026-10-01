<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Whatsapp\Exceptions\UnsupportedMessageException;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Kommo\KommoGateway;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Modo mixto: Kommo recibe, Cloud API envía.
 *
 * Sin base de datos a propósito: las conversaciones se arman en memoria y tanto
 * Graph como Kommo están falseados, así que ningún test llega a un proveedor.
 *
 * Spec: docs/specs/kommo-paridad-cloud-api.md (M1, M2).
 */
beforeEach(function () {
    config([
        'whatsapp.gateways.cloud_api.token'           => 'cloud-token',
        'whatsapp.gateways.cloud_api.phone_number_id' => '1111',
        'whatsapp.gateways.cloud_api.api_version'     => 'v21.0',
    ]);

    Http::fake([
        'graph.facebook.com/*/media'    => Http::response(['id' => 'media-1']),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OK']]]),
        '*.kommo.com/*'                 => Http::response([], 200),
    ]);
});

function mixedKommo(string $sendVia = 'cloud_api'): KommoGateway
{
    return new KommoGateway([
        'subdomain'        => 'acme',
        'token'            => 'kommo-token',
        'message_field_id' => 10,
        'bot_id'           => 20,
        'send_via'         => $sendVia,
    ]);
}

function kommoConversation(?string $phone = '59177655508'): Conversation
{
    return (new Conversation)->forceFill([
        'id'                       => 7,
        'gateway'                  => 'kommo',
        'wa_phone'                 => $phone,
        'provider_conversation_id' => '555',
    ]);
}

function sentToGraph(): array
{
    return Http::recorded()
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && str_ends_with($r->url(), '/messages'))
        ->values()
        ->all();
}

function sentToKommo(): int
{
    return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'kommo.com'))->count();
}

it('envía el texto por Cloud API al teléfono de la conversación y nunca por Kommo', function () {
    $result = mixedKommo()->sendText(kommoConversation(), 'Hola');

    expect($result->providerMessageId)->toBe('wamid.OK')
        ->and(sentToKommo())->toBe(0);

    $request = sentToGraph()[0];

    expect($request['to'])->toBe('59177655508')
        ->and($request['type'])->toBe('text')
        ->and($request['text']['body'])->toBe('Hola')
        ->and($request->url())->toContain('/1111/messages');
});

it('envía archivos, ubicación e interactivos por Cloud API', function () {
    Storage::fake('public');
    Storage::disk('public')->put('whatsapp/media/7/cotizacion.pdf', '%PDF');

    $gateway = mixedKommo();
    $conversation = kommoConversation();

    $gateway->sendMedia($conversation, new OutboundMedia(
        type: 'document',
        path: 'whatsapp/media/7/cotizacion.pdf',
        mime: 'application/pdf',
        filename: 'cotizacion.pdf',
    ));

    $gateway->sendLocation($conversation, OutboundLocation::fromArray([
        'latitude' => -17.78, 'longitude' => -63.18, 'name' => 'Sucursal',
    ]));

    $gateway->sendInteractive($conversation, OutboundInteractive::fromArray([
        'format'  => 'button',
        'body'    => '¿Confirmás?',
        'buttons' => [['id' => 'si', 'title' => 'Sí']],
    ]));

    expect(collect(sentToGraph())->pluck('type')->all())->toBe(['document', 'location', 'interactive'])
        ->and(sentToKommo())->toBe(0);
});

it('declara las capacidades de envío de Cloud API, salvo citar un mensaje', function () {
    $capabilities = mixedKommo()->capabilities();

    foreach (['text', 'template', 'image', 'audio', 'video', 'document', 'sticker', 'location', 'interactive'] as $type) {
        expect($capabilities->canSend($type))->toBeTrue("kommo en modo mixto debería poder enviar {$type}");
    }

    expect($capabilities->canSend('reply'))->toBeFalse();
});

it('no cita un mensaje que no es de WhatsApp: Meta rechazaría el envío', function () {
    mixedKommo()->sendText(kommoConversation(), 'Hola', 'kommo-1234');
    mixedKommo()->sendText(kommoConversation(), 'Hola', 'wamid.PREV');

    $requests = sentToGraph();

    expect($requests[0]->data())->not->toHaveKey('context')
        ->and($requests[1]['context']['message_id'])->toBe('wamid.PREV');
});

it('falla con un motivo claro si la conversación todavía no tiene teléfono', function () {
    expect(fn () => mixedKommo()->sendText(kommoConversation(null), 'Hola'))
        ->toThrow(RuntimeException::class, 'no tiene teléfono');

    expect(sentToGraph())->toBeEmpty()
        ->and(sentToKommo())->toBe(0);
});

it('con send_via=kommo vuelve al salesbot y sigue sin enviar archivos', function () {
    $gateway = mixedKommo('kommo');

    $gateway->sendText(kommoConversation(), 'Hola');

    expect(sentToKommo())->toBe(2) // PATCH del campo + POST del salesbot
        ->and(sentToGraph())->toBeEmpty()
        ->and($gateway->capabilities()->canSend('image'))->toBeFalse();

    Storage::fake('public');

    expect(fn () => $gateway->sendMedia(kommoConversation(), new OutboundMedia(
        type: 'image', path: 'x.jpg', mime: 'image/jpeg', filename: 'x.jpg',
    )))->toThrow(UnsupportedMessageException::class);
});
