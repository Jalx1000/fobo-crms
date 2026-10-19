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

/*
|--------------------------------------------------------------------------
| Endpoints de la bandeja
|--------------------------------------------------------------------------
| Lo que se prueba es la API mobile, no la bandeja (que tiene sus tests):
| que cada ruta llegue con el usuario del token, aplique su alcance y quede
| a su nombre. Nada sale a WhatsApp: la cola está falseada.
*/

function conversacionMobile(array $attrs = []): object
{
    return \Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create(array_merge([
        'gateway'         => config('whatsapp.gateway'),
        'wa_phone'        => '+5917'.random_int(1000000, 9999999),
        'wa_name'         => 'Mobile '.uniqid(),
        'ai_enabled'      => false,
        'last_inbound_at' => now(),
        'last_message_at' => now(),
    ], $attrs));
}

function mensajeMobile(object $conversacion, array $attrs = []): object
{
    return \Webkul\Whatsapp\Models\MessageProxy::modelClass()::create(array_merge([
        'conversation_id' => $conversacion->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'Hola',
        'sender'          => 'contact',
        'status'          => 'delivered',
    ], $attrs));
}

/** Un asesor restringido, con un contacto propio (y su lead) y otro ajeno. */
function asesorRestringido(): array
{
    $yo = usuarioMobile(['whatsapp'], 'individual');
    $otro = usuarioMobile(['whatsapp'], 'individual');
    $etapa = DB::table('lead_pipeline_stages')->orderBy('id')->first();

    $persona = function (int $ownerId) use ($etapa) {
        $id = (int) DB::table('persons')->insertGetId(['name' => 'Contacto mobile '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

        DB::table('leads')->insert([
            'title'                  => 'Mobile (test)',
            'user_id'                => $ownerId,
            'person_id'              => $id,
            'lead_pipeline_id'       => $etapa->lead_pipeline_id,
            'lead_pipeline_stage_id' => $etapa->id,
            'status'                 => 1,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        return $id;
    };

    $miPersona = $persona($yo->id);
    $personaAjena = $persona($otro->id);

    return [
        'yo'            => $yo,
        'mi_persona'    => $miPersona,
        'persona_ajena' => $personaAjena,
        'mia'           => conversacionMobile(['person_id' => $miPersona]),
        'ajena'         => conversacionMobile(['person_id' => $personaAjena]),
    ];
}

beforeEach(function () {
    \Illuminate\Support\Facades\Queue::fake();
    \Illuminate\Support\Facades\Http::preventStrayRequests();
});

it('sin el permiso whatsapp, la bandeja responde 403 JSON', function () {
    test()->getJson('/api/v1/mobile/whatsapp/conversations', tokenMobile(usuarioMobile(['leads'])))
        ->assertForbidden()
        ->assertJsonPath('message', 'No tenés acceso al módulo de WhatsApp.');
});

it('lista sólo las conversaciones visibles para el usuario del token', function () {
    $e = asesorRestringido();

    $ids = collect(
        test()->getJson('/api/v1/mobile/whatsapp/conversations?search=Mobile&per_page=100', tokenMobile($e['yo']))
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'phone', 'unread_count', 'ai_enabled', 'handoff', 'preview', 'last_message_at']], 'total'])
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($e['mia']->id)->not->toContain($e['ajena']->id);
});

it('el hilo trae los mensajes, marca leído y con since sólo lo nuevo', function () {
    $user = usuarioMobile(['whatsapp']);
    $conv = conversacionMobile(['unread_count' => 2]);
    $viejo = mensajeMobile($conv, ['body' => 'viejo']);

    // `since` es inclusivo (>=) y tiene resolución de segundos: lo del mismo
    // segundo vuelve a llegar, y la app lo mezcla por id sin duplicar.
    $this->travel(2)->seconds();

    $primera = test()->getJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/messages", tokenMobile($user))
        ->assertOk()
        ->assertJsonStructure(['conversation', 'messages', 'server_time', 'capabilities', 'handoff', 'window', 'agent']);

    expect(collect($primera->json('messages'))->pluck('id'))->toContain($viejo->id)
        ->and($conv->fresh()->unread_count)->toBe(0);

    $this->travel(2)->seconds();
    $nuevo = mensajeMobile($conv, ['body' => 'nuevo']);

    $ids = collect(
        test()->getJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/messages?since=".urlencode($primera->json('server_time')), tokenMobile($user))
            ->assertOk()
            ->json('messages')
    )->pluck('id');

    expect($ids)->toContain($nuevo->id)->not->toContain($viejo->id);
});

it('una conversación ajena o inexistente da 404 en todos los endpoints con {id}', function () {
    $e = asesorRestringido();
    $h = tokenMobile($e['yo']);
    $id = $e['ajena']->id;

    test()->getJson("/api/v1/mobile/whatsapp/conversations/{$id}/messages", $h)->assertNotFound();
    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$id}/messages", ['text' => 'x'], $h)->assertNotFound();
    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$id}/location", ['latitude' => 1, 'longitude' => 1], $h)->assertNotFound();
    test()->patchJson("/api/v1/mobile/whatsapp/conversations/{$id}/agent", ['enabled' => true], $h)->assertNotFound();
    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$id}/handoff/claim", [], $h)->assertNotFound();
    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$id}/notes", ['comment' => 'x'], $h)->assertNotFound();
    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$id}/link-person", [], $h)->assertNotFound();
    test()->getJson("/api/v1/mobile/whatsapp/persons/{$e['persona_ajena']}", $h)->assertNotFound();
    test()->postJson("/api/v1/mobile/whatsapp/persons/{$e['persona_ajena']}/leads", ['title' => 'x'], $h)->assertNotFound();

    // Inexistente: la bandeja devolvería 200 con conversation null.
    test()->getJson('/api/v1/mobile/whatsapp/conversations/999999999/messages', $h)->assertNotFound();
});

