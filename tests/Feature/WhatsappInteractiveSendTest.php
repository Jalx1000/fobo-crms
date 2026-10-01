<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Exceptions\InteractiveMessageException;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\InteractiveParser;

/*
 * Envío de menús tocables. El agente numera las opciones con "1.-)" y el CRM
 * las convierte en botones o lista.
 *
 * La suite corre contra la base de PRODUCCIÓN: nada de truncate(), todo dentro
 * de una transacción, y ni un solo WhatsApp real (Http::fake()).
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

function parser(): InteractiveParser
{
    return app(InteractiveParser::class);
}

function menuDeCiudades(int $cuantas): string
{
    $ciudades = ['Santa Cruz', 'Cochabamba', 'La Paz', 'Potosí', 'Oruro', 'Sucre', 'Tarija',
        'Trinidad', 'Cobija', 'El Alto', 'Montero', 'Quillacollo'];

    $texto = "¿Desde qué ciudad nos escribís?\n\n";

    foreach (array_slice($ciudades, 0, $cuantas) as $i => $ciudad) {
        $texto .= ($i + 1).'.-) '.$ciudad."\n";
    }

    return $texto;
}

function conversacionInteractiva(): object
{
    return ConversationProxy::modelClass()::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Prueba interactivos',
        'ai_enabled' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| DTO — los límites de WhatsApp
|--------------------------------------------------------------------------
*/

it('DTO interactivo: rechaza más de 3 botones', function () {
    $opciones = array_map(fn ($n) => ['id' => "opt:$n", 'title' => "Opción $n"], range(1, 4));

    expect(fn () => OutboundInteractive::buttons('¿Cuál?', $opciones))
        ->toThrow(function (InteractiveMessageException $e) {
            expect($e->valor)->toBe(4)->and($e->limite)->toBe(3)
                ->and($e->getMessage())->toContain('botones');
        });
});

it('DTO interactivo: rechaza más de 10 filas', function () {
    $opciones = array_map(fn ($n) => ['id' => "opt:$n", 'title' => "Opción $n"], range(1, 11));

    expect(fn () => OutboundInteractive::list('¿Cuál?', $opciones))
        ->toThrow(function (InteractiveMessageException $e) {
            expect($e->valor)->toBe(11)->and($e->limite)->toBe(10);
        });
});

it('DTO interactivo: rechaza un título de botón de 21 caracteres', function () {
    expect(fn () => OutboundInteractive::buttons('¿Cuál?', [
        ['id' => 'opt:1', 'title' => str_repeat('a', 21)],
    ]))->toThrow(function (InteractiveMessageException $e) {
        expect($e->valor)->toBe(21)->and($e->limite)->toBe(20);
    });
});

it('DTO interactivo: rechaza un título de fila de 25 caracteres', function () {
    expect(fn () => OutboundInteractive::list('¿Cuál?', [
        ['id' => 'opt:1', 'title' => str_repeat('a', 25)],
    ]))->toThrow(function (InteractiveMessageException $e) {
        expect($e->valor)->toBe(25)->and($e->limite)->toBe(24);
    });
});

it('DTO interactivo: rechaza body vacío, body largo y footer largo', function () {
    $ok = [['id' => 'opt:1', 'title' => 'Sí']];

    expect(fn () => OutboundInteractive::buttons('   ', $ok))->toThrow(InteractiveMessageException::class)
        ->and(fn () => OutboundInteractive::buttons(str_repeat('a', 1025), $ok))->toThrow(InteractiveMessageException::class)
        ->and(fn () => OutboundInteractive::buttons('¿Sí?', $ok, footer: str_repeat('a', 61)))->toThrow(InteractiveMessageException::class);
});

it('DTO interactivo: rechaza una lista sin opciones', function () {
    expect(fn () => OutboundInteractive::list('¿Cuál?', []))
        ->toThrow(InteractiveMessageException::class);
});

it('DTO interactivo: sobrevive la ida y vuelta por el payload', function () {
    $original = OutboundInteractive::list('¿Ciudad?', [
        ['id' => 'opt:1', 'title' => 'Santa Cruz'],
        ['id' => 'opt:2', 'title' => 'Cochabamba'],
    ], footer: 'Kohlberg');

    $reconstruido = OutboundInteractive::fromArray($original->toArray());

    expect($reconstruido->toArray())->toBe($original->toArray());
});

