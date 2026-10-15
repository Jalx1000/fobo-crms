<?php

namespace Tests\Feature;

use Webkul\Doctor\Models\Doctor;
use Webkul\Doctor\Models\Specialty;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Este test cubre el flujo completo de administración de Doctores.
 * Valida la creación, edición, asignación de especialidades y atributos personalizados (rango de edad).
 */
beforeEach(function () {
    $this->adminUser = User::find(1);

    // Aseguramos tener al menos una especialidad
    $this->specialty = Specialty::first() ?: Specialty::create([
        'name' => 'Especialidad Test',
        'slug' => 'especialidad-test',
    ]);
});

it('permite al administrador crear un nuevo doctor con varias especialidades y rango de edad', function () {
    // Creamos especialidades adicionales para la prueba
    $spec1 = Specialty::create(['name' => 'Especialidad A '.uniqid(), 'slug' => 'spec-a-'.uniqid()]);
    $spec2 = Specialty::create(['name' => 'Especialidad B '.uniqid(), 'slug' => 'spec-b-'.uniqid()]);

    $doctorData = [
        'name'            => 'Doctor Multiespecialidad '.uniqid(),
        'email'           => 'multi.'.uniqid().'@odontoking.com',
        'specialties'     => [$spec1->id, $spec2->id],
        'is_active'       => 1,
        'age_range_min'   => 18,
        'age_range_max'   => 65,
        'age_range_notes' => 'Atención adultos',
    ];

    actingAs($this->adminUser, 'user')
        ->post(route('admin.doctor.store'), $doctorData)
        ->assertRedirect(route('admin.doctor.index'));

    $doctor = Doctor::where('email', $doctorData['email'])->first();

    // Validar que tenga AMBAS especialidades vinculadas
    expect($doctor->specialties)->toHaveCount(2);
    $specialtyIds = $doctor->specialties->pluck('id')->toArray();
    expect($specialtyIds)->toContain($spec1->id);
    expect($specialtyIds)->toContain($spec2->id);
});

it('valida que el nombre del doctor sea único', function () {
    $existingDoctor = Doctor::first() ?: Doctor::create(['name' => 'Doctor Unico', 'email' => 'unique@test.com']);

    $doctorData = [
        'name'  => $existingDoctor->name,
        'email' => 'otro@test.com',
    ];

    actingAs($this->adminUser, 'user')
        ->post(route('admin.doctor.store'), $doctorData)
        ->assertSessionHasErrors(['name']);
});

it('permite editar un doctor y su rango de edad', function () {
    $doctor = Doctor::create([
        'name'  => 'Doctor para Editar '.uniqid(),
        'email' => 'edit.'.uniqid().'@test.com',
    ]);

    $updatedData = [
        'name'            => 'Nombre Editado '.$doctor->id,
        'email'           => $doctor->email,
        'age_range_min'   => 0,
        'age_range_max'   => 99,
        'age_range_notes' => 'Atiende a todos',
    ];

    actingAs($this->adminUser, 'user')
        ->put(route('admin.doctor.update', $doctor->id), $updatedData)
        ->assertRedirect(route('admin.doctor.index'));

    $doctor->refresh();
    expect($doctor->name)->toBe($updatedData['name']);
    expect((int) $doctor->age_range_min)->toBe(0);
    expect((int) $doctor->age_range_max)->toBe(99);
});

it('permite eliminar un doctor', function () {
    $doctor = Doctor::create([
        'name'  => 'Doctor para Eliminar '.uniqid(),
        'email' => 'delete.'.uniqid().'@test.com',
    ]);

    actingAs($this->adminUser, 'user')
        ->delete(route('admin.doctor.delete', $doctor->id))
        ->assertStatus(200)
        ->assertJson(['message' => 'Doctor eliminado correctamente']);

    $this->assertDatabaseMissing('doctors', ['id' => $doctor->id]);
});
