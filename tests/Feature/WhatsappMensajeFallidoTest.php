<?php

use Illuminate\Support\Facades\Mail;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Jobs\NotifyMensajeFallido;
use Webkul\Whatsapp\Mail\MensajeFallido;
use Webkul\Whatsapp\Models\Message;

/**
 * Cuando Meta rechaza un mensaje, el motivo llega por mail. Antes el webhook de
 * estados descartaba los `errors` y sólo quedaba la palabra "failed".
 */
function webhookDeEstadoFallido(array $errores): array
{
    return ['entry' => [['changes' => [['value' => ['statuses' => [[
        'id'     => 'wamid.fallido',
        'status' => 'failed',
        'errors' => $errores,
    ]]]]]]]];
}

it('el webhook de estados conserva el motivo que manda Meta', function () {
    $errores = [['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'Número sin WhatsApp']]];

    $estado = (new CloudApiGateway([]))->parseWebhook(webhookDeEstadoFallido($errores))->statuses[0];

    expect($estado->status)->toBe('failed')
        ->and($estado->errors)->toBe($errores);
});

it('arma un motivo legible con el código y el detalle', function () {
    expect(NotifyMensajeFallido::motivoDeMeta([
        ['code' => 131049, 'title' => 'Not delivered', 'error_data' => ['details' => 'Límite de marketing']],
    ]))->toBe('131049 — Not delivered: Límite de marketing')
        ->and(NotifyMensajeFallido::motivoDeMeta([]))->toContain('sin decir por qué');
});

it('manda el mail a las casillas configuradas', function () {
    Mail::fake();
    config(['whatsapp.fallos.emails' => 'uno@test.local, dos@test.local']);

    $mensaje = Message::query()->where('direction', 'outbound')->latest('id')->first();

    if (! $mensaje) {
        $this->markTestSkipped('No hay mensajes salientes en la base.');
    }

    (new NotifyMensajeFallido($mensaje->id, '131026 — Message undeliverable'))->handle();

    Mail::assertSent(MensajeFallido::class, fn ($mail) => $mail->hasTo('uno@test.local') && $mail->hasTo('dos@test.local'));
});

it('sin casillas configuradas no manda nada', function () {
    Mail::fake();
    config(['whatsapp.fallos.emails' => '']);

    (new NotifyMensajeFallido(1, 'x'))->handle();

    Mail::assertNothingSent();
});
