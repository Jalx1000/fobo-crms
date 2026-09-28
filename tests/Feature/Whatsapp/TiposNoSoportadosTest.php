<?php

use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;

/**
 * Los tipos que el inbox mostraba como «Tipo no soportado».
 *
 * `interactive` y `reaction` sí se reciben y se guardan bien: lo que faltaba era
 * exponerlos. Un mensaje entregado que el asesor no puede leer es peor que uno
 * que falla, porque nada avisa que se perdió información.
 */
function gateway(): CloudApiGateway
{
    return app(\Webkul\Whatsapp\Gateways\GatewayManager::class)->driver('cloud_api');
}

function sobre(array $mensaje): array
{
    return ['entry' => [['changes' => [['value' => [
        'contacts' => [['profile' => ['name' => 'Cliente']]],
        'messages' => [$mensaje],
    ]]]]]];
}

it('declara reaction entre lo que puede recibir', function () {
    $caps = gateway()->capabilities()->toArray();

    expect($caps['receive'])->toContain('reaction')
        ->and($caps['receive'])->toContain('interactive');
});

it('una reacción llega con el emoji como cuerpo', function () {
    // Sin cuerpo el hilo muestra un renglón en blanco y la vista previa de la
    // lista de conversaciones queda vacía.
    $lote = gateway()->parseWebhook(sobre([
        'id'       => 'wamid.TEST-REACT-'.uniqid(),
        'from'     => '59177132734',
        'type'     => 'reaction',
        'reaction' => ['message_id' => 'wamid.ORIGINAL', 'emoji' => '👍'],
    ]));

    expect($lote->messages)->toHaveCount(1);
    expect($lote->messages[0]->type)->toBe('reaction');
    expect($lote->messages[0]->body)->toBe('👍');
    expect($lote->messages[0]->raw['reaction']['message_id'])->toBe('wamid.ORIGINAL');
});

it('quitar una reacción se nombra en vez de quedar en blanco', function () {
    // Meta manda emoji vacío cuando el cliente retira su reacción: es un evento
    // real, no un mensaje corrupto.
    $lote = gateway()->parseWebhook(sobre([
        'id'       => 'wamid.TEST-UNREACT-'.uniqid(),
        'from'     => '59177132734',
        'type'     => 'reaction',
        'reaction' => ['message_id' => 'wamid.ORIGINAL', 'emoji' => ''],
    ]));

    expect($lote->messages[0]->body)->toBe('Quitó su reacción');
});

it('el toque de un botón llega con el título como cuerpo y el id aparte', function () {
    $lote = gateway()->parseWebhook(sobre([
        'id'          => 'wamid.TEST-BTN-'.uniqid(),
        'from'        => '59177132734',
        'type'        => 'interactive',
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'bolsas', 'title' => '🟢 Bolsas']],
    ]));

    expect($lote->messages[0]->body)->toBe('🟢 Bolsas');
    expect(gateway()->interactiveReply($lote->messages[0]->raw))
        ->toBe(['id' => 'bolsas', 'title' => '🟢 Bolsas']);
});

it('el inbox recibe las opciones que se ofrecieron y la que se eligió', function () {
    test()->actingAs(getDefaultAdmin());

    $inbox = app(\Webkul\Whatsapp\Http\Controllers\InboxController::class);

    $metodo = new ReflectionMethod($inbox, 'transform');
    $metodo->setAccessible(true);

    $modelo = \Webkul\Whatsapp\Models\MessageProxy::modelClass();

    $oferta = (new $modelo)->forceFill([
        'id'        => 1,
        'type'      => 'interactive',
        'direction' => 'outbound',
        'body'      => '¿De qué ciudad nos escribe?',
        'payload'   => ['interactive' => [
            'format'      => 'list',
            'list_button' => 'Ver opciones',
            'sections'    => [['rows' => [
                ['id' => 'sc', 'title' => 'Santa Cruz'],
                ['id' => 'lp', 'title' => 'La Paz'],
            ]]],
        ]],
    ]);

    $salida = $metodo->invoke($inbox, $oferta);

    expect($salida['interactive']['direccion'])->toBe('oferta');
    expect($salida['interactive']['formato'])->toBe('list');
    expect($salida['interactive']['opciones'])->toBe(['Santa Cruz', 'La Paz']);

    $respuesta = (new $modelo)->forceFill([
        'id'        => 2,
        'type'      => 'interactive',
        'direction' => 'inbound',
        'body'      => 'Santa Cruz',
        'payload'   => ['interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'sc', 'title' => 'Santa Cruz']]],
    ]);

    $salida = $metodo->invoke($inbox, $respuesta);

    expect($salida['interactive']['direccion'])->toBe('respuesta');
    expect($salida['interactive']['elegido'])->toBe('Santa Cruz');
});

it('el hilo tiene una rama para cada tipo que el gateway declara recibir', function () {
    /*
     * Regresión del bug entero: el backend recibía y guardaba bien, pero la
     * vista caía en «Tipo no soportado». Si mañana se agrega un tipo a las
     * capacidades sin rama en el hilo, esto lo detecta.
     */
    $html = file_get_contents(base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php'));

    foreach (gateway()->capabilities()->toArray()['receive'] as $tipo) {
        expect($html)->toContain("msg.type === '{$tipo}'");
    }
});
