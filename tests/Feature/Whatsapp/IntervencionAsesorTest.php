<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Gateways\Kommo\KommoGateway;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Intervención del asesor (28/10): cuando un asesor le escribe al cliente -desde
 * el CRM, Kommo o la app de WhatsApp Business- el pedido pasa de NO ATENDIDOS a
 * SOLICITUD RECIBIDA. Y el agente también puede moverlo, por la API.
 * Nada sale a la red: Meta, Kommo, correo y cola falseados.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Http::fake();
    Mail::fake();
    Queue::fake();

    config(['whatsapp.gateway' => 'kommo', 'whatsapp.gateways.kommo.send_via' => 'cloud_api']);

    $this->etapa = fn (string $code) => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', $code)->value('id');

    $this->persona = DB::table('persons')->insertGetId(['name' => 'Intervención '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

    $this->lead = DB::table('leads')->insertGetId([
        'title'            => 'Intervención '.uniqid(), 'person_id' => $this->persona, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => ($this->etapa)('no-atendidos'),
        'created_at'       => now(), 'updated_at' => now(),
    ]);

    $this->telefono = '5917'.random_int(1000000, 9999999);

    $this->conv = Conversation::create([
        'gateway'                  => 'kommo', 'wa_phone' => '+'.$this->telefono, 'wa_name' => 'Intervención',
        'ai_enabled'               => false, 'status' => 'open', 'person_id' => $this->persona, 'lead_id' => $this->lead,
        'provider_conversation_id' => (string) random_int(100000, 999999),
    ]);
});

function etapaDelPedido(int $lead): string
{
    return DB::table('lead_pipeline_stages')->where('id', DB::table('leads')->where('id', $lead)->value('lead_pipeline_stage_id'))->value('code');
}

function mensaje(Conversation $conv, string $sender, string $direction = 'outbound'): Message
{
    return Message::create([
        'conversation_id' => $conv->id, 'direction' => $direction, 'type' => 'text',
        'body'            => 'Hola', 'status' => 'sent', 'sender' => $sender,
    ]);
}

function ingerir(string $gateway, array $payload): void
{
    (new IngestInboundPayload($gateway, $payload))->handle(app(GatewayManager::class), app(ConversationResolver::class));
}

function ecoCloudApi(string $para, string $id): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [[
        'field' => 'smb_message_echoes',
        'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1'], 'message_echoes' => [[
            'from' => '59177655508', 'to' => $para, 'id' => $id, 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'Hola, soy su asesor'],
        ]]],
    ]]]]];
}

function salienteKommo(string $leadKommo, string $tipoAutor): array
{
    return ['message' => ['add' => [[
        'id'         => 'kommo-'.uniqid(), 'type' => 'outgoing', 'text' => 'Hola, soy su asesor',
        'element_id' => $leadKommo, 'entity_id' => $leadKommo,
        'author'     => ['id' => 'x', 'type' => $tipoAutor, 'name' => 'Fabio'],
    ]]]];
}

/*
| CRM: un mensaje del asesor mueve el pedido
*/

it('un mensaje del asesor pasa el pedido de NO ATENDIDOS a SOLICITUD RECIBIDA', function () {
    mensaje($this->conv, 'human');

    expect(etapaDelPedido($this->lead))->toBe('solicitud-recibida');
});

it('no lo mueven la IA, el recontacto automático ni el cliente', function (string $sender, string $direction) {
    mensaje($this->conv, $sender, $direction);

    expect(etapaDelPedido($this->lead))->toBe('no-atendidos');
})->with([
    'ia'      => ['ia', 'outbound'],
    'sistema' => ['system', 'outbound'],
    'cliente' => ['contact', 'inbound'],
]);

it('no hace retroceder un pedido que ya está más adelante', function () {
    DB::table('leads')->where('id', $this->lead)->update(['lead_pipeline_stage_id' => ($this->etapa)('cotizacion-enviada')]);

    mensaje($this->conv, 'human');

    expect(etapaDelPedido($this->lead))->toBe('cotizacion-enviada');
});

it('lo que el asesor escribe desde el inbox del CRM mueve el pedido', function () {
    $this->actingAs(Webkul\User\Models\User::find(1), 'user')
        ->postJson(route('admin.whatsapp.send'), ['conversation_id' => $this->conv->id, 'text' => 'Hola, le escribe su asesor'])
        ->assertOk();

    expect(etapaDelPedido($this->lead))->toBe('solicitud-recibida');
});

