<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Regla: un lead creado SIN usuario en contexto (p.ej. la sync SMD/Dropbox por
 * cron) debe quedar en la cuenta "Sin asignar" (no-admin), NUNCA en el admin.
 * Esto evita que el tablero por-vendedor (que excluye admins) los oculte.
 */
beforeEach(function () {
    $this->personId = DB::table('persons')->value('id');

    $this->unassigned = DB::table('users')
        ->where('email', config('dashboard.unassigned_user.email'))
        ->first();
});

function makeBareLeadData($personId): array
{
    return [
        'title'       => 'Lead sync '.uniqid(),
        'entity_type' => 'leads',
        'person'      => ['id' => $personId],
    ];
}

it('crea el lead a nombre de "Sin asignar" cuando no hay usuario autenticado', function () {
    // Sin actingAs: no hay usuario en el guard (caso de la sync por cron).
    expect(auth()->guard('user')->check())->toBeFalse();
    expect($this->unassigned)->not->toBeNull();

    $lead = app(LeadRepository::class)->create(makeBareLeadData($this->personId));
    $lead->refresh();

    expect((int) $lead->user_id)->toBe((int) $this->unassigned->id);

    // Y la cuenta "Sin asignar" NO es admin (clave para que el tablero la muestre).
    $role = DB::table('roles')->where('id', $this->unassigned->role_id)->first();
    expect($role->name)->not->toStartWith('Admin');
});

it('respeta al usuario autenticado y no usa "Sin asignar" (sin regresión)', function () {
    $staff = User::create([
        'name'     => 'Recepcion '.uniqid(),
        'email'    => 'staff'.uniqid().'@test.com',
        'password' => bcrypt('secret'),
        'role_id'  => 2, // Recepcionista
        'status'   => 1,
    ]);

    $this->actingAs($staff, 'user');

    $lead = app(LeadRepository::class)->create(makeBareLeadData($this->personId));
    $lead->refresh();

    expect((int) $lead->user_id)->toBe((int) $staff->id);
    expect((int) $lead->user_id)->not->toBe((int) $this->unassigned->id);
});
