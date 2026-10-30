<?php

/*
|--------------------------------------------------------------------------
| Alianza: credenciales de la clínica rechazadas
|--------------------------------------------------------------------------
| 2026-10-12: Alianza respondía 401 "Usuario o Contraseña incorrectos" y cada
| verificación (ahora también cada cita del formulario) volvía a intentar, lo
| que puede renovar su bloqueo. Tras un rechazo no se reintenta por un rato,
| salvo que cambie la clave en el .env.
*/

use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\Insurance\Drivers\AlianzaDriver;

beforeEach(function () {
    config([
        'services.alianza.login_url'        => 'https://alianza.test/api',
        'services.alianza.microservice_url' => 'https://alianza.test/gw',
        'services.alianza.user'             => 'cli.test',
        'services.alianza.password'         => 'clave-'.uniqid(),
        'services.alianza.timeout'          => 5,
    ]);

    $this->person = (object) ['id' => 0, 'name' => 'Test', 'leads' => collect()];
});

function alianzaLogins(): int
{
    return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'LoginUserProv'))->count();
}

it('un 401 de credenciales devuelve un mensaje claro y no reintenta en la siguiente verificación', function () {
    Http::fake(['alianza.test/api/LoginUserProv' => Http::response([
        'success' => false, 'data' => null, 'messageError' => 'Usuario o Contraseña incorrectos.',
    ], 401)]);

    $first = (new AlianzaDriver)->verify($this->person, '1234567');
    $second = (new AlianzaDriver)->verify($this->person, '7654321');

    expect($first['status'])->toBe('INDETERMINADO')
        ->and($first['message'])->toBe(AlianzaDriver::REJECTED_MESSAGE)
        ->and($second['message'])->toBe(AlianzaDriver::REJECTED_MESSAGE)
        ->and(alianzaLogins())->toBe(1);
});

it('el 200 sin token y con la clave marcada inválida también cuenta como rechazo', function () {
    Http::fake(['alianza.test/api/LoginUserProv' => Http::response([
        'success' => true, 'data' => ['accessToken' => '', 'webClientVtime' => [['nUsercode' => 0, 'sIndBlockedPass' => '2', 'sMessage' => '']]],
    ], 200)]);

    (new AlianzaDriver)->verify($this->person, '1234567');
    (new AlianzaDriver)->verify($this->person, '1234567');

    expect(alianzaLogins())->toBe(1);
});

it('cambiar la clave en el .env vuelve a intentar al toque', function () {
    Http::fake(['alianza.test/api/LoginUserProv' => Http::response(['success' => false, 'messageError' => 'Usuario o Contraseña incorrectos.'], 401)]);
    (new AlianzaDriver)->verify($this->person, '1234567');

    config(['services.alianza.password' => 'clave-nueva-'.uniqid()]);
    (new AlianzaDriver)->verify($this->person, '1234567');

    expect(alianzaLogins())->toBe(2);
});

it('una falla que no es de credenciales (Alianza caída) no bloquea los reintentos', function () {
    Http::fake(['alianza.test/api/LoginUserProv' => Http::response('error', 503)]);

    (new AlianzaDriver)->verify($this->person, '1234567');
    (new AlianzaDriver)->verify($this->person, '1234567');

    expect(alianzaLogins())->toBe(2);
});