/*
| Kommo: el asesor escribe desde Kommo
*/

it('Kommo: un saliente de un asesor entra como eco del asesor', function () {
    $mensajes = (new KommoGateway(['send_via' => 'cloud_api', 'echo_author_types' => ['internal']]))
        ->parseWebhook(salienteKommo('555', 'internal'))->messages;

    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]->isEcho())->toBeTrue()
        ->and($mensajes[0]->contact->name)->toBeNull();
});

it('Kommo: un saliente de un bot o sin modo mixto se descarta', function (string $tipo, string $sendVia) {
    $mensajes = (new KommoGateway(['send_via' => $sendVia, 'echo_author_types' => ['internal']]))
        ->parseWebhook(salienteKommo('555', $tipo))->messages;

    expect($mensajes)->toBeEmpty();
})->with([
    'bot'           => ['bot', 'cloud_api'],
    'salesbot'      => ['internal', 'kommo'],
]);

it('Kommo: el eco del asesor queda en el hilo, sin despertar a la IA, y mueve el pedido', function () {
    config(['whatsapp.gateways.kommo.echo_author_types' => ['internal']]);

    ingerir('kommo', salienteKommo($this->conv->provider_conversation_id, 'internal'));

    $eco = $this->conv->messages()->latest('id')->first();

    expect($eco->direction)->toBe('outbound')
        ->and($eco->sender)->toBe('human')
        ->and($this->conv->fresh()->unread_count)->toBe(0)
        ->and(etapaDelPedido($this->lead))->toBe('solicitud-recibida');
});

/*
| Cloud API: el asesor escribe desde la app WhatsApp Business (coexistencia)
*/

it('Cloud API: lee smb_message_echoes con el cliente en `to`', function () {
    $mensajes = (new CloudApiGateway([]))->parseWebhook(ecoCloudApi('59170000001', 'wamid.ECO'))->messages;

    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]->isEcho())->toBeTrue()
        ->and($mensajes[0]->contact->phone)->toBe('59170000001')
        ->and($mensajes[0]->body)->toBe('Hola, soy su asesor');
});

it('Cloud API en modo mixto: el eco va al hilo de Kommo de ese teléfono y mueve el pedido', function () {
    $conversaciones = Conversation::count();

    ingerir('cloud_api', ecoCloudApi($this->telefono, 'wamid.ECO'.uniqid()));

    expect(Conversation::count())->toBe($conversaciones)
        ->and($this->conv->messages()->where('sender', 'human')->count())->toBe(1)
        ->and(etapaDelPedido($this->lead))->toBe('solicitud-recibida');
});

it('Cloud API en modo mixto: un eco de un teléfono sin conversación no abre una nueva', function () {
    $conversaciones = Conversation::count();

    ingerir('cloud_api', ecoCloudApi('59160000000', 'wamid.ECO'.uniqid()));

    expect(Conversation::count())->toBe($conversaciones);
});

/*
| Agente: mueve la etapa por la API
*/

it('el agente pasa el pedido a SOLICITUD RECIBIDA', function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/etapa", ['etapa' => 'solicitud-recibida'])
        ->assertOk()
        ->assertJson(['movido' => true, 'pedido' => ['id' => $this->lead, 'etapa' => 'solicitud-recibida']]);
});

it('el agente no hace retroceder el pedido: contesta movido=false', function () {
    actingAsSanctumAuthenticatedAdmin();
    DB::table('leads')->where('id', $this->lead)->update(['lead_pipeline_stage_id' => ($this->etapa)('cotizacion-enviada')]);

    $this->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/etapa", ['etapa' => 'solicitud-recibida'])
        ->assertOk()
        ->assertJson(['movido' => false, 'pedido' => ['etapa' => 'cotizacion-enviada']]);
});

it('el agente no puede mover a una etapa fuera de la lista', function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/etapa", ['etapa' => 'entregados'])
        ->assertStatus(422);

    expect(etapaDelPedido($this->lead))->toBe('no-atendidos');
});

it('el agente ve la etapa del pedido en el contexto de la conversación', function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->getJson("/api/v1/whatsapp/conversations/{$this->conv->id}/messages")
        ->assertOk()
        ->assertJsonPath('pedido.etapa', 'no-atendidos');
});
