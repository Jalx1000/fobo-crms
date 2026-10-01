<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Messenger\Gateways\MessengerGateway;
use Webkul\Messenger\Models\SocialConnection;
use Webkul\Messenger\Services\MessengerProfile;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Messenger as a channel of the shared inbox: the driver translates Meta's
 * payloads into the canonical DTOs, and everything after that is the same
 * pipeline WhatsApp uses.
 *
 * The suite has no dedicated test database, so every test rolls back.
 */
uses(DatabaseTransactions::class);

const PAGE_TOKEN = 'page-token';

const APP_SECRET = 'app-secret';

function messengerGateway(array $overrides = []): MessengerGateway
{
    return new MessengerGateway(array_merge([
        'app_secret'    => APP_SECRET,
        'verify_token'  => 'verify-me',
        'graph_version' => 'v25.0',
        'fetch_profile' => false, // profile lookups are exercised on their own
        'max_media_kb'  => 1024,
    ], $overrides), app(MessengerProfile::class));
}

function connectPage(string $pageId = 'PAGE-1'): SocialConnection
{
    return SocialConnection::create([
        'channel'      => 'messenger',
        'account_id'   => $pageId,
        'name'         => 'Test Page',
        'access_token' => PAGE_TOKEN,
    ]);
}

/**
 * One inbound text event, as Meta delivers it.
 */
function messagingPayload(array $item, string $pageId = 'PAGE-1'): array
{
    return ['entry' => [['id' => $pageId, 'messaging' => [$item]]]];
}

function textEvent(string $text = 'Hola', string $mid = 'mid-1'): array
{
    return [
        'sender'    => ['id' => 'PSID-1'],
        'recipient' => ['id' => 'PAGE-1'],
        'timestamp' => 1700000000000,
        'message'   => ['mid' => $mid, 'text' => $text],
    ];
}

function signatureFor(array $payload): string
{
    return 'sha256='.hash_hmac('sha256', json_encode($payload), APP_SECRET);
}

/*
|--------------------------------------------------------------------------
| Handshake and authentication
|--------------------------------------------------------------------------
*/

it('answers the subscription handshake only with the right verify token', function () {
    $gateway = messengerGateway();

    // Query parameters, not input: Meta calls this with a GET.
    $ok = $gateway->verifyWebhook(Request::create(
        '/api/v1/webhooks/messenger?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=CHALLENGE'
    ));

    expect($ok?->getContent())->toBe('CHALLENGE');

    $wrong = $gateway->verifyWebhook(Request::create(
        '/api/v1/webhooks/messenger?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=CHALLENGE'
    ));

    expect($wrong)->toBeNull();
});

it('rejects a webhook whose body signature does not match', function () {
    config(['whatsapp.gateways.messenger.app_secret' => APP_SECRET]);

    $payload = messagingPayload(textEvent());

    $this->postJson('/api/v1/webhooks/messenger', $payload, [
        'X-Hub-Signature-256' => 'sha256=forged',
    ])->assertStatus(401);
});

it('queues the shared ingest job for a signed delivery', function () {
    Queue::fake();

    config(['whatsapp.gateways.messenger.app_secret' => APP_SECRET]);

    $payload = messagingPayload(textEvent());

    $this->call(
        'POST',
        '/api/v1/webhooks/messenger',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => signatureFor($payload)],
        json_encode($payload),
    )->assertOk();

    // Parsed as Messenger regardless of which WhatsApp provider is the default:
    // that mismatch is what used to send Meta traffic into the wrong driver.
    Queue::assertPushed(IngestInboundPayload::class, fn ($job) => $job->gatewayKey === 'messenger');
});

/*
|--------------------------------------------------------------------------
| Parsing
|--------------------------------------------------------------------------
*/

it('translates an inbound text into a canonical message keyed by PSID', function () {
    connectPage();

    $batch = messengerGateway()->parseWebhook(messagingPayload(textEvent('Hola')));

    expect($batch->messages)->toHaveCount(1);

    $message = $batch->messages[0];

    expect($message->providerMessageId)->toBe('mid-1')
        ->and($message->providerConversationId)->toBe('PSID-1')
        ->and($message->providerAccountId)->toBe('PAGE-1')
        ->and($message->contact->phone)->toBeNull()
        ->and($message->contact->providerId)->toBe('PSID-1')
        ->and($message->type)->toBe('text')
        ->and($message->body)->toBe('Hola')
        ->and($message->direction)->toBe('inbound');
});

