<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * A customer who answers by tapping a button sends no text: Meta delivers
 * `type: interactive` with the label nested under the reply kind. The CRM used
 * to store body = null and the agent dropped the turn as
 * crm_unsupported_message — the customer got no reply at all.
 */
beforeEach(function () {
    Queue::fake();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

function interactivePayload(string $phone, string $wamid, array $interactive): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Alejandro']]],
                    'messages' => [[
                        'id'          => $wamid,
                        'from'        => $phone,
                        'type'        => 'interactive',
                        'interactive' => $interactive,
                    ]],
                ],
            ]],
        ]],
    ];
}

it('reads the label out of a button reply', function () {
    $gateway = new CloudApiGateway([]);

    $batch = $gateway->parseWebhook(interactivePayload('59176616013', 'wamid.btn', [
        'type'         => 'button_reply',
        'button_reply' => ['id' => 'btn_catalogo', 'title' => 'Ver catálogo'],
    ]));

    expect($batch->messages)->toHaveCount(1)
        ->and($batch->messages[0]->body)->toBe('Ver catálogo')
        ->and($batch->messages[0]->type)->toBe('interactive');
});

it('reads the label out of a list reply', function () {
    $gateway = new CloudApiGateway([]);

    $batch = $gateway->parseWebhook(interactivePayload('59176616013', 'wamid.list', [
        'type'       => 'list_reply',
        'list_reply' => ['id' => 'row_2', 'title' => 'Malbec Reserva', 'description' => '750ml'],
    ]));

    expect($batch->messages[0]->body)->toBe('Malbec Reserva');
});

it('falls back to the option id when the provider sends no title', function () {
    $gateway = new CloudApiGateway([]);

    $batch = $gateway->parseWebhook(interactivePayload('59176616013', 'wamid.notitle', [
        'type'         => 'button_reply',
        'button_reply' => ['id' => 'btn_si'],
    ]));

    expect($batch->messages[0]->body)->toBe('btn_si');
});

it('leaves a malformed interactive message alone instead of failing', function () {
    $gateway = new CloudApiGateway([]);

    $batch = $gateway->parseWebhook(interactivePayload('59176616013', 'wamid.broken', [
        'type' => 'button_reply',
    ]));

    expect($batch->messages[0]->body)->toBeNull()
        ->and($gateway->interactiveSelection([]))->toBeNull();
});

it('sends the button label as message.text on the agent event', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    $wamid = 'wamid.event.'.random_int(1000, 9999);

    (new IngestInboundPayload('cloud_api', interactivePayload($phone, $wamid, [
        'type'         => 'button_reply',
        'button_reply' => ['id' => 'btn_catalogo', 'title' => 'Ver catálogo'],
    ])))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    $message = MessageProxy::modelClass()::where('wa_message_id', $wamid)->firstOrFail();

    $event = app(AgentPayload::class)->event($message);

    expect($event['event'])->toBe('message.received')
        ->and($event['message']['text'])->toBe('Ver catálogo')
        /*
         * El tipo REAL, no normalizado a 'text'.
         *
         * Se normalizaba para que el agente dejara de descartar estos turnos
         * (crm_unsupported_message), pero eso apartaba a kohlberg del contrato
         * que el mismo agente ya usa en imprimir. La respuesta correcta es
         * `message.selection`, que es lo que el agente lee — ver
         * WhatsappContratoAgenteTest.
         */
        ->and($event['message']['type'])->toBe('interactive')
        ->and($event['message']['selection'])->toBe(['id' => 'btn_catalogo', 'title' => 'Ver catálogo'])
        ->and($event['message']['interactive'])->toBe([
            'kind'  => 'button_reply',
            'id'    => 'btn_catalogo',
            'title' => 'Ver catálogo',
        ]);

    // The history the agent reads back has the label too, not a blank turn.
    expect(collect($event['history'])->last()['content'])->toBe('Ver catálogo');
});

it('reads a quick-reply template button too', function () {
    $gateway = new CloudApiGateway([]);

    $batch = $gateway->parseWebhook([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id'     => 'wamid.template',
                        'from'   => '59176616013',
                        'type'   => 'button',
                        'button' => ['payload' => 'CONFIRMAR', 'text' => 'Confirmar pedido'],
                    ]],
                ],
            ]],
        ]],
    ]);

    expect($batch->messages[0]->body)->toBe('Confirmar pedido');
});

it('el hilo muestra la opción que tocó el cliente, no "Menú enviado"', function () {
    $conv = \Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Cliente interactivo',
        'status'     => 'open',
        'ai_enabled' => true,
    ]);

    /*
     * Un interactivo tiene DOS formas y antes el inbox sólo entendía una.
     *
     * Saliente: nuestro DTO, con `options`.
     * Entrante: la forma cruda de Meta, anidada bajo `button_reply`/`list_reply`
     * y SIN `options` — el `empty($node['options'])` la descartaba, así que la
     * respuesta del cliente caía en el v-else y el asesor leía "Menú enviado"
     * justo donde el cliente había contestado.
     */
    $entrante = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id,
        'direction'       => 'inbound',
        'type'            => 'interactive',
        'body'            => 'Sí',
        'status'          => 'delivered',
        'sender'          => 'contact',
        'payload'         => [
            'type'        => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'opt:1', 'title' => 'Sí']],
        ],
    ]);

    $saliente = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id,
        'direction'       => 'outbound',
        'type'            => 'interactive',
        'body'            => '¿Seguimos?',
        'status'          => 'sent',
        'sender'          => 'ia',
        'payload'         => [
            'interactive' => [
                'body'    => '¿Seguimos?',
                'kind'    => 'buttons',
                'options' => [['id' => 'opt:1', 'title' => 'Sí'], ['id' => 'opt:2', 'title' => 'No']],
            ],
        ],
    ]);

    $controlador = app(\Webkul\Whatsapp\Http\Controllers\InboxController::class);
    $metodo = new ReflectionMethod($controlador, 'interactiveOf');
    $metodo->setAccessible(true);

    // La respuesta del cliente: se expone la opción elegida, con su id.
    expect($metodo->invoke($controlador, $entrante))->toBe([
        'kind'      => 'seleccion',
        'seleccion' => ['id' => 'opt:1', 'title' => 'Sí'],
    ]);

    // El menú que mandamos: sigue exponiendo body y opciones como antes.
    $menu = $metodo->invoke($controlador, $saliente);

    expect($menu['kind'])->toBe('buttons')
        ->and($menu['body'])->toBe('¿Seguimos?')
        ->and($menu['options'])->toHaveCount(2);
});
