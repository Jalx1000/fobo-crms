<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/*
 * Las notas `admin_only` (las que deja la auditoría de datos) quedan
 * registradas en el historial, pero sólo las ve un rol con acceso total.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo va dentro de una
 * transacción y no se toca ningún rol ni usuario real.
 */
beforeEach(fn () => DB::beginTransaction());

afterEach(fn () => DB::rollBack());

function usuarioConRol(string $permissionType): User
{
    $roleId = (int) DB::table('roles')->insertGetId([
        'name'            => 'Rol de prueba '.uniqid(),
        'description'     => 'Temporal, sólo para tests',
        'permission_type' => $permissionType,
        'permissions'     => json_encode(['leads', 'leads.view', 'contacts', 'contacts.persons', 'contacts.persons.view']),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    return User::find((int) DB::table('users')->insertGetId([
        'name'       => 'Test notas '.uniqid(),
        'email'      => 'notas-'.uniqid().'@test.local',
        'password'   => bcrypt('password'),
        'status'     => 1,
        'role_id'    => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

/** Crea dos notas en el lead y el contacto: una normal y una de auditoría. */
function notasDePrueba(): array
{
    $lead = DB::table('leads')->whereNotNull('person_id')->orderBy('id')->first();

    $ids = [];
    foreach (['normal' => false, 'auditoria' => true] as $tipo => $adminOnly) {
        $id = DB::table('activities')->insertGetId([
            'type' => 'note', 'comment' => "Nota {$tipo} ".uniqid(), 'is_done' => 1,
            'admin_only' => $adminOnly, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => $lead->id]);
        DB::table('person_activities')->insert(['activity_id' => $id, 'person_id' => $lead->person_id]);
        $ids[$tipo] = $id;
    }

    return [$lead, $ids];
}

function idsDe($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

it('un asesor no ve las notas de auditoría del lead ni del contacto', function () {
    [$lead, $ids] = notasDePrueba();
    test()->actingAs(usuarioConRol('custom'), 'user');

    foreach ([route('admin.leads.activities.index', $lead->id), route('admin.contacts.persons.activities.index', $lead->person_id)] as $url) {
        $vistas = idsDe(test()->getJson($url)->assertOk());

        expect($vistas)->toContain($ids['normal'])->not->toContain($ids['auditoria']);
    }
});

it('un administrador ve las notas de auditoría', function () {
    [$lead, $ids] = notasDePrueba();
    test()->actingAs(usuarioConRol('all'), 'user');

    foreach ([route('admin.leads.activities.index', $lead->id), route('admin.contacts.persons.activities.index', $lead->person_id)] as $url) {
        expect(idsDe(test()->getJson($url)->assertOk()))->toContain($ids['normal'], $ids['auditoria']);
    }
});

it('sin usuario (consola, colas) se ve todo el historial', function () {
    [, $ids] = notasDePrueba();

    expect(\Webkul\Activity\Models\Activity::whereIn('id', $ids)->count())->toBe(2);
});