it('el contador cuenta sólo lo visible', function () {
    $e = asesorRestringido();
    $e['mia']->update(['unread_count' => 3]);
    $e['ajena']->update(['unread_count' => 5]);
    conversacionMobile(['handoff_state' => 'assigned', 'assigned_user_id' => $e['yo']->id]);

    $antes = test()->getJson('/api/v1/mobile/whatsapp/unread-count', tokenMobile($e['yo']))->assertOk()->json('data');

    $e['mia']->update(['unread_count' => 0]);

    $despues = test()->getJson('/api/v1/mobile/whatsapp/unread-count', tokenMobile($e['yo']))->assertOk()->json('data');

    // Diferencias y no absolutos: la base es la real y la bolsa puede tener
    // derivaciones de verdad.
    expect($antes['messages'] - $despues['messages'])->toBe(3)
        ->and($antes['conversations'] - $despues['conversations'])->toBe(1)
        ->and($antes['mine'])->toBeGreaterThanOrEqual(1);
});

it('el texto sale a nombre del usuario del token, por la cola', function () {
    $user = usuarioMobile(['whatsapp']);
    $conv = conversacionMobile();

    $r = test()->postJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/messages", ['text' => 'Hola desde la app'], tokenMobile($user))
        ->assertOk()
        ->assertJsonPath('message.text', 'Hola desde la app')
        ->assertJsonPath('message.sender', 'human')
        ->assertJsonPath('message.status', 'queued');

    $mensaje = \Webkul\Whatsapp\Models\MessageProxy::modelClass()::find($r->json('message.id'));

    expect($mensaje->conversation_id)->toBe($conv->id)
        ->and((int) $mensaje->user_id)->toBe($user->id);

    \Illuminate\Support\Facades\Queue::assertPushed(\Webkul\Whatsapp\Jobs\SendMessage::class);
});

it('con el agente encendido no deja escribir a mano', function () {
    $conv = conversacionMobile(['ai_enabled' => true]);

    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/messages", ['text' => 'x'], tokenMobile(usuarioMobile(['whatsapp'])))
        ->assertUnprocessable();
});

it('envía un archivo y una ubicación', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $h = tokenMobile(usuarioMobile(['whatsapp']));
    $conv = conversacionMobile();

    test()->post("/api/v1/mobile/whatsapp/conversations/{$conv->id}/media", [
        'kind' => 'image',
        'file' => \Illuminate\Http\UploadedFile::fake()->image('foto.jpg'),
    ], $h + ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('message.type', 'image');

    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/location", [
        'latitude' => -17.78, 'longitude' => -63.18, 'label' => 'Sucursal Centro',
    ], $h)
        ->assertOk()
        ->assertJsonPath('message.type', 'location');
});