it('drops events for a page nobody connected', function () {
    // No SocialConnection row: without the page token we could never reply.
    $batch = messengerGateway()->parseWebhook(messagingPayload(textEvent()));

    expect($batch->messages)->toBeEmpty();
});

it('marks an echo as outbound so it lands as history, not as a customer message', function () {
    connectPage();

    $batch = messengerGateway()->parseWebhook(messagingPayload([
        'sender'    => ['id' => 'PAGE-1'],
        'recipient' => ['id' => 'PSID-1'],
        'message'   => ['mid' => 'mid-echo', 'is_echo' => true, 'text' => 'Respuesta desde la app de Meta'],
    ]));

    expect($batch->messages)->toHaveCount(1)
        ->and($batch->messages[0]->direction)->toBe('outbound')
        ->and($batch->messages[0]->isEcho())->toBeTrue()
        ->and($batch->messages[0]->providerConversationId)->toBe('PSID-1');
});

it('carries an attachment as a downloadable url', function () {
    connectPage();

    $batch = messengerGateway()->parseWebhook(messagingPayload([
        'sender'    => ['id' => 'PSID-1'],
        'recipient' => ['id' => 'PAGE-1'],
        'message'   => ['mid' => 'mid-img', 'attachments' => [[
            'type'    => 'image',
            'payload' => ['url' => 'https://cdn.example.test/photo.jpg'],
        ]]],
    ]));

    expect($batch->messages[0]->type)->toBe('image')
        // Messenger has no media ids: the URL itself is the handle.
        ->and($batch->messages[0]->mediaId)->toBe('https://cdn.example.test/photo.jpg');
});

it('shapes a location pin the way the thread renders it', function () {
    connectPage();

    $batch = messengerGateway()->parseWebhook(messagingPayload([
        'sender'    => ['id' => 'PSID-1'],
        'recipient' => ['id' => 'PAGE-1'],
        'message'   => ['mid' => 'mid-loc', 'attachments' => [[
            'type'    => 'location',
            'title'   => 'Sucursal',
            'payload' => ['coordinates' => ['lat' => -17.78333, 'long' => -63.18214]],
        ]]],
    ]));

    $message = $batch->messages[0];

    expect($message->type)->toBe('location')
        ->and($message->raw['location']['latitude'])->toBe(-17.78333)
        ->and($message->raw['location']['longitude'])->toBe(-63.18214);
});

it('translates delivery receipts into status updates', function () {
    connectPage();

    $batch = messengerGateway()->parseWebhook(messagingPayload([
        'sender'    => ['id' => 'PSID-1'],
        'recipient' => ['id' => 'PAGE-1'],
        'delivery'  => ['mids' => ['mid-1', 'mid-2']],
    ]));

    expect($batch->statuses)->toHaveCount(2)
        ->and($batch->statuses[0]->status)->toBe('delivered');
});

/*
|--------------------------------------------------------------------------
| Persistence
|--------------------------------------------------------------------------
*/