/*
|--------------------------------------------------------------------------
| Parser — los 7 casos de la regla
|--------------------------------------------------------------------------
*/

it('Parser: 3 opciones cortas salen como botones', function () {
    $parsed = parser()->parse(menuDeCiudades(3));

    expect($parsed->isInteractive())->toBeTrue()
        ->and($parsed->interactive->kind)->toBe(OutboundInteractive::BUTTONS)
        ->and($parsed->interactive->options)->toHaveCount(3)
        ->and($parsed->interactive->options[0]['title'])->toBe('Santa Cruz');
});

it('Parser: 7 opciones salen como lista', function () {
    $parsed = parser()->parse(menuDeCiudades(7));

    expect($parsed->interactive->kind)->toBe(OutboundInteractive::LIST)
        ->and($parsed->interactive->options)->toHaveCount(7)
        ->and($parsed->interactive->listButton)->toBe('Ver opciones');
});

it('Parser: 12 opciones degradan a texto', function () {
    $parsed = parser()->parse(menuDeCiudades(12));

    expect($parsed->isInteractive())->toBeFalse()
        ->and($parsed->reason)->toContain('12 opciones')
        // El cliente recibe numeración normal, no el marcador interno crudo.
        ->and($parsed->fallbackText)->toContain('1. Santa Cruz')
        ->and($parsed->fallbackText)->not->toContain('1.-)');
});

it('Parser: una opción demasiado larga degrada a texto', function () {
    $texto = "¿Cuál pedido?\n\n1.-) Pedido de 2 Promo Varietales, total Bs 478.00\n2.-) Corto\n";

    $parsed = parser()->parse($texto);

    expect($parsed->isInteractive())->toBeFalse()
        ->and($parsed->reason)->toContain('caracteres');
});

it('Parser: numeración salteada no se toca', function () {
    expect(parser()->parse("Elegí:\n\n1.-) Uno\n2.-) Dos\n4.-) Cuatro\n"))->toBeNull();
});

it('Parser: una sola opción no es un menú', function () {
    expect(parser()->parse("Elegí:\n\n1.-) Único\n"))->toBeNull();
});

/*
 * EL CASO QUE PROTEGE CONTRA EL 50 % DE FALSOS POSITIVOS.
 *
 * Este es un mensaje real del agente en producción. Con "1." a secas como
 * marcador se habría convertido en un menú, pidiéndole al cliente que elija un
 * pedido que nadie le ofreció.
 */
it('Parser: una enumeración con "1." a secas NO se convierte', function () {
    $real = <<<'TXT'
    Alejandro, tienes 4 pedidos en estado "Cliente confirmado" en Santa Cruz:

    1. Pedido de 2 Promo Varietales: 2 Promo Varietales, total Bs 478.00.
    2. Pedido de 3 vinos: incluye 1 Promo Varietales y 2 Pack Red Blend + Copa, total Bs 387.
    3. Pedido Club del Vino - Javier Mogro: 1 Cabernet Sauvignon Block 63.
    4. Pedido Promo Varietales: 1 Promo Varietales.

    ¿Quieres que te ayude con algo más?
    TXT;

    expect(parser()->parse($real))->toBeNull();
});

it('Parser: separa body, footer y limpia los títulos', function () {
    $texto = "¿Desde qué ciudad nos escribís?\n\n1.-) Santa Cruz\n2.-) Cochabamba\n\nGracias.";

    $parsed = parser()->parse($texto);

    expect($parsed->interactive->body)->toBe('¿Desde qué ciudad nos escribís?')
        ->and($parsed->interactive->footer)->toBe('Gracias.')
        ->and($parsed->interactive->options[1])->toBe(['id' => 'opt:2', 'title' => 'Cochabamba']);
});

it('Parser: un footer más largo que el límite se anexa al body en vez de perderse', function () {
    $largo = str_repeat('nota ', 20);

    $parsed = parser()->parse("¿Ciudad?\n\n1.-) Santa Cruz\n2.-) Sucre\n\n{$largo}");

    expect($parsed->interactive->footer)->toBeNull()
        ->and($parsed->interactive->body)->toContain('nota');
});

