<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\TemplateCatalog;

/**
 * Envío de plantillas desde el inbox.
 *
 * La suite corre contra la base real, así que todo va dentro de una transacción
 * que se revierte, y cada caso crea sus propios datos.
 *
 * El catálogo se siembra en el caché en vez de llamar a Meta: la lista real
 * tiene hoy una sola plantilla sin variables (`hello_world`), que no ejercita
 * la validación por posición — que es justamente el camino que más importa.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Cache::put('whatsapp.templates', [
        [
            'name'      => 'recordatorio_pedido',
            'language'  => 'es',
            'body'      => 'Hola {{1}}, tu pedido {{2}} está listo.',
            'variables' => 2,
            'category'  => 'UTILITY',
        ],
        [
            'name'      => 'hello_world',
            'language'  => 'en_US',
            'body'      => 'Welcome and congratulations!',
            'variables' => 0,
            'category'  => 'UTILITY',
        ],
    ], now()->addMinutes(5));

    Queue::fake();
});

function usuarioDeInbox(): User
{
    $id = (int) DB::table('users')->insertGetId([
        'name'       => 'Test Plantilla '.uniqid(),
        'email'      => 'plantilla-'.uniqid().'@test.local',
        'password'   => bcrypt('password'),
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::find($id);
}

function conversacionDePrueba(array $attrs = [])
{
    return ConversationProxy::modelClass()::create(array_merge([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Cliente de prueba',
        'status'     => 'open',
        'ai_enabled' => false,
    ], $attrs));
}

function enviarPlantilla(User $user, array $payload)
{
    return test()->actingAs($user, 'user')
        ->postJson(route('admin.whatsapp.send-template'), $payload);
}

// ---------------------------------------------------------------------------

it('cuenta las variables distintas del cuerpo, no las repeticiones', function () {
    $catalog = app(TemplateCatalog::class);

    $render = $catalog->render('Hola {{1}}, {{1}}: tu pedido {{2}}.', ['Ana', 'A-7']);

    expect($render)->toBe('Hola Ana, Ana: tu pedido A-7.');
});

it('rechaza una plantilla con menos variables de las que declara', function () {
    $conversacion = conversacionDePrueba();

    $response = enviarPlantilla(usuarioDeInbox(), [
        'conversation_id' => $conversacion->id,
        'template'        => 'recordatorio_pedido',
        'language'        => 'es',
        'variables'       => ['Ana'],
    ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'La plantilla necesita 2 variable(s) y llegaron 1.']);

    expect($conversacion->messages()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rechaza una variable vacía', function () {
    $conversacion = conversacionDePrueba();

    enviarPlantilla(usuarioDeInbox(), [
        'conversation_id' => $conversacion->id,
        'template'        => 'recordatorio_pedido',
        'language'        => 'es',
        'variables'       => ['Ana', '   '],
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Completá todas las variables.']);

    expect($conversacion->messages()->count())->toBe(0);
});

it('rechaza una plantilla que no está en el catálogo', function () {
    $conversacion = conversacionDePrueba();

    enviarPlantilla(usuarioDeInbox(), [
        'conversation_id' => $conversacion->id,
        'template'        => 'no_existe',
        'language'        => 'es',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Esa plantilla ya no está disponible.']);
});

it('no deja que un humano escriba por encima del agente activo', function () {
    $conversacion = conversacionDePrueba(['ai_enabled' => true]);

    enviarPlantilla(usuarioDeInbox(), [
        'conversation_id' => $conversacion->id,
        'template'        => 'hello_world',
        'language'        => 'en_US',
    ])->assertStatus(422);

    expect($conversacion->messages()->count())->toBe(0);
});

it('encola el mensaje con el payload que necesita el envío y el reporte', function () {
    $conversacion = conversacionDePrueba();

    enviarPlantilla(usuarioDeInbox(), [
        'conversation_id' => $conversacion->id,
        'template'        => 'recordatorio_pedido',
        'language'        => 'es',
        'variables'       => ['Ana', 'A-7'],
    ])->assertStatus(200);

    $mensaje = $conversacion->messages()->first();

    expect($mensaje->type)->toBe('template')
        ->and($mensaje->sender)->toBe('human')
        ->and($mensaje->status)->toBe('queued')
        // El preview que ve el asesor en el hilo, con las variables ya puestas.
        ->and($mensaje->body)->toBe('Hola Ana, tu pedido A-7 está listo.')
        // Y el payload, que es de dónde sale el envío real y el reporte.
        ->and($mensaje->payload['template'])->toBe('recordatorio_pedido')
        ->and($mensaje->payload['language'])->toBe('es')
        ->and($mensaje->payload['variables'])->toBe(['Ana', 'A-7']);

    Queue::assertPushed(SendMessage::class);
});

it('devuelve 501, y no un 200 mentiroso, si el canal no manda plantillas', function () {
    config()->set('whatsapp.gateway', 'kommo');

    $conversacion = conversacionDePrueba(['gateway' => 'kommo']);

    enviarPlantilla(usuarioDeInbox(), [
        'conversation_id' => $conversacion->id,
        'template'        => 'hello_world',
        'language'        => 'en_US',
    ])->assertStatus(501);

    expect($conversacion->messages()->count())->toBe(0);
});

it('no ofrece hello_world aunque Meta la tenga aprobada', function () {
    config([
        'whatsapp.gateways.cloud_api.waba_id' => 'waba-test',
        'whatsapp.gateways.cloud_api.token'   => 'token-test',
    ]);

    \Illuminate\Support\Facades\Http::fake([
        'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['data' => [
            ['name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'Welcome and congratulations!']]],
            ['name' => 'recordatorio_pedido', 'language' => 'es', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']]],
        ]]),
    ]);

    $nombres = collect(app(TemplateCatalog::class)->all(fresh: true))->pluck('name');

    expect($nombres)->toContain('recordatorio_pedido')
        ->and($nombres)->not->toContain('hello_world');
});
