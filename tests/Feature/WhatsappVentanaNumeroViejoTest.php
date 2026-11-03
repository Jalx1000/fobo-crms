<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Jobs\NotifyAgent;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/*
 * La ventana de 24 h es por número del negocio.
 *
 * Desde el 27/10 respondemos desde el +591 78638547, pero la WABA del número
 * viejo sigue suscrita a la app y sus mensajes entran por el mismo webhook. Cada
 * uno abría la ventana en el CRM; el asesor escribía y Meta devolvía 131047,
 * porque con el número nuevo el cliente nunca había hablado (caso del
 * +59168686440, conversación 1352).
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    Queue::fake();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

function payloadRecibidoPor(string $phone, string $phoneNumberId): array
{
    return [
        'entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'contacts' => [['profile' => ['name' => 'Cliente']]],
            'messages' => [[
                'id'   => 'wamid.ventana'.random_int(100000, 999999),
                'from' => $phone,
                'type' => 'text',
                'text' => ['body' => 'Hola'],
            ]],
        ]]]]],
    ];
}

function numeroActivo(): string
{
    return (string) config('whatsapp.gateways.cloud_api.phone_number_id');
}

it('el gateway marca si el mensaje llegó al número desde el que respondemos', function () {
    $gateway = new CloudApiGateway(['phone_number_id' => '111']);

    $nuestro = $gateway->parseWebhook(payloadRecibidoPor('59170000001', '111'))->messages[0];
    $viejo = $gateway->parseWebhook(payloadRecibidoPor('59170000001', '999'))->messages[0];

    expect($nuestro->opensWindow)->toBeTrue()
        ->and($viejo->opensWindow)->toBeFalse()
        ->and($viejo->raw['received_by'])->toBe('999');
});

it('sin ids para comparar, el mensaje cuenta como nuestro', function () {
    $message = (new CloudApiGateway([]))->parseWebhook(payloadRecibidoPor('59170000001', '999'))->messages[0];

    expect($message->opensWindow)->toBeTrue();
});

it('un mensaje al número viejo se guarda pero no abre la ventana ni despierta al agente', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    (new IngestInboundPayload('cloud_api', payloadRecibidoPor($phone, 'numero-viejo')))
        ->handle(app(GatewayManager::class), app(ConversationResolver::class));

    $conversation = ConversationProxy::modelClass()::where('wa_phone', '+'.$phone)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->last_inbound_at)->toBeNull()
        ->and(MessageProxy::modelClass()::where('conversation_id', $conversation->id)->count())->toBe(1);

    Queue::assertNotPushed(NotifyAgent::class);
});

it('un mensaje al número activo sí abre la ventana', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    (new IngestInboundPayload('cloud_api', payloadRecibidoPor($phone, numeroActivo())))
        ->handle(app(GatewayManager::class), app(ConversationResolver::class));

    $conversation = ConversationProxy::modelClass()::where('wa_phone', '+'.$phone)->first();

    expect($conversation->last_inbound_at)->not->toBeNull();
});

it('con la ventana cerrada el backend rechaza el texto libre', function () {
    $conversation = ConversationProxy::modelClass()::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '+5917'.random_int(1000000, 9999999),
        'ai_enabled'      => false,
        'last_inbound_at' => now()->subHours(25),
    ]);

    $admin = User::where('role_id', 1)->where('status', 1)->first();

    test()->actingAs($admin, 'user')
        ->postJson(route('admin.whatsapp.send'), [
            'conversation_id' => $conversation->id,
            'phone'           => $conversation->wa_phone,
            'text'            => 'Hola',
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Fuera de la ventana de 24h de WhatsApp. Se requiere una plantilla aprobada o esperar a que el cliente escriba.']);

    Queue::assertNotPushed(SendMessage::class);
});

it('con la ventana abierta el texto sale', function () {
    $conversation = ConversationProxy::modelClass()::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '+5917'.random_int(1000000, 9999999),
        'ai_enabled'      => false,
        'last_inbound_at' => now()->subHour(),
    ]);

    $admin = User::where('role_id', 1)->where('status', 1)->first();

    test()->actingAs($admin, 'user')
        ->postJson(route('admin.whatsapp.send'), [
            'conversation_id' => $conversation->id,
            'phone'           => $conversation->wa_phone,
            'text'            => 'Hola',
        ])
        ->assertOk();

    Queue::assertPushed(SendMessage::class);
});
