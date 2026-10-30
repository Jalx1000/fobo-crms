<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Doctor\Models\Doctor;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
| El unique_id de un doctor es su _id en ShareMeData: con él se le asignan las
| citas que llegan de SMD. Editar el doctor desde la pantalla lo pisaba con
| "número|nombre" (el campo está deshabilitado y no se envía, y el repositorio
| inventaba uno): la ficha #12 quedó con unique_id "ADRIANA SORIA AGUILAR".
*/

function uidDoctor(): Doctor
{
    return Doctor::create([
        'name'      => 'Doctor UID '.uniqid(),
        'email'     => 'uid'.uniqid().'@test.com',
        'unique_id' => bin2hex(random_bytes(12)),
        'is_active' => true,
    ]);
}

it('editar un doctor desde la pantalla no cambia su unique_id', function () {
    $doctor = uidDoctor();
    $uid = $doctor->unique_id;

    // Lo que envía el formulario: el campo deshabilitado no viaja.
    actingAs(User::find(1), 'user')
        ->put(route('admin.doctor.update', $doctor->id), [
            'name'  => 'Nombre Editado '.uniqid(),
            'email' => $doctor->email,
            'phone' => '70000000',
        ])
        ->assertRedirect(route('admin.doctor.index'));

    expect($doctor->refresh()->unique_id)->toBe($uid);
});

it('la pantalla tampoco lo cambia aunque llegue un unique_id en el pedido', function (?string $sent) {
    $doctor = uidDoctor();
    $uid = $doctor->unique_id;

    actingAs(User::find(1), 'user')
        ->put(route('admin.doctor.update', $doctor->id), [
            'name'      => $doctor->name,
            'email'     => $doctor->email,
            'unique_id' => $sent,
        ])
        ->assertRedirect(route('admin.doctor.index'));

    expect($doctor->refresh()->unique_id)->toBe($uid);
})->with([
    'otro id'     => ['ffffffffffffffffffffffff'],
    'vacío'       => [''],
    'null'        => [null],
]);

it('actualizar con el repositorio sin unique_id no inventa uno a partir del nombre', function () {
    $doctor = uidDoctor();
    $uid = $doctor->unique_id;

    app(DoctorRepository::class)->update(['name' => 'Otro Nombre '.uniqid(), 'number' => '77'], $doctor->id);

    expect($doctor->refresh()->unique_id)->toBe($uid);
});

it('un doctor sin unique_id sigue sin él al editarlo (no queda "número|nombre")', function () {
    $doctor = Doctor::create(['name' => 'Sin SMD '.uniqid(), 'email' => 'nosmd'.uniqid().'@test.com', 'unique_id' => null]);

    actingAs(User::find(1), 'user')
        ->put(route('admin.doctor.update', $doctor->id), ['name' => $doctor->name, 'email' => $doctor->email])
        ->assertRedirect(route('admin.doctor.index'));

    expect($doctor->refresh()->unique_id)->toBeNull();
});

it('la vinculación con SMD (unique_id explícito) sigue pudiendo guardarlo', function () {
    $doctor = Doctor::create(['name' => 'Por vincular '.uniqid(), 'email' => 'link'.uniqid().'@test.com', 'unique_id' => null]);
    $smdId = bin2hex(random_bytes(12));

    // Es lo que hacen IncomingAppointmentService / AppointmentService al descubrir el _id.
    app(DoctorRepository::class)->update(['unique_id' => $smdId], $doctor->id);

    expect($doctor->refresh()->unique_id)->toBe($smdId);
});
