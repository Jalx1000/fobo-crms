<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/*
 * El módulo de WhatsApp respeta el rol.
 *
 * La pestaña "WhatsApp" se dibujaba en la vista de lead y de contacto para
 * CUALQUIER usuario, sin mirar el permiso —el menú lateral sí lo miraba, así
 * que el mismo usuario tenía o no tenía el módulo según por dónde entrara— y
 * las rutas del módulo no exigían nada más que estar autenticado: bastaba
 * pedir /whatsapp/thread para leer la conversación completa de un cliente.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo va dentro de una
 * transacción y no se toca ningún rol real.
 */
beforeEach(fn () => DB::beginTransaction());

afterEach(fn () => DB::rollBack());

/**
 * Un usuario con un rol a medida que tiene exactamente los permisos que se le
 * pasen. No se reutiliza ningún rol real: si mañana a "Encargado" le dan
 * WhatsApp, este test no puede empezar a mentir.
 */
function usuarioConPermisos(array $permisos): User
{
    $roleId = (int) DB::table('roles')->insertGetId([
        'name'            => 'Rol de prueba '.uniqid(),
        'description'     => 'Temporal, sólo para tests',
        'permission_type' => 'custom',
        'permissions'     => json_encode($permisos),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $userId = (int) DB::table('users')->insertGetId([
        'name'       => 'Test ACL '.uniqid(),
        'email'      => 'acl-'.uniqid().'@test.local',
        'password'   => bcrypt('password'),
        'status'     => 1,
        'role_id'    => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::find($userId);
}

it('un rol sin el permiso no puede leer una conversación', function () {
    $usuario = usuarioConPermisos(['dashboard', 'leads', 'leads.view']);

    test()->actingAs($usuario, 'user')
        ->getJson(route('admin.whatsapp.thread', ['phone' => '59176616013']))
        ->assertStatus(403);
});

it('un rol sin el permiso no puede escribirle a un cliente', function () {
    $usuario = usuarioConPermisos(['dashboard', 'leads', 'leads.view']);

    test()->actingAs($usuario, 'user')
        ->postJson(route('admin.whatsapp.send'), ['phone' => '59176616013', 'text' => 'hola'])
        ->assertStatus(403);
});

/*
 * La bandeja (admin.whatsapp.index) es la ÚNICA ruta que ya estaba cubierta:
 * figura en Config/acl.php, y el Bouncer de Krayin la corta con 401 antes de
 * llegar a nuestro middleware. Las otras 21 no estaban en esa lista y por eso
 * quedaban abiertas.
 */
it('un rol sin el permiso tampoco entra a la bandeja', function () {
    $usuario = usuarioConPermisos(['dashboard', 'leads', 'leads.view']);

    test()->actingAs($usuario, 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertStatus(401);
});

it('un rol con el permiso sí entra', function () {
    $usuario = usuarioConPermisos(['dashboard', 'whatsapp']);

    test()->actingAs($usuario, 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertStatus(200);
});

it('la pestaña de WhatsApp no se dibuja para un rol sin el permiso', function () {
    $usuario = usuarioConPermisos([
        'dashboard', 'leads', 'leads.view', 'contacts', 'contacts.persons', 'contacts.persons.view',
    ]);

    $lead = Webkul\Lead\Models\Lead::query()->first();

    if (! $lead) {
        test()->markTestSkipped('No hay leads en la base para renderizar la vista.');
    }

    $html = test()->actingAs($usuario, 'user')
        ->get(route('admin.leads.view', $lead->id))
        ->assertStatus(200)
        ->getContent();

    // Ni la pestaña ni el componente del inbox.
    expect($html)->not->toContain('v-whatsapp-inbox');
});

it('la pestaña sí se dibuja para un administrador', function () {
    $lead = Webkul\Lead\Models\Lead::query()->first();

    if (! $lead) {
        test()->markTestSkipped('No hay leads en la base para renderizar la vista.');
    }

    $html = test()->actingAs(getDefaultAdmin(), 'user')
        ->get(route('admin.leads.view', $lead->id))
        ->assertStatus(200)
        ->getContent();

    expect($html)->toContain('v-whatsapp-inbox');
});

// ---------------------------------------------------------------------------
// Visibilidad del fallo del agente
// ---------------------------------------------------------------------------

it('registra el rechazo del agente con su status y su cuerpo', function () {
    \Illuminate\Support\Facades\Config::set('whatsapp.ai.enabled', true);
    \Illuminate\Support\Facades\Config::set('whatsapp.ai.webhook_url', 'https://agente.test/api/v1/crm/webhook');
    \Illuminate\Support\Facades\Config::set('whatsapp.ai.token', 'token-equivocado');

    \Illuminate\Support\Facades\Http::fake([
        'agente.test/*' => \Illuminate\Support\Facades\Http::response(['detail' => 'Unauthorized'], 401),
    ]);

    $conv = \Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Cliente agente',
        'status'     => 'open',
        'ai_enabled' => true,
    ]);

    $msg = \Webkul\Whatsapp\Models\MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'Hola',
        'status'          => 'delivered',
        'sender'          => 'contact',
    ]);

    \Illuminate\Support\Facades\Log::spy();

    /*
     * El fallo era invisible: con QUEUE_CONNECTION=sync el job corre dentro del
     * request del webhook, la excepción de throw() subía por ahí y el webhook
     * igual contestaba 200 a Meta. El agente nunca se enteraba y no quedaba una
     * línea en el log — costó una sesión entera de diagnóstico.
     */
    try {
        (new \Webkul\Whatsapp\Jobs\NotifyAgent($msg->id))->handle(app(\Webkul\Whatsapp\Services\AgentPayload::class));
    } catch (\Throwable $e) {
        // throw() sigue reventando a propósito: el job debe poder reintentarse.
    }

    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
        ->withArgs(fn ($mensaje, $contexto = []) => $mensaje === 'WhatsApp: el agente rechazó el evento'
            && ($contexto['status'] ?? null) === 401
            && str_contains($contexto['respuesta'] ?? '', 'Unauthorized')
            && ($contexto['conversation_id'] ?? null) === $conv->id);
});