it('Parser: sin texto antes de las opciones degrada, porque el body es obligatorio', function () {
    $parsed = parser()->parse("1.-) Santa Cruz\n2.-) Sucre\n");

    expect($parsed->isInteractive())->toBeFalse()
        ->and($parsed->reason)->toContain('sin texto');
});

/*
|--------------------------------------------------------------------------
| Gateway — la forma exacta del payload a Meta
|--------------------------------------------------------------------------
*/

it('Gateway: postea botones con la forma que espera Meta', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.btn']]])]);

    $gateway = new CloudApiGateway(['token' => 't', 'phone_number_id' => '123']);
    $conversation = conversacionInteractiva();

    $gateway->sendInteractive($conversation, parser()->parse(menuDeCiudades(3))->interactive);

    Http::assertSent(function ($request) {
        $i = $request['interactive'];

        return $request['type'] === 'interactive'
            && $i['type'] === 'button'
            && $i['body']['text'] === '¿Desde qué ciudad nos escribís?'
            && count($i['action']['buttons']) === 3
            && $i['action']['buttons'][0]['type'] === 'reply'
            && $i['action']['buttons'][0]['reply'] === ['id' => 'opt:1', 'title' => 'Santa Cruz'];
    });
});

it('Gateway: postea la lista con una sección y el label del botón', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.list']]])]);

    $gateway = new CloudApiGateway(['token' => 't', 'phone_number_id' => '123']);

    $gateway->sendInteractive(conversacionInteractiva(), parser()->parse(menuDeCiudades(7))->interactive);

    Http::assertSent(function ($request) {
        $i = $request['interactive'];

        return $i['type'] === 'list'
            && $i['action']['button'] === 'Ver opciones'
            && count($i['action']['sections']) === 1
            && count($i['action']['sections'][0]['rows']) === 7
            && $i['action']['sections'][0]['rows'][6]['title'] === 'Tarija';
    });
});

it('Gateway: declara que puede mandar botones y listas', function () {
    $caps = (new CloudApiGateway([]))->capabilities();

    expect($caps->canSend('buttons'))->toBeTrue()
        ->and($caps->canSend('list'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| API del agente
|--------------------------------------------------------------------------
*/

it('API: un texto con opciones marcadas crea un mensaje interactivo', function () {
    actingAsSanctumAuthenticatedAdmin();

    $conversation = conversacionInteractiva();
    $conversation->forceFill(['last_inbound_at' => now()])->save();

    $response = $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/messages", [
        'text' => menuDeCiudades(3),
    ]);

    $response->assertOk();

    $message = MessageProxy::modelClass()::findOrFail($response->json('message.id'));

    expect($message->type)->toBe('interactive')
        ->and($message->payload['interactive']['kind'])->toBe(OutboundInteractive::BUTTONS)
        ->and($message->payload['interactive']['options'])->toHaveCount(3);
});

it('API: un menú que no entra sale como texto y deja el motivo', function () {
    actingAsSanctumAuthenticatedAdmin();

    $conversation = conversacionInteractiva();
    $conversation->forceFill(['last_inbound_at' => now()])->save();

    $response = $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/messages", [
        'text' => menuDeCiudades(12),
    ]);

    $response->assertOk();

    $message = MessageProxy::modelClass()::findOrFail($response->json('message.id'));

    expect($message->type)->toBe('text')
        ->and($message->payload['interactive_skipped'])->toContain('12 opciones')
        ->and($message->body)->toContain('1. Santa Cruz');
});

it('API: un texto normal sigue saliendo como texto', function () {
    actingAsSanctumAuthenticatedAdmin();

    $conversation = conversacionInteractiva();
    $conversation->forceFill(['last_inbound_at' => now()])->save();

    $response = $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/messages", [
        'text' => 'Hola, ¿en qué te ayudo?',
    ]);

    $message = MessageProxy::modelClass()::findOrFail($response->json('message.id'));

    expect($message->type)->toBe('text')
        ->and($message->payload)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Round-trip: el contrato completo, no las dos mitades sueltas
|--------------------------------------------------------------------------
*/

