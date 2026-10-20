<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\VisibilidadLeads;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/**
 * El alta de un distribuidor en Configuración → Usuarios.
 *
 * Dos datos separados, que antes el grupo mezclaba:
 *   "Distribuye en"  -> en qué ciudades reparte (al menos una).
 *   permiso + grupo  -> qué ve: individual, lo suyo; grupo, lo de su EMPRESA.
 *
 * Con el grupo haciendo las dos cosas, cinco distribuidores de Palpalá en el grupo
 * "Palpalá" veían lo de los demás.
 */
uses(DatabaseTransactions::class);

function zonasCiudad(): int
{
    return (int) DB::table('lead_pipelines')->insertGetId([
        'name' => 'Ciudad Zonas '.uniqid(), 'is_default' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function zonasGrupo(string $nombre): int
{
    return (int) DB::table('groups')->insertGetId([
        'name' => $nombre, 'description' => $nombre, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function zonasRol(string $nombre = 'Distribuidor'): int
{
    return (int) (Role::where('name', $nombre)->value('id') ?: test()->markTestSkipped("No existe el rol {$nombre}."));
}

/** El formulario como lo manda la pantalla. */
function zonasFormulario(array $cambios = []): array
{
    return array_merge([
        'name'             => 'Distribuidor zonas '.uniqid(),
        'email'            => 'zonas-'.uniqid().'@test.local',
        'password'         => 'secret123',
        'confirm_password' => 'secret123',
        'role_id'          => zonasRol(),
        'status'           => 1,
        'view_permission'  => 'individual',
    ], $cambios);
}

function zonasDe(int $userId): array
{
    return DB::table('distribuidor_zonas')->where('user_id', $userId)->orderBy('lead_pipeline_id')
        ->pluck('lead_pipeline_id')->map(fn ($id) => (int) $id)->all();
}

function zonasAdmin()
{
    return test()->actingAs(getDefaultAdmin(), 'user');
}

it('guarda las ciudades elegidas en "Distribuye en"', function () {
    $a = zonasCiudad();
    $b = zonasCiudad();

    $id = zonasAdmin()->postJson(route('admin.settings.users.store'), zonasFormulario(['ciudades_distribucion' => [$a, $b]]))
        ->assertOk()->json('data.id');

    expect(zonasDe($id))->toBe([$a, $b]);
});

it('no deja crear un distribuidor sin ciudad', function () {
    zonasAdmin()->postJson(route('admin.settings.users.store'), zonasFormulario())
        ->assertStatus(422)
        ->assertJsonValidationErrors('ciudades_distribucion');
});

it('no deja darle permiso Global a un distribuidor', function () {
    zonasAdmin()->postJson(route('admin.settings.users.store'), zonasFormulario([
        'view_permission' => 'global', 'ciudades_distribucion' => [zonasCiudad()],
    ]))->assertStatus(422)->assertJsonValidationErrors('view_permission');
});

it('no deja usar como empresa un grupo que se llama como una ciudad', function () {
    $ciudad = zonasCiudad();
    $grupo = zonasGrupo((string) DB::table('lead_pipelines')->where('id', $ciudad)->value('name'));

    zonasAdmin()->postJson(route('admin.settings.users.store'), zonasFormulario([
        'view_permission' => 'group', 'groups' => [$grupo], 'ciudades_distribucion' => [$ciudad],
    ]))->assertStatus(422)->assertJsonValidationErrors('groups[]');
});

it('a un usuario que no es distribuidor no le pide ciudad ni le guarda zonas', function () {
    $rol = Role::where('name', '!=', 'Distribuidor')->value('id');

    $id = zonasAdmin()->postJson(route('admin.settings.users.store'), zonasFormulario([
        'role_id' => $rol, 'view_permission' => 'global', 'ciudades_distribucion' => [zonasCiudad()],
    ]))->assertOk()->json('data.id');

    expect(zonasDe($id))->toBe([]);
});

it('al editar, las zonas quedan exactamente como en el formulario', function () {
    $vieja = zonasCiudad();
    $nueva = zonasCiudad();
    $form = zonasFormulario(['ciudades_distribucion' => [$vieja]]);

    $id = zonasAdmin()->postJson(route('admin.settings.users.store'), $form)->assertOk()->json('data.id');

    zonasAdmin()->putJson(route('admin.settings.users.update', $id), array_merge($form, ['ciudades_distribucion' => [$nueva]]))
        ->assertOk();

    expect(zonasDe($id))->toBe([$nueva])
        ->and(zonasAdmin()->getJson(route('admin.settings.users.edit', $id))->json('data.ciudades_distribucion'))->toBe([$nueva]);
});

it('si deja de ser distribuidor, pierde las zonas', function () {
    $form = zonasFormulario(['ciudades_distribucion' => [zonasCiudad()]]);
    $id = zonasAdmin()->postJson(route('admin.settings.users.store'), $form)->assertOk()->json('data.id');

    zonasAdmin()->putJson(route('admin.settings.users.update', $id), array_merge($form, [
        'role_id' => Role::where('name', '!=', 'Distribuidor')->value('id'), 'view_permission' => 'global',
    ]))->assertOk();

    expect(zonasDe($id))->toBe([]);
});

// ─────────────────────────── qué ve cada uno ───────────────────────────

/** Un distribuidor de la ciudad, con permiso y grupos dados. */
function zonasDistribuidor(int $ciudad, string $permiso, array $grupos = []): User
{
    $user = User::create([
        'name'    => 'Dist '.uniqid(), 'email' => 'dist-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        'role_id' => zonasRol(), 'status' => 1, 'view_permission' => $permiso,
    ]);
    $user->groups()->sync($grupos);
    DB::table('distribuidor_zonas')->insert(['lead_pipeline_id' => $ciudad, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

    return $user;
}

function zonasLeadDe(User $dueno, int $ciudad): object
{
    return (object) ['user_id' => $dueno->id, 'lead_pipeline_id' => $ciudad];
}

it('con permiso Individual no ve el pedido de un compañero de empresa', function () {
    $ciudad = zonasCiudad();
    $empresa = zonasGrupo('Empresa '.uniqid());
    $yo = zonasDistribuidor($ciudad, 'individual', [$empresa]);
    $companero = zonasDistribuidor($ciudad, 'individual', [$empresa]);

    test()->actingAs($yo, 'user');

    expect(VisibilidadLeads::puedeVer(zonasLeadDe($yo, $ciudad)))->toBeTrue()
        ->and(VisibilidadLeads::puedeVer(zonasLeadDe($companero, $ciudad)))->toBeFalse();
});

it('con permiso Grupo ve lo de su empresa y no lo de otra empresa de la misma ciudad', function () {
    $ciudad = zonasCiudad();
    $empresa = zonasGrupo('Empresa '.uniqid());
    $yo = zonasDistribuidor($ciudad, 'group', [$empresa]);
    $companero = zonasDistribuidor($ciudad, 'individual', [$empresa]);
    $otraEmpresa = zonasDistribuidor($ciudad, 'individual', [zonasGrupo('Otra '.uniqid())]);

    test()->actingAs($yo, 'user');

    expect(VisibilidadLeads::puedeVer(zonasLeadDe($companero, $ciudad)))->toBeTrue()
        ->and(VisibilidadLeads::puedeVer(zonasLeadDe($otraEmpresa, $ciudad)))->toBeFalse();
});
