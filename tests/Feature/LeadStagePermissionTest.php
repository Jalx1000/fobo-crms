<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * La suite corre contra la base real (no hay RefreshDatabase), así que estas
 * pruebas se envuelven en una transacción que se revierte al final. Nunca tocan
 * los roles ni los usuarios reales: crean los suyos.
 */
uses(DatabaseTransactions::class);

/**
 * Crea un rol custom con los permisos indicados y devuelve su id.
 */
function makeRole(array $permissions): int
{
    return (int) DB::table('roles')->insertGetId([
        'name'            => 'Test Etapa '.uniqid(),
        'description'     => 'Rol temporal de prueba',
        'permission_type' => 'custom',
        'permissions'     => json_encode($permissions),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

/**
 * Crea un usuario activo con ese rol y devuelve el modelo.
 */
function makeUserWithRole(int $roleId): User
{
    $id = (int) DB::table('users')->insertGetId([
        'name'       => 'Test Etapa '.uniqid(),
        'email'      => 'etapa-'.uniqid().'@test.local',
        'password'   => bcrypt('password'),
        'status'     => 1,
        'role_id'    => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::find($id);
}

/**
 * Un lead cualquiera junto a otra etapa de su mismo pipeline, para tener siempre
 * un destino válido al que moverlo.
 */
function leadWithAlternateStage(): array
{
    $lead = DB::table('leads')
        ->whereNotNull('lead_pipeline_stage_id')
        ->whereNotNull('lead_pipeline_id')
        ->first();

    $otherStageId = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $lead->lead_pipeline_id)
        ->where('id', '!=', $lead->lead_pipeline_stage_id)
        ->whereNotIn('code', ['won', 'lost'])
        ->value('id');

    return [$lead, $otherStageId];
}

/**
 * La etapa que la base tiene guardada ahora mismo para ese lead.
 */
function stageOf(int $leadId): int
{
    return (int) DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id');
}

beforeEach(function () {
    [$this->lead, $this->otherStageId] = leadWithAlternateStage();

    if (! $this->lead || ! $this->otherStageId) {
        $this->markTestSkipped('No hay un lead con una etapa alterna en su pipeline.');
    }
});

it('permite mover de etapa a un rol administrador', function () {
    test()->actingAs(getDefaultAdmin())
        ->put(route('admin.leads.stage.update', $this->lead->id), [
            'lead_pipeline_stage_id' => $this->otherStageId,
        ])
        ->assertOk();

    expect(stageOf($this->lead->id))->toBe($this->otherStageId);
});

it('permite mover de etapa a un rol custom con el permiso', function () {
    $user = makeUserWithRole(makeRole(['leads', 'leads.view', 'leads.edit', 'leads.stage_update']));

    test()->actingAs($user)
        ->put(route('admin.leads.stage.update', $this->lead->id), [
            'lead_pipeline_stage_id' => $this->otherStageId,
        ])
        ->assertOk();

    expect(stageOf($this->lead->id))->toBe($this->otherStageId);
});

it('rechaza el cambio de etapa a un rol custom sin el permiso', function () {
    $user = makeUserWithRole(makeRole(['leads', 'leads.view', 'leads.edit']));

    $original = stageOf($this->lead->id);

    test()->actingAs($user)
        ->put(route('admin.leads.stage.update', $this->lead->id), [
            'lead_pipeline_stage_id' => $this->otherStageId,
        ])
        ->assertStatus(401);

    expect(stageOf($this->lead->id))->toBe($original);
});

it('rechaza la actualizacion masiva de etapa a un rol custom sin el permiso', function () {
    $user = makeUserWithRole(makeRole(['leads', 'leads.view', 'leads.edit']));

    $original = stageOf($this->lead->id);

    test()->actingAs($user)
        ->post(route('admin.leads.mass_update'), [
            'indices' => [$this->lead->id],
            'value'   => $this->otherStageId,
        ])
        ->assertStatus(401);

    expect(stageOf($this->lead->id))->toBe($original);
});

it('deja editar el pedido sin cambiar la etapa cuando falta el permiso', function () {
    $user = makeUserWithRole(makeRole(['leads', 'leads.view', 'leads.edit']));

    $original = stageOf($this->lead->id);

    /**
     * El formulario de edición manda lead_pipeline_stage_id en un input oculto en
     * cada guardado. Sin el permiso el campo se ignora, pero la edición debe seguir
     * funcionando: rechazarla romperia el guardado normal para todos los asesores.
     */
    test()->actingAs($user)
        ->put(route('admin.leads.update', $this->lead->id), [
            'title'                  => $this->lead->title,
            'description'            => $this->lead->description,
            'lead_pipeline_stage_id' => $this->otherStageId,
        ])
        ->assertRedirect();

    expect(stageOf($this->lead->id))->toBe($original);
});

it('agrega el permiso a los roles que ya podian editar pedidos y es idempotente', function () {
    $roleId = makeRole(['leads', 'leads.view', 'leads.edit']);

    $sinEdit = makeRole(['leads', 'leads.view']);

    $migration = require base_path(
        'packages/Webkul/User/src/Database/Migrations/2026_08_25_120000_add_stage_update_permission_to_roles.php'
    );

    $migration->up();
    $migration->up();

    $permissions = json_decode(DB::table('roles')->where('id', $roleId)->value('permissions'), true);

    expect(array_count_values($permissions)['leads.stage_update'] ?? 0)->toBe(1);

    $sinPermiso = json_decode(DB::table('roles')->where('id', $sinEdit)->value('permissions'), true);

    expect($sinPermiso)->not->toContain('leads.stage_update');
});