it('Round-trip: el cliente toca una fila y el agente recibe la selección', function () {
    $conversation = conversacionInteractiva();

    // Ida: se manda la lista y se guarda qué se ofreció.
    $interactive = parser()->parse(menuDeCiudades(7))->interactive;

    MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'outbound',
        'type'            => 'interactive',
        'body'            => $interactive->label(),
        'payload'         => ['interactive' => $interactive->toArray()],
        'status'          => 'sent',
        'sender'          => 'ia',
    ]);

    // Vuelta: el cliente toca "Cochabamba", que era opt:2.
    $entrante = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'inbound',
        'type'            => 'interactive',
        'body'            => 'Cochabamba',
        'payload'         => [
            'type'        => 'interactive',
            'interactive' => [
                'type'       => 'list_reply',
                'list_reply' => ['id' => 'opt:2', 'title' => 'Cochabamba'],
            ],
        ],
        'status' => 'received',
    ]);

    $event = app(\Webkul\Whatsapp\Services\AgentPayload::class)->event($entrante);

    /*
     * El tipo real más `selection`, que es el contrato que el mismo agente ya
     * usa en imprimir. Antes esto se normalizaba a 'text' para que el agente no
     * descartara el turno; la respuesta correcta era alinear el contrato.
     */
    expect($event['message']['type'])->toBe('interactive')
        ->and($event['message']['text'])->toBe('Cochabamba')
        ->and($event['message']['selection'])->toBe(['id' => 'opt:2', 'title' => 'Cochabamba'])
        ->and(collect($event['history'])->last()['type'])->toBe('interactive');
});

it('Round-trip: un mensaje de texto normal sigue reportándose como texto', function () {
    $conversation = conversacionInteractiva();

    $entrante = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'Hola',
        'payload'         => ['type' => 'text', 'text' => ['body' => 'Hola']],
        'status'          => 'received',
    ]);

    $event = app(\Webkul\Whatsapp\Services\AgentPayload::class)->event($entrante);

    expect($event['message']['type'])->toBe('text')
        ->and($event['message'])->not->toHaveKey('interactive');
});

/*
|--------------------------------------------------------------------------
| El job: que el tipo decida el envío
|--------------------------------------------------------------------------
*/

it('Job: un mensaje interactivo va a sendInteractive y no a sendText', function () {
    $conversation = conversacionInteractiva();
    $interactive = parser()->parse(menuDeCiudades(7))->interactive;

    $message = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'outbound',
        'type'            => 'interactive',
        'body'            => $interactive->label(),
        'payload'         => ['interactive' => $interactive->toArray()],
        'status'          => 'queued',
        'sender'          => 'ia',
    ]);

    // Doble del gateway: se afirma la llamada, no se lee el match del job.
    $gateway = Mockery::mock(\Webkul\Whatsapp\Gateways\Contracts\Gateway::class);
    $gateway->shouldReceive('sendInteractive')->once()
        ->andReturn(new \Webkul\Whatsapp\Gateways\Dto\SendResult('wamid.job'));
    $gateway->shouldNotReceive('sendText');

    $manager = Mockery::mock(\Webkul\Whatsapp\Gateways\GatewayManager::class);
    $manager->shouldReceive('for')->andReturn($gateway);

    (new \Webkul\Whatsapp\Jobs\SendMessage($message->id))->handle($manager);

    expect($message->fresh()->status)->toBe('sent')
        ->and($message->fresh()->wa_message_id)->toBe('wamid.job');
});

it('Job: un canal que no soporta interactivos falla con el motivo, no en silencio', function () {
    $conversation = conversacionInteractiva();
    $interactive = parser()->parse(menuDeCiudades(3))->interactive;

    $message = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'outbound',
        'type'            => 'interactive',
        'body'            => $interactive->label(),
        'payload'         => ['interactive' => $interactive->toArray()],
        'status'          => 'queued',
        'sender'          => 'ia',
    ]);

    $gateway = new \Webkul\Whatsapp\Gateways\Kommo\KommoGateway([]);

    $manager = Mockery::mock(\Webkul\Whatsapp\Gateways\GatewayManager::class);
    $manager->shouldReceive('for')->andReturn($gateway);

    (new \Webkul\Whatsapp\Jobs\SendMessage($message->id))->handle($manager);

    // Falla, pero con el motivo escrito donde el agente lo puede leer.
    expect($message->fresh()->status)->toBe('failed')
        ->and($message->fresh()->error)->toContain('interactive');
});
