<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Core\Services\Distribuidores;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/**
 * Un distribuidor se registra con rol Distribuidor + el grupo de su ciudad, y con eso
 * alcanza: su zona de reparto se carga sola.
 *
 * Antes la zona vivía en una tabla que nadie podía cargar desde el CRM. Un
 * distribuidor dado de alta después de crearla quedaba afuera y veía todo lo del
 * otro distribuidor de su ciudad.
 */
uses(DatabaseTransactions::class);

/** Una ciudad con su grupo homónimo, recién creados. @return array{ciudad:int, grupo:int} */
function zonasCiudad(string $nombre): array
{
    $ciudad = (int) DB::table('lead_pipelines')->insertGetId([
        'name' => $nombre, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $grupo = (int) DB::table('groups')->insertGetId([
        'name' => $nombre, 'description' => $nombre, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['ciudad' => $ciudad, 'grupo' => $grupo];
}

function zonasUsuario(string $rol, array $grupos): User
{
    $rolId = Role::where('name', $rol)->value('id');

    if (! $rolId) {
        test()->markTestSkipped("No existe el rol {$rol}.");
    }

    $user = User::create([
        'name'            => "Zonas {$rol} ".uniqid(),
        'email'           => 'zonas-'.uniqid().'@test.local',
        'password'        => bcrypt('secret'),
        'role_id'         => $rolId,
        'status'          => 1,
        'view_permission' => 'group',
    ]);

    $user->groups()->sync($grupos);

    return $user;
}

function zonasDe(int $userId): array
{
    return DB::table('distribuidor_zonas')->where('user_id', $userId)->orderBy('lead_pipeline_id')
        ->pluck('lead_pipeline_id')->map(fn ($id) => (int) $id)->all();
}

it('al crear un distribuidor con el grupo de su ciudad, queda registrada su zona', function () {
    $c = zonasCiudad('Ciudad Zonas '.uniqid());
    $user = zonasUsuario('Distribuidor', [$c['grupo']]);

    event('settings.user.create.after', $user);

    expect(zonasDe($user->id))->toBe([$c['ciudad']]);
});

it('dos distribuidores de la misma ciudad quedan registrados los dos', function () {
    $c = zonasCiudad('Ciudad Zonas '.uniqid());
    $a = zonasUsuario('Distribuidor', [$c['grupo']]);
    $b = zonasUsuario('Distribuidor', [$c['grupo']]);

    event('settings.user.create.after', $a);
    event('settings.user.create.after', $b);

    expect(DB::table('distribuidor_zonas')->where('lead_pipeline_id', $c['ciudad'])->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('al cambiarle el grupo, la zona sigue al grupo nuevo', function () {
    $vieja = zonasCiudad('Ciudad Zonas '.uniqid());
    $nueva = zonasCiudad('Ciudad Zonas '.uniqid());
    $user = zonasUsuario('Distribuidor', [$vieja['grupo']]);
    event('settings.user.create.after', $user);

    $user->groups()->sync([$nueva['grupo']]);
    event('settings.user.update.after', $user);

    expect(zonasDe($user->id))->toBe([$nueva['ciudad']]);
});

it('un grupo que no es ciudad no le borra la zona', function () {
    $c = zonasCiudad('Ciudad Zonas '.uniqid());
    $user = zonasUsuario('Distribuidor', [$c['grupo']]);
    event('settings.user.create.after', $user);

    $otro = (int) DB::table('groups')->insertGetId(['name' => 'Grupo suelto '.uniqid(), 'description' => '-', 'created_at' => now(), 'updated_at' => now()]);
    $user->groups()->sync([$otro]);
    event('settings.user.update.after', $user);

    expect(zonasDe($user->id))->toBe([$c['ciudad']]);
});

it('si deja de ser distribuidor, pierde la zona', function () {
    $c = zonasCiudad('Ciudad Zonas '.uniqid());
    $user = zonasUsuario('Distribuidor', [$c['grupo']]);
    event('settings.user.create.after', $user);

    $otroRol = Role::where('name', '!=', 'Distribuidor')->value('id');
    $user->update(['role_id' => $otroRol]);
    event('settings.user.update.after', $user->fresh());

    expect(zonasDe($user->id))->toBe([]);
});

it('un encargado con el grupo de una ciudad no pasa a ser distribuidor', function () {
    $c = zonasCiudad('Ciudad Zonas '.uniqid());
    $rol = Role::where('name', '!=', 'Distribuidor')->where('name', '!=', 'Administrador')->value('name');
    $user = zonasUsuario($rol, [$c['grupo']]);

    event('settings.user.create.after', $user);

    expect(zonasDe($user->id))->toBe([]);
});

it('registrado en Configuración, ve sólo lo suyo', function () {
    $c = zonasCiudad('Ciudad Zonas '.uniqid());
    $user = zonasUsuario('Distribuidor', [$c['grupo']]);
    event('settings.user.create.after', $user);

    test()->actingAs($user, 'user');

    expect(app(Distribuidores::class)->soloLoSuyo())->toBe($user->id);
});
