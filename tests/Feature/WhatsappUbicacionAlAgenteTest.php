<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Jobs\NotifyAgent;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Support\UbicacionDeMensaje;

/*
 * El pin que el cliente comparte por WhatsApp, tal como llega al agente.
 *
 * El evento `message.received` SÍ se disparaba para una ubicación, pero llegaba
 * vacío: `extractBody()` no tenía caso para 'location', así que `body` quedaba
 * null y el agente recibía `type: location` y nada más. Las coordenadas estaban
 * guardadas en `payload` y nunca salían de la base. Se midió: las 5 ubicaciones
 * recibidas hasta el 06/10/2026 tenían body NULL, y el equipo del agente lo
 * reportó desde su lado el mismo día — su agente terminaba pidiéndole al cliente
 * que escribiera la dirección a mano.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    Queue::fake();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

function payloadUbicacion(string $phone, string $wamid, array $nodo): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Cliente']]],
                    'messages' => [[
                        'id'       => $wamid,
                        'from'     => $phone,
                        'type'     => 'location',
                        'location' => $nodo,
                    ]],
                ],
            ]],
        ]],
    ];
}

/** Ingiere una ubicación como si viniera del webhook y devuelve el mensaje. */
function ingerirUbicacion(array $nodo): object
{
    $wamid = 'wamid.ubic.'.uniqid();
    $phone = '5493884'.random_int(100000, 999999);

    (new IngestInboundPayload('cloud_api', payloadUbicacion($phone, $wamid, $nodo)))
        ->handle(app(GatewayManager::class), app(ConversationResolver::class));

    return MessageProxy::modelClass()::where('wa_message_id', $wamid)->firstOrFail();
}

it('una ubicación que entra deja de tener el cuerpo vacío', function () {
    $batch = (new CloudApiGateway([]))->parseWebhook(payloadUbicacion(
        '5493884000001',
        'wamid.u1',
        ['latitude' => -24.2550022, 'longitude' => -65.2082377],
    ));

    expect($batch->messages[0]->type)->toBe('location')
        ->and($batch->messages[0]->body)->toBe('-24.255002,-65.208238');
});

/*
 * El formato es el que el agente tiene documentado para `ubicacion_lead`
 * ("<lat>,<lng> | <referencia>"), sin prefijos: el agente reenvía este texto
 * tal cual al campo del lead.
 */
it('el nombre y la dirección del pin viajan como referencia', function () {
    $texto = UbicacionDeMensaje::texto(['location' => [
        'latitude'  => -24.1884781,
        'longitude' => -65.300333,
        'name'      => 'Casa',
        'address'   => 'Belgrano 450',
    ]]);

    expect($texto)->toBe('-24.188478,-65.300333 | Casa, Belgrano 450');
});

it('un pin en 0,0 no es una ubicación', function () {
    expect(UbicacionDeMensaje::de(['location' => ['latitude' => 0, 'longitude' => 0]]))->toBeNull()
        ->and(UbicacionDeMensaje::de(['location' => ['latitude' => -24.25]]))->toBeNull()
        ->and(UbicacionDeMensaje::de([]))->toBeNull()
        ->and(UbicacionDeMensaje::texto([]))->toBeNull();
});

it('una ubicación despierta al agente', function () {
    ingerirUbicacion(['latitude' => -24.2550348, 'longitude' => -65.2083152]);

    Queue::assertPushed(NotifyAgent::class);
});

/*
 * Las dos formas que el equipo del agente dijo que acepta, a propósito: el
 * objeto anidado y los campos planos.
 */
it('el agente recibe las coordenadas, no sólo el tipo', function () {
    $mensaje = ingerirUbicacion(['latitude' => -24.1884781, 'longitude' => -65.300333]);

    $m = app(AgentPayload::class)->event($mensaje)['message'];

    expect($m['text'])->toBe('-24.188478,-65.300333')
        ->and($m['location']['lat'])->toBe(-24.1884781)
        ->and($m['location']['lng'])->toBe(-65.300333)
        ->and($m['latitude'])->toBe(-24.1884781)
        ->and($m['longitude'])->toBe(-65.300333);

    // Al menos 3 decimales: con menos el pin queda a más de 100 m.
    expect($m['text'])->toMatch('/^-?\d+\.\d{3,},-?\d+\.\d{3,}$/');
});

/*
 * Las ubicaciones YA guardadas tienen body null. El texto se deriva del
 * payload justamente para que el agente las vea sin migración.
 */
it('una ubicación vieja con body null igual llega con coordenadas', function () {
    $mensaje = ingerirUbicacion(['latitude' => -17.7548369, 'longitude' => -63.1973423]);

    // Como estaban las 5 que ya había en la base.
    $mensaje->forceFill(['body' => null])->save();

    $evento = app(AgentPayload::class)->event($mensaje->fresh());

    expect($evento['message']['text'])->toBe('-17.754837,-63.197342');

    $turno = collect($evento['history'])->firstWhere('type', 'location');

    expect($turno['content'])->toBe('-17.754837,-63.197342');
});
