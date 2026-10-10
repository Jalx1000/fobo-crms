<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Webkul\User\Models\User;

/*
 * La API mobile de WhatsApp (api/v1/mobile/whatsapp).
 *
 * El token es de una persona, no del bot. Todo el módulo lee el guard "user",
 * así que lo que se prueba acá es que el usuario del token llegue a ese guard
 * —y que no se quede pegado a requests siguientes.
 *
 * Los tokens son reales (createToken + Bearer), no Sanctum::actingAs: lo que
 * importa es el camino que recorre la app, middleware por middleware.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 * Spec: tasks/spec-api-mobile-whatsapp.md
 */
beforeEach(fn () => DB::beginTransaction());

afterEach(fn () => DB::rollBack());

function tokenMobile(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test-mobile')->plainTextToken];
}

it('sin token responde 401', function () {
    test()->getJson('/api/v1/mobile/whatsapp/me')->assertUnauthorized();
});

it('/me devuelve al usuario del token', function () {
    $user = usuarioMobile(['whatsapp'], 'individual');

    test()->getJson('/api/v1/mobile/whatsapp/me', tokenMobile($user))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.view_permission', 'individual')
        ->assertJsonPath('data.can_whatsapp', true);
});

it('/me responde 200 sin el permiso, para que la app muestre "sin acceso"', function () {
    $user = usuarioMobile(['leads']);

    test()->getJson('/api/v1/mobile/whatsapp/me', tokenMobile($user))
        ->assertOk()
        ->assertJsonPath('data.can_whatsapp', false);
});

it('el usuario del token no queda logueado para la request siguiente', function () {
    $user = usuarioMobile(['whatsapp']);

    test()->getJson('/api/v1/mobile/whatsapp/me', tokenMobile($user))->assertOk();

    // Los guards viven en el contenedor del test; la app real arranca uno
    // nuevo por request. Olvidarlos simula eso: si quedara algo, sería sesión.
    app('auth')->forgetGuards();

    test()->getJson('/api/v1/mobile/whatsapp/me')->assertUnauthorized();
    expect(auth()->guard('user')->check())->toBeFalse();
});

it('cada asesor tiene su propio bucket, separado del agente', function () {
    config(['agent_api.mobile_rate_limit' => 120]);

    $limite = function (User $user) {
        $request = Request::create('/api/v1/mobile/whatsapp/me', 'GET');
        $request->setUserResolver(fn ($guard = null) => $guard === 'sanctum' ? $user : null);

        return RateLimiter::limiter('mobile-api')($request);
    };

    $a = $limite(usuarioMobile(['whatsapp']));
    $b = $limite(usuarioMobile(['whatsapp']));

    expect($a->maxAttempts)->toBe(120)
        ->and($a->key)->toStartWith('mobile-api:')
        ->and($a->key)->not->toBe($b->key);
});
