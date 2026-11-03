<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Core\Services\AsignacionDeAsesor;
use Webkul\User\Models\User;

/**
 * Sólo los administradores y los encargados con "Puede asignar asesor" eligen el
 * asesor de un pedido o de un cliente.
 *
 * La suite corre contra la base real, así que todo va en una transacción y los
 * usuarios, el cliente y el pedido son de la prueba.
 */
uses(DatabaseTransactions::class);

function asesorEncargado(bool $puedeAsignar): User
{
    $roleId = (int) DB::table('roles')->where('name', AsignacionDeAsesor::ROL_ENCARGADO)->value('id');

    $id = (int) DB::table('users')->insertGetId([
        'name'          => 'Test Asesor '.uniqid(),
        'email'         => 'asesor-'.uniqid().'@test.local',
        'password'      => bcrypt('password'),
        'status'        => 1,
        'role_id'       => $roleId,
        'puede_asignar' => $puedeAsignar,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return User::find($id);
}

beforeEach(function () {
    if (! DB::table('roles')->where('name', AsignacionDeAsesor::ROL_ENCARGADO)->exists()) {
        $this->markTestSkipped('No existe el rol Encargado.');
    }

    $this->duenoOriginal = asesorEncargado(false);
    $this->otro = asesorEncargado(false);

    $this->personId = (int) DB::table('persons')->insertGetId([
        'name'       => 'Cliente Asesor '.uniqid(),
        'user_id'    => $this->duenoOriginal->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stage = DB::table('lead_pipeline_stages')->whereNotIn('code', ['won', 'lost'])->first();

    $this->leadId = (int) DB::table('leads')->insertGetId([
        'title'                  => 'Pedido Asesor '.uniqid(),
        'status'                 => 1,
        'user_id'                => $this->duenoOriginal->id,
        'person_id'              => $this->personId,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stage->id,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
});

it('no deja que un encargado sin permiso cambie el asesor de un pedido', function () {
    test()->actingAs(asesorEncargado(false))
        ->putJson(route('admin.leads.attributes.update', $this->leadId), ['user_id' => $this->otro->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect((int) DB::table('leads')->where('id', $this->leadId)->value('user_id'))->toBe($this->duenoOriginal->id);
});

it('no deja que un encargado sin permiso cambie el asesor de un cliente ni le mueva los pedidos', function () {
    test()->actingAs(asesorEncargado(false))
        ->putJson(route('admin.contacts.persons.update', $this->personId), ['user_id' => $this->otro->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect((int) DB::table('persons')->where('id', $this->personId)->value('user_id'))->toBe($this->duenoOriginal->id)
        ->and((int) DB::table('leads')->where('id', $this->leadId)->value('user_id'))->toBe($this->duenoOriginal->id);
});

it('deja guardar el formulario si el asesor no cambia', function () {
    $servicio = app(AsignacionDeAsesor::class);

    test()->actingAs(asesorEncargado(false));

    $servicio->validar(['user_id' => (string) $this->duenoOriginal->id], $this->duenoOriginal->id);
    $servicio->validar(['title' => 'sin asesor en el request'], $this->duenoOriginal->id);

    expect(true)->toBeTrue();
});

it('en un alta deja no elegir asesor o elegirse a sí mismo, pero no elegir a otro', function () {
    $yo = asesorEncargado(false);
    $servicio = app(AsignacionDeAsesor::class);

    test()->actingAs($yo);

    $servicio->validar(['user_id' => ''], null, esAlta: true);
    $servicio->validar(['user_id' => $yo->id], null, esAlta: true);

    expect(fn () => $servicio->validar(['user_id' => $this->otro->id], null, esAlta: true))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('deja asignar a un encargado con permiso y a un administrador', function () {
    expect(app(AsignacionDeAsesor::class)->puede(asesorEncargado(true)->id))->toBeTrue()
        ->and(app(AsignacionDeAsesor::class)->puede(getDefaultAdmin()->id))->toBeTrue()
        ->and(app(AsignacionDeAsesor::class)->puede(asesorEncargado(false)->id))->toBeFalse();

    test()->actingAs(asesorEncargado(true))
        ->putJson(route('admin.leads.attributes.update', $this->leadId), ['user_id' => $this->otro->id])
        ->assertOk();
});
