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
