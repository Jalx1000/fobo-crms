<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * El tablero debe excluir a TODOS los usuarios con rol Administrador de los
 * desgloses por usuario (leads por usuario, ventas, tiempo de respuesta).
 */
it('excluye del tablero a los usuarios con rol Administrador', function () {
    $adminRole = Role::create([
        'name'            => 'Administrator',
        'description'     => 'Admin',
        'permission_type' => 'all',
    ]);

    $staffRole = Role::create([
        'name'            => 'Recepcionista',
        'description'     => 'Staff',
        'permission_type' => 'custom',
        'permissions'     => [],
    ]);

    $admin = User::create([
        'name'            => 'Admin Excluido '.uniqid(),
        'email'           => 'admin'.uniqid().'@test.com',
        'password'        => bcrypt('secret'),
        'role_id'         => $adminRole->id,
        'status'          => 1,
        // Visión global (como un admin real) para que el listado no se limite a sí mismo.
        'view_permission' => 'global',
    ]);

    $staff = User::create([
        'name'     => 'Recepcion Visible '.uniqid(),
        'email'    => 'staff'.uniqid().'@test.com',
        'password' => bcrypt('secret'),
        'role_id'  => $staffRole->id,
        'status'   => 1,
    ]);

    $response = $this->actingAs($admin, 'user')
        ->getJson(route('admin.dashboard.stats', [
            'type'  => 'leads-by-users',
            'start' => now()->subDays(29)->format('Y-m-d'),
            'end'   => now()->format('Y-m-d'),
        ]))
        ->assertStatus(200);

    $labels = $response->json('statistics.labels');

    expect($labels)->not->toContain($admin->name);
    expect($labels)->toContain($staff->name);
});