it('stores an inbound message in the shared inbox without faking a phone number', function () {
    connectPage();

    // Contact creation is exercised by the WhatsApp suite; here the point is
    // the shape of the conversation row.
    config(['whatsapp.auto_create_person' => false]);

    $job = new IngestInboundPayload('messenger', messagingPayload(textEvent('Hola')));
    $job->handle(app(GatewayManager::class), app(\Webkul\Whatsapp\Services\ConversationResolver::class));

    $conversation = Conversation::where('gateway', 'messenger')
        ->where('provider_conversation_id', 'PSID-1')
        ->first();

    expect($conversation)->not->toBeNull()
        // The PSID must never be written as a phone: that is what let a
        // Messenger thread collide with a real number in the inbox list.
        ->and($conversation->wa_phone)->toBeNull()
        ->and($conversation->provider_account_id)->toBe('PAGE-1');

    expect(DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->count())->toBe(1);

    // Meta retries webhooks: the same delivery must not duplicate the message.
    $job->handle(app(GatewayManager::class), app(\Webkul\Whatsapp\Services\ConversationResolver::class));

    expect(DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Sending
|--------------------------------------------------------------------------
*/

it('replies through the page the conversation arrived on', function () {
    connectPage('PAGE-1');

    $other = SocialConnection::create([
        'channel'      => 'messenger',
        'account_id'   => 'PAGE-2',
        'access_token' => 'other-page-token',
    ]);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['message_id' => 'mid-out'], 200),
    ]);

    $conversation = (new Conversation)->forceFill([
        'id'                       => 1,
        'gateway'                  => 'messenger',
        'provider_conversation_id' => 'PSID-1',
        'provider_account_id'      => 'PAGE-2',
    ]);

    $result = messengerGateway()->sendText($conversation, 'Hola de vuelta');

    expect($result->providerMessageId)->toBe('mid-out');

    Http::assertSent(function ($request) use ($other) {
        return str_contains($request->url(), '/PAGE-2/messages')
            && $request->hasHeader('Authorization', 'Bearer '.$other->access_token)
            && $request['recipient']['id'] === 'PSID-1';
    });
});