it('lista plantillas y envía una', function () {
    $catalogo = Mockery::mock(\Webkul\Whatsapp\Services\TemplateCatalog::class);
    $plantilla = ['name' => 'seguimiento', 'language' => 'es', 'body' => 'Hola {{1}}', 'variables' => 1, 'category' => 'UTILITY'];
    $catalogo->shouldReceive('all')->andReturn([$plantilla]);
    $catalogo->shouldReceive('find')->with('seguimiento', 'es')->andReturn($plantilla);
    $catalogo->shouldReceive('render')->andReturn('Hola María');
    app()->instance(\Webkul\Whatsapp\Services\TemplateCatalog::class, $catalogo);

    $h = tokenMobile(usuarioMobile(['whatsapp']));
    $conv = conversacionMobile(['last_inbound_at' => now()->subDays(3)]);

    test()->getJson('/api/v1/mobile/whatsapp/templates', $h)
        ->assertOk()
        ->assertJsonPath('templates.0.name', 'seguimiento');

    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/template", [
        'template' => 'seguimiento', 'language' => 'es', 'variables' => ['María'],
    ], $h)
        ->assertOk()
        ->assertJsonPath('message.type', 'template')
        ->assertJsonPath('message.text', 'Hola María');
});

it('apaga el agente, toma la derivación y la suelta, a nombre del usuario del token', function () {
    $user = usuarioMobile(['whatsapp']);
    $h = tokenMobile($user);
    $conv = conversacionMobile(['ai_enabled' => true, 'handoff_state' => 'requested', 'handoff_requested_at' => now()]);

    test()->patchJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/agent", ['enabled' => false], $h)
        ->assertOk()
        ->assertJsonPath('agent.enabled', false);

    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/handoff/claim", [], $h)
        ->assertOk()
        ->assertJsonPath('handoff.assigned_user.id', $user->id)
        ->assertJsonPath('handoff.is_mine', true);

    // Otro asesor llega tarde.
    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/handoff/claim", [], tokenMobile(usuarioMobile(['whatsapp'])))
        ->assertStatus(409);

    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$conv->id}/handoff/resolve", ['reactivate_ai' => false], $h)
        ->assertOk()
        ->assertJsonPath('handoff.open', false);
});

it('la nota queda a nombre del usuario del token', function () {
    $e = asesorRestringido();

    $r = test()->postJson("/api/v1/mobile/whatsapp/conversations/{$e['mia']->id}/notes", ['comment' => 'Llamar a las 18 h'], tokenMobile($e['yo']))
        ->assertOk()
        ->assertJsonPath('note.kind', 'note')
        ->assertJsonPath('note.author', $e['yo']->name);

    $actividad = (int) str_replace('note-', '', $r->json('note.id'));

    expect((int) DB::table('activities')->where('id', $actividad)->value('user_id'))->toBe($e['yo']->id);
});

it('ficha del contacto, pedido, vínculo y catálogos', function () {
    $e = asesorRestringido();
    $h = tokenMobile($e['yo']);

    test()->getJson("/api/v1/mobile/whatsapp/persons/{$e['mi_persona']}", $h)
        ->assertOk()
        ->assertJsonPath('person.id', $e['mi_persona']);

    test()->postJson("/api/v1/mobile/whatsapp/persons/{$e['mi_persona']}/leads", ['title' => 'Pedido desde la app'], $h)
        ->assertOk()
        ->assertJsonPath('lead.title', 'Pedido desde la app');

    $sinContacto = conversacionMobile(['handoff_state' => 'requested']);

    test()->postJson("/api/v1/mobile/whatsapp/conversations/{$sinContacto->id}/link-person", ['name' => 'Nuevo contacto'], $h)
        ->assertOk()
        ->assertJsonStructure(['person_id']);

    test()->getJson('/api/v1/mobile/whatsapp/stages', $h)->assertOk()->assertJsonStructure(['stages']);
    test()->getJson('/api/v1/mobile/whatsapp/cities', $h)->assertOk()->assertJsonStructure(['cities', 'none', 'total']);
    test()->getJson('/api/v1/mobile/whatsapp/products?search=a', $h)->assertOk()->assertJsonStructure(['products']);
    test()->getJson('/api/v1/mobile/whatsapp/quick-replies', $h)->assertOk()->assertJsonStructure(['quick_replies']);
});
