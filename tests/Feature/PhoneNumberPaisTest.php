<?php

use Webkul\Core\Services\PhoneNumber;

/*
 * Los números cargados sin código de país salían como +388…, y Meta rechazaba la
 * plantilla: 21 de las 27 fallidas reales de Sensia. No toca la base.
 */
it('completa los números argentinos cargados a mano', function (string $cargado) {
    config(['app.phone_default_country' => 'AR']);

    expect(PhoneNumber::normalize($cargado))->toBe('+5493885763067');
})->with([
    'sin código'     => '3885763067',
    'con guiones'    => '388 576-3067',
    'con cero'       => '03885763067',
    'con 54 sin 9'   => '543885763067',
    'completo'       => '5493885763067',
    'completo con +' => '+5493885763067',
]);

it('no toca los números de Bolivia', function (string $numero, string $esperado) {
    config(['app.phone_default_country' => 'AR']);

    expect(PhoneNumber::normalize($numero))->toBe($esperado);
})->with([
    ['76616013', '+76616013'],
    ['59176616013', '+59176616013'],
    ['+59176616013', '+59176616013'],
]);

it('no toca lo que ya viene con +, para seguir encontrando las conversaciones guardadas', function () {
    config(['app.phone_default_country' => 'AR']);

    expect(PhoneNumber::normalize('+3885763067'))->toBe('+3885763067');
});

it('no adivina los casos ambiguos', function () {
    config(['app.phone_default_country' => 'AR']);

    expect(PhoneNumber::normalize('388154123456'))->toBe('+388154123456');
});

it('sin la variable los números quedan como se cargaron', function () {
    config(['app.phone_default_country' => null]);

    expect(PhoneNumber::normalize('3885763067'))->toBe('+3885763067')
        ->and(PhoneNumber::normalize(''))->toBe('');
});

it('sigue reconociendo el número viejo y el corregido como la misma línea', function () {
    expect(PhoneNumber::same('+3885763067', '+5493885763067'))->toBeTrue();
});