it('refuses to send when the page is not connected', function () {
    $conversation = (new Conversation)->forceFill([
        'id'                       => 1,
        'gateway'                  => 'messenger',
        'provider_conversation_id' => 'PSID-1',
        'provider_account_id'      => 'PAGE-UNKNOWN',
    ]);

    expect(fn () => messengerGateway()->sendText($conversation, 'Hola'))
        ->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| Channel registry
|--------------------------------------------------------------------------
*/

it('registers messenger as its own channel family so threads are never merged', function () {
    $gateways = app(GatewayManager::class);

    expect($gateways->has('messenger'))->toBeTrue()
        ->and($gateways->label('messenger'))->toBe('Messenger')
        ->and($gateways->family('messenger'))->toBe('messenger')
        // A PSID is not a phone number: the de-duplication in the inbox list
        // only ever collapses rows inside the WhatsApp family.
        ->and($gateways->keysInFamily(GatewayManager::DEFAULT_FAMILY))
        ->not->toContain('messenger');
});

/*
|--------------------------------------------------------------------------
| Contacts
|--------------------------------------------------------------------------
*/

it('registers a CRM contact for a Messenger sender that has no phone number', function () {
    config(['whatsapp.auto_create_person' => true]);

    $resolver = app(\Webkul\Whatsapp\Services\ConversationResolver::class);

    // Regression: PersonRepository derived unique_id from contact_numbers[0]
    // unconditionally, so a contact with no phone crashed with "Undefined array
    // key 0" and the first message from every new Messenger sender silently
    // produced no contact at all.
    $person = $resolver->resolveOrCreatePerson(
        new \Webkul\Whatsapp\Gateways\Dto\ContactIdentity(providerId: 'PSID-NEW', name: 'Ada Lovelace'),
        'messenger',
    );

    expect($person)->not->toBeNull()
        ->and($person->name)->toBe('Ada Lovelace')
        // A PSID is not a phone: it must never land where phone matching reads.
        ->and($person->contact_numbers)->toBeEmpty()
        // unique_id is UNIQUE, so a contact with nothing to key on still needs
        // a value of its own rather than an empty string shared with the next.
        ->and($person->unique_id)->not->toBeEmpty();

    $second = $resolver->resolveOrCreatePerson(
        new \Webkul\Whatsapp\Gateways\Dto\ContactIdentity(providerId: 'PSID-OTHER', name: 'Grace Hopper'),
        'messenger',
    );

    expect($second)->not->toBeNull()
        ->and($second->id)->not->toBe($person->id);
});

it('waits for the phone before registering a contact on a phone channel', function () {
    config(['whatsapp.auto_create_person' => true]);

    // Kommo delivers the number in a second call that can fail or lag.
    // Registering someone now would duplicate the contact the moment the real
    // number arrives and matches somebody already in the CRM.
    $person = app(\Webkul\Whatsapp\Services\ConversationResolver::class)->resolveOrCreatePerson(
        new \Webkul\Whatsapp\Gateways\Dto\ContactIdentity(providerId: 'KOMMO-LEAD-1', name: 'Sin teléfono'),
        'kommo',
    );

    expect($person)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Botones
|--------------------------------------------------------------------------
|
| Messenger no tiene el interactivo de WhatsApp, pero sí quick replies. El
| DTO y el controlador del agente no cambian: la traducción vive sola en el
| driver, y la elección vuelve con la misma forma que deja la Cloud API para
| que `selection.id` sirva igual en los dos canales.
*/

it('manda los botones del agente como quick replies', function () {
    connectPage('PAGE-1');

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid-qr'], 200)]);

    $conversation = (new Conversation)->forceFill([
        'id'                       => 1,
        'gateway'                  => 'messenger',
        'provider_conversation_id' => 'PSID-1',
        'provider_account_id'      => 'PAGE-1',
    ]);

    messengerGateway()->sendInteractive($conversation, new OutboundInteractive(
        format: 'button',
        body: '¿Qué te muestro?',
        header: 'Imprimir',
        footer: 'Elegí una opción',
        buttons: [
            ['id' => 'prod:bolsas', 'title' => 'Bolsas'],
            ['id' => 'prod:tapas', 'title' => 'Tapas'],
        ],
    ));

    Http::assertSent(function ($request) {
        return $request['message']['text'] === "Imprimir\n\n¿Qué te muestro?\n\nElegí una opción"
            && $request['message']['quick_replies'] === [
                ['content_type' => 'text', 'title' => 'Bolsas', 'payload' => 'prod:bolsas'],
                ['content_type' => 'text', 'title' => 'Tapas', 'payload' => 'prod:tapas'],
            ];
    });
});

it('aplana las secciones de una lista y recorta los títulos al tope de Messenger', function () {
    connectPage('PAGE-1');

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid-qr'], 200)]);

    $conversation = (new Conversation)->forceFill([
        'id'                       => 1,
        'gateway'                  => 'messenger',
        'provider_conversation_id' => 'PSID-1',
        'provider_account_id'      => 'PAGE-1',
    ]);

    messengerGateway()->sendInteractive($conversation, new OutboundInteractive(
        format: 'list',
        body: 'Nuestros productos',
        listButton: 'Ver productos',
        sections: [
            ['title' => 'Embalaje', 'rows' => [
                // 24 caracteres: válido para WhatsApp, pasado para Messenger.
                ['id' => '1', 'title' => 'Bolsas de alta densidad'],
            ]],
            ['title' => 'Tapas', 'rows' => [
                ['id' => '2', 'title' => 'Tapas'],
            ]],
        ],
    ));

    Http::assertSent(function ($request) {
        $opciones = $request['message']['quick_replies'];

        return count($opciones) === 2
            && mb_strlen($opciones[0]['title']) <= MessengerGateway::MAX_QUICK_REPLY_TITLE
            && $opciones[0]['payload'] === '1'
            && $opciones[1]['title'] === 'Tapas'
            // El botón que abre la lista no va: las opciones ya están a la vista.
            && ! str_contains($request['message']['text'], 'Ver productos');
    });
});

it('anuncia que puede mandar y recibir interactivos', function () {
    expect(messengerGateway()->capabilities()->canSend('interactive'))->toBeTrue();
});

it('devuelve la opción elegida con la misma forma que la Cloud API', function () {
    connectPage('PAGE-1');

    $evento = [
        'sender'    => ['id' => 'PSID-1'],
        'recipient' => ['id' => 'PAGE-1'],
        'timestamp' => 1700000000000,
        'message'   => [
            'mid'         => 'mid-qr-in',
            'text'        => 'Bolsas',
            'quick_reply' => ['payload' => 'prod:bolsas'],
        ],
    ];

    $batch = messengerGateway()->parseWebhook(messagingPayload($evento));

    $mensaje = $batch->messages[0];

    expect($mensaje->type)->toBe('interactive')
        ->and($mensaje->body)->toBe('Bolsas')
        // Es lo que lee AgentPayload::selection() para armar message.selection.
        ->and($mensaje->raw['interactive']['button_reply'])
        ->toBe(['id' => 'prod:bolsas', 'title' => 'Bolsas']);
});
