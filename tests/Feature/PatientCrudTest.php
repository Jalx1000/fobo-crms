<?php

namespace Tests\Feature;

use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Este test cubre el flujo completo de administración de Pacientes (Persons).
 * Valida la creación, edición y eliminación de pacientes.
 */
beforeEach(function () {
    $this->adminUser = User::find(1);
});

it('permite al administrador crear un nuevo paciente', function () {
    $personData = [
        'name'            => 'Paciente Test '.uniqid(),
        'emails'          => [
            ['label' => 'work', 'value' => 'test.'.uniqid().'@paciente.com'],
        ],
        'contact_numbers' => [
            ['label' => 'work', 'value' => '591'.rand(70000000, 79999999)],
        ],
        'job_title'       => 'Ingeniero de Pruebas',
        'unique_id'       => 'create-'.uniqid(),
    ];

    $response = actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.store'), $personData);

    $person = Person::where('name', $personData['name'])->first();
    expect($person)->not->toBeNull();

    $response->assertRedirect(route('admin.contacts.persons.view', $person->id))
        ->assertSessionHas('success');

    expect($person->job_title)->toBe($personData['job_title']);
});

it('permite editar los datos de un paciente existente', function () {
    $person = Person::create([
        'name'      => 'Paciente para Editar '.uniqid(),
        'emails'    => [['label' => 'work', 'value' => 'edit.'.uniqid().'@test.com']],
        'unique_id' => 'edit-'.uniqid(),
    ]);

    $updatedData = [
        'name'      => 'Nombre Editado '.$person->id,
        'job_title' => 'Gerente de Calidad',
        'unique_id' => 'updated-'.$person->id.'-'.uniqid(),
    ];

    actingAs($this->adminUser, 'user')
        ->put(route('admin.contacts.persons.update', $person->id), $updatedData)
        ->assertRedirect(route('admin.contacts.persons.index'));

    $person->refresh();
    expect($person->name)->toBe($updatedData['name']);
    expect($person->job_title)->toBe($updatedData['job_title']);
});

it('permite eliminar un paciente que no tiene leads asociados', function () {
    $person = Person::create([
        'name'      => 'Paciente para Eliminar '.uniqid(),
        'emails'    => [['label' => 'work', 'value' => 'delete.'.uniqid().'@test.com']],
        'unique_id' => 'delete-'.uniqid(),
    ]);

    actingAs($this->adminUser, 'user')
        ->delete(route('admin.contacts.persons.delete', $person->id))
        ->assertStatus(200)
        ->assertJson(['message' => trans('admin::app.contacts.persons.index.delete-success')]);

    $this->assertDatabaseMissing('persons', ['id' => $person->id]);
});

it('no permite eliminar un paciente que tiene leads vinculados', function () {
    // Buscamos una persona que sepamos que tiene leads (o usamos una existente)
    $personWithLeads = Person::has('leads')->first();

    if ($personWithLeads) {
        actingAs($this->adminUser, 'user')
            ->delete(route('admin.contacts.persons.delete', $personWithLeads->id))
            ->assertStatus(400)
            ->assertJson(['message' => trans('admin::app.contacts.persons.index.delete-failed')]);
    } else {
        $this->markTestSkipped('No hay pacientes con leads para probar esta restricción.');
    }
});
