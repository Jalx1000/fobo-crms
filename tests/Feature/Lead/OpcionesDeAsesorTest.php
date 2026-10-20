<?php

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\User\Models\User;

/**
 * La lista de asesores de Pedidos y Cotizaciones (lookup `users`), 20/10/2026:
 * un Supervisor con vista «individual» solo se veía a sí mismo (y la consulta
 * ni se ejecutaba) y no podía asignar a otro ejecutivo. Quien puede cambiar
 * el asesor (Administrador y Supervisores, por rol) elige entre todos.
 */
function opcionesDeAsesorComo(User $usuario): array
{
    test()->actingAs($usuario, 'user');

    return collect(app(AttributeRepository::class)->getLookUpOptions('users'))->pluck('name')->all();
}

it('un supervisor con vista individual puede elegir a cualquier ejecutivo', function () {
    $supervisor = User::where('name', 'Mariano Pinell')->firstOrFail();
    $bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();

    // Bulmaro como Supervisor con vista individual (se deshace al terminar).
    DB::table('users')->where('id', $bulmaro->id)->update(['role_id' => $supervisor->role_id, 'view_permission' => 'individual']);

    expect(opcionesDeAsesorComo($bulmaro->fresh()))->toContain('Bulmaro Vaca', 'Eduardo Lujan', 'Gabriela Marconi')
        ->and(opcionesDeAsesorComo($supervisor))->toContain('Eduardo Lujan');
});

it('un ejecutivo comercial con vista individual se ve solo a sí mismo', function () {
    expect(opcionesDeAsesorComo(User::where('name', 'Eduardo Lujan')->firstOrFail()))->toBe(['Eduardo Lujan']);
});
