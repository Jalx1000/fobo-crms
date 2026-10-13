<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

/**
 * CRM-5: estados de entrega de Meta en el modo mixto.
 *
 * Kommo recibe y Cloud API envía, pero Meta no le mandaba nada al CRM: solo 13
 * de 1.251 respuestas del agente figuraban como entregadas, y un envío fallido
 * no se veía. Ahora hay una entrada propia para Cloud API (/api/v1/webhooks/
 * cloud-api). Mientras Kommo sea quien recibe, de ahí solo se toman los estados:
 * los mensajes de los clientes ya entran por Kommo y se duplicarían.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    // Nada sale a la red: ni al agente ni a Meta.
    Illuminate\Support\Facades\Http::fake();

    config([
        'whatsapp.gateway'                         => 'kommo',
        'whatsapp.gateways.cloud_api.app_secret'   => 'secreto-app',
        'whatsapp.gateways.cloud_api.verify_token' => 'token-verificacion',
    ]);

    $this->conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+59170000001', 'wa_name' => 'Estados',
        'ai_enabled' => true, 'status' => 'open', 'last_inbound_at' => now(),
    ]);

    $this->enviado = Message::create([
        'conversation_id' => $this->conv->id, 'direction' => 'outbound', 'type' => 'text', 'sender' => 'ia',
        'status'          => 'sent', 'body' => 'Hola', 'wa_message_id' => 'wamid.TEST'.uniqid(),
    ]);
});

function estadosPayload(array $statuses = [], array $messages = []): array
{
    $value = ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1287390591133408']];

    if ($statuses) {
        $value['statuses'] = $statuses;
    }

    if ($messages) {
        $value['contacts'] = [['wa_id' => '59170000001', 'profile' => ['name' => 'Estados']]];
        $value['messages'] = $messages;
    }

    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1090399237204184', 'changes' => [['field' => 'messages', 'value' => $value]]]]];
}

function estado(string $wamid, string $status, array $extra = []): array
{
    return array_merge(['id' => $wamid, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => '59170000001'], $extra);
}

it('responde la verificación de Meta en la entrada de Cloud API aunque Kommo sea el activo', function () {
    $this->get('/api/v1/webhooks/cloud-api?hub_mode=subscribe&hub_verify_token=token-verificacion&hub_challenge=12345')
        ->assertOk()
        ->assertSee('12345');
});

it('acepta un aviso firmado y lo procesa como Cloud API', function () {
    Queue::fake();

    $cuerpo = json_encode(estadosPayload([estado($this->enviado->wa_message_id, 'delivered')]));

    $this->call('POST', '/api/v1/webhooks/cloud-api', [], [], [], [
        'CONTENT_TYPE'             => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, 'secreto-app'),
    ], $cuerpo)->assertOk();

    Queue::assertPushed(IngestInboundPayload::class, fn ($job) => $job->gatewayKey === 'cloud_api');
});

it('rechaza un aviso sin firma válida', function () {
    Queue::fake();

    $cuerpo = json_encode(estadosPayload([estado($this->enviado->wa_message_id, 'delivered')]));

    $this->call('POST', '/api/v1/webhooks/cloud-api', [], [], [], [
        'CONTENT_TYPE'             => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=falsa',
    ], $cuerpo);

    Queue::assertNothingPushed();
});

it('marca el mensaje como entregado y luego leído', function () {
    (new IngestInboundPayload('cloud_api', estadosPayload([estado($this->enviado->wa_message_id, 'delivered')])))
        ->handle(app(Webkul\Whatsapp\Gateways\GatewayManager::class), app(Webkul\Whatsapp\Services\ConversationResolver::class));

    expect($this->enviado->fresh()->status)->toBe('delivered');

    (new IngestInboundPayload('cloud_api', estadosPayload([estado($this->enviado->wa_message_id, 'read')])))
        ->handle(app(Webkul\Whatsapp\Gateways\GatewayManager::class), app(Webkul\Whatsapp\Services\ConversationResolver::class));

    expect($this->enviado->fresh()->status)->toBe('read');
});

it('en modo mixto no registra los mensajes de clientes que llegan por Cloud API: ya entran por Kommo', function () {
    $antes = Message::count();

    (new IngestInboundPayload('cloud_api', estadosPayload([], [[
        'from' => '59170000001', 'id' => 'wamid.IN'.uniqid(), 'timestamp' => (string) time(),
        'type' => 'text', 'text' => ['body' => 'Hola, quiero cotizar'],
    ]])))->handle(app(Webkul\Whatsapp\Gateways\GatewayManager::class), app(Webkul\Whatsapp\Services\ConversationResolver::class));

    expect(Message::count())->toBe($antes);
});

it('con Cloud API como gateway activo los mensajes entran como siempre', function () {
    config(['whatsapp.gateway' => 'cloud_api']);
    $antes = Message::count();

    (new IngestInboundPayload('cloud_api', estadosPayload([], [[
        'from' => '59170000002', 'id' => 'wamid.IN'.uniqid(), 'timestamp' => (string) time(),
        'type' => 'text', 'text' => ['body' => 'Hola'],
    ]])))->handle(app(Webkul\Whatsapp\Gateways\GatewayManager::class), app(Webkul\Whatsapp\Services\ConversationResolver::class));

    expect(Message::count())->toBe($antes + 1);
});
