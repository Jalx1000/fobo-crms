<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Jobs\NotifyAgent;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Con el agente apagado, el agente sigue enterándose de la conversación —lo del
 * cliente y lo del asesor— para no volver «sin saber qué se habló», pero no
 * puede contestar.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    config(['whatsapp.ai.context_when_off' => true]);
});

function apagadoConversacion(?bool $ia): object
{
    return ConversationProxy::modelClass()::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '+54938'.random_int(10000000, 99999999),
        'wa_name'         => 'Cliente apagado',
        'ai_enabled'      => $ia,
        'last_inbound_at' => now(),
        'last_message_at' => now(),
    ]);
}

function apagadoTexto(string $phone): array
{
    return ['entry' => [['changes' => [['value' => [
        'contacts' => [['profile' => ['name' => 'Cliente']]],
        'messages' => [['id' => 'wamid.'.uniqid(), 'from' => ltrim($phone, '+'), 'type' => 'text', 'text' => ['body' => 'Hola, sigo acá']]],
    ]]]]]];
}

it('con el agente apagado igual le avisa el mensaje del cliente', function () {
    $conv = apagadoConversacion(false);

    (new IngestInboundPayload('cloud_api', apagadoTexto($conv->wa_phone)))
        ->handle(app(GatewayManager::class), app(ConversationResolver::class));

    Queue::assertPushed(NotifyAgent::class);
});

it('apagado el aviso de contexto, no le avisa nada', function () {
    config(['whatsapp.ai.context_when_off' => false]);
    $conv = apagadoConversacion(false);

    (new IngestInboundPayload('cloud_api', apagadoTexto($conv->wa_phone)))
        ->handle(app(GatewayManager::class), app(ConversationResolver::class));

    Queue::assertNotPushed(NotifyAgent::class);
});

it('lo que escribe el asesor también le llega al agente', function () {
    $conv = apagadoConversacion(false);

    MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id, 'direction' => 'outbound', 'sender' => 'human',
        'type' => 'text', 'body' => 'La bomba sale 14000', 'status' => 'queued',
    ]);

    Queue::assertPushed(NotifyAgent::class);
});

it('el evento dice que no tiene que responder y quién escribió cada mensaje', function () {
    $conv = apagadoConversacion(false);

    $cliente = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'sender' => 'contact',
        'type' => 'text', 'body' => '¿Cuánto sale la bomba?', 'status' => 'delivered',
    ]);
    $asesor = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id, 'direction' => 'outbound', 'sender' => 'human',
        'type' => 'text', 'body' => 'Sale 14000', 'status' => 'sent',
    ]);

    $evento = app(AgentPayload::class)->event($cliente->fresh('conversation'));
    $delAsesor = app(AgentPayload::class)->event($asesor->fresh('conversation'));

    expect($evento['responder'])->toBeFalse()
        ->and($evento['event'])->toBe('message.received')
        ->and($delAsesor['event'])->toBe('message.sent')
        ->and($delAsesor['responder'])->toBeFalse()
        ->and(collect($evento['history'])->pluck('sender')->all())->toContain('contact', 'human');
});

it('con el agente prendido el mensaje del cliente es su turno', function () {
    $conv = apagadoConversacion(true);

    $cliente = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'sender' => 'contact',
        'type' => 'text', 'body' => 'Hola', 'status' => 'delivered',
    ]);

    expect(app(AgentPayload::class)->event($cliente->fresh('conversation'))['responder'])->toBeTrue();
});

it('el agente no puede contestar con el agente apagado', function () {
    actingAsSanctumAuthenticatedAdmin();
    $conv = apagadoConversacion(false);

    test()->postJson("/api/v1/whatsapp/conversations/{$conv->id}/messages", ['text' => 'hola'])
        ->assertStatus(409);

    expect($conv->messages()->count())->toBe(0);
});
