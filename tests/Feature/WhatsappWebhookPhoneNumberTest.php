<?php

/*
| La app de Meta tiene suscritas varias cuentas (WABA) y todas mandan su webhook
| a la misma URL. El CRM tiene que procesar solo los mensajes del número
| configurado (WHATSAPP_PHONE_NUMBER_ID): si no, un mensaje al número de otra
| cuenta entra a esta BD y la respuesta sale desde el número equivocado.
*/

use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;

function webhookFor(?string $phoneNumberId): array
{
    $value = [
        'messaging_product' => 'whatsapp',
        'contacts'          => [['profile' => ['name' => 'Paciente'], 'wa_id' => '59170000001']],
        'messages'          => [[
            'id'   => 'wamid.'.($phoneNumberId ?? 'sin-metadata'),
            'from' => '59170000001',
            'type' => 'text',
            'text' => ['body' => 'Hola'],
        ]],
        'statuses' => [['id' => 'wamid.out-'.($phoneNumberId ?? 'x'), 'status' => 'delivered']],
    ];

    if ($phoneNumberId !== null) {
        $value['metadata'] = ['display_phone_number' => '59160000000', 'phone_number_id' => $phoneNumberId];
    }

    return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => $value]]]]];
}

function gatewayFor(?string $ourNumber): CloudApiGateway
{
    return app(CloudApiGateway::class, ['config' => array_merge(config('whatsapp.gateways.cloud_api'), ['phone_number_id' => $ourNumber])]);
}

it('procesa los mensajes y estados del número configurado', function () {
    $batch = gatewayFor('111')->parseWebhook(webhookFor('111'));

    expect($batch->messages)->toHaveCount(1)
        ->and($batch->statuses)->toHaveCount(1);
});

it('descarta mensajes y estados de otro número de la misma app de Meta', function () {
    $batch = gatewayFor('111')->parseWebhook(webhookFor('999'));

    expect($batch->messages)->toBeEmpty()
        ->and($batch->statuses)->toBeEmpty();
});

it('en un mismo webhook se queda solo con el cambio de su número', function () {
    $payload = webhookFor('111');
    $payload['entry'][] = webhookFor('999')['entry'][0];

    $batch = gatewayFor('111')->parseWebhook($payload);

    expect($batch->messages)->toHaveCount(1)
        ->and($batch->messages[0]->providerMessageId)->toBe('wamid.111');
});

it('sin metadata o sin número configurado no filtra (compatibilidad)', function () {
    expect(gatewayFor('111')->parseWebhook(webhookFor(null))->messages)->toHaveCount(1)
        ->and(gatewayFor(null)->parseWebhook(webhookFor('999'))->messages)->toHaveCount(1);
});
