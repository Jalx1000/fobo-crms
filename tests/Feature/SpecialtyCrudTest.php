<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Webkul\Doctor\Models\Specialty;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Este test cubre el flujo completo de administración de Especialidades Médicas.
 * Se utiliza el usuario administrador (ID 1) para las pruebas.
 */
beforeEach(function () {
    // Obtenemos el usuario administrador existente
    $this->adminUser = User::find(1);
});

it('permite al administrador crear una nueva especialidad', function () {
    $specialtyData = [
        'name'        => 'Especialidad Test '.uniqid(),
        'description' => 'Descripción de prueba profesional',
    ];

    actingAs($this->adminUser, 'user')
        ->post(route('admin.specialties.store'), $specialtyData)
        ->assertRedirect(route('admin.specialties.index'))
        ->assertSessionHas('success', 'Especialidad creada correctamente');

    $this->assertDatabaseHas('specialties', [
        'name' => $specialtyData['name'],
    ]);
});

it('valida que el nombre de la especialidad sea único', function () {
    $existingName = 'Ortodoncia'; // Asumimos que existe por el negocio

    // Si no existe, lo creamos para el test
    DB::table('specialties')->updateOrInsert(
        ['name' => $existingName],
        ['slug' => 'ortodoncia', 'created_at' => now(), 'updated_at' => now()]
    );

    $specialtyData = [
        'name'        => $existingName,
        'description' => 'Intento duplicado',
    ];

    actingAs($this->adminUser, 'user')
        ->post(route('admin.specialties.store'), $specialtyData)
        ->assertSessionHasErrors(['name']);
});

it('permite editar una especialidad existente', function () {
    $specialty = Specialty::first() ?: Specialty::create([
        'name'        => 'Temp Specialty',
        'slug'        => 'temp-specialty',
        'description' => 'Old description',
    ]);

    $updatedData = [
        'name'        => 'Nombre Editado '.$specialty->id,
        'description' => 'Nueva descripción editada',
    ];

    actingAs($this->adminUser, 'user')
        ->put(route('admin.specialties.update', $specialty->id), $updatedData)
        ->assertRedirect(route('admin.specialties.index'));

    $this->assertDatabaseHas('specialties', [
        'id'   => $specialty->id,
        'name' => $updatedData['name'],
    ]);
});

it('permite eliminar una especialidad que no tiene doctores asociados', function () {
    $specialty = Specialty::create([
        'name'        => 'Para Eliminar '.uniqid(),
        'slug'        => 'para-eliminar',
        'description' => 'Será borrada',
    ]);

    actingAs($this->adminUser, 'user')
        ->delete(route('admin.specialties.delete', $specialty->id))
        ->assertStatus(200)
        ->assertJson(['message' => 'Especialidad eliminada correctamente']);

    $this->assertDatabaseMissing('specialties', ['id' => $specialty->id]);
});

it('retorna error 404 al intentar editar una especialidad inexistente', function () {
    actingAs($this->adminUser, 'user')
        ->get(route('admin.specialties.edit', 999999))
        ->assertStatus(404);
});
