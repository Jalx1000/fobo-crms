<?php

/*
|--------------------------------------------------------------------------
| Borrar todo el horario de un doctor
|--------------------------------------------------------------------------
| Un solo botón en la agenda de turnos borra todos los turnos de un doctor
| (pasados y futuros), sin tocar los de los demás ni sus citas.
*/

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Doctor\Models\Doctor;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminUser = User::find(1);
    $this->doctor = Doctor::create(['name' => 'Doctor Borrador '.uniqid(), 'is_active' => true]);
    $this->other = Doctor::create(['name' => 'Doctor Intacto '.uniqid(), 'is_active' => true]);

    $shift = fn (int $doctorId, string $date) => DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $date,
        'start_time' => '09:00',
        'end_time'   => '12:30',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $shift($this->doctor->id, Carbon::today()->subMonth()->toDateString());
    $shift($this->doctor->id, Carbon::today()->toDateString());
    $shift($this->doctor->id, Carbon::today()->addWeek()->toDateString());
    $shift($this->other->id, Carbon::today()->addWeek()->toDateString());
});

it('borra todos los turnos del doctor, pasados y futuros, y dice cuántos', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_all', $this->doctor->id))
        ->assertOk()
        ->assertJsonPath('deleted', 3);

    expect(DB::table('doctor_shifts')->where('doctor_id', $this->doctor->id)->count())->toBe(0);
});

it('no toca los turnos de los demás doctores', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_all', $this->doctor->id))
        ->assertOk();

    expect(DB::table('doctor_shifts')->where('doctor_id', $this->other->id)->count())->toBe(1);
});

it('un doctor sin turnos responde 0 borrados, no error', function () {
    $empty = Doctor::create(['name' => 'Doctor Vacío '.uniqid(), 'is_active' => true]);

    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_all', $empty->id))
        ->assertOk()
        ->assertJsonPath('deleted', 0);
});

it('un doctor que no existe da 404', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_all', 99999999))
        ->assertNotFound();
});

it('exige estar logueado', function () {
    // El middleware del admin manda al login (302), como en todo el panel.
    $this->deleteJson(route('admin.schedules.delete_all', $this->doctor->id))
        ->assertRedirect();

    expect(DB::table('doctor_shifts')->where('doctor_id', $this->doctor->id)->count())->toBe(3);
});

it('la agenda de turnos muestra el botón de borrar todo el horario', function () {
    actingAs($this->adminUser)
        ->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertSee('deleteAllShifts(doctor)', false)
        ->assertSee(route('admin.schedules.delete_all', 'replaceId'), false);
});

it('la semana trae el último día con turno de cada doctor, aunque caiga fuera de la semana', function () {
    $lejos = Carbon::today()->addMonths(2)->toDateString();
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $this->doctor->id, 'date' => $lejos, 'start_time' => '09:00', 'end_time' => '12:30',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $sinTurnos = Doctor::create(['name' => 'Doctor Sin Turnos '.uniqid(), 'is_active' => true]);

    $doctors = collect(actingAs($this->adminUser)
        ->getJson(route('admin.schedules.week', ['start' => Carbon::today()->startOfWeek()->toDateString()]))
        ->assertOk()
        ->json('doctors'))->keyBy('id');

    expect($doctors[$this->doctor->id]['last_shift_date'])->toBe($lejos)
        ->and($doctors[$sinTurnos->id]['last_shift_date'])->toBeNull();
});

it('el modal de añadir turno dice para qué doctor es', function () {
    $html = actingAs($this->adminUser)->get(route('admin.schedules.index'))->assertOk()->getContent();

    expect(substr_count($html, '{{ modalContext.doctorName }}'))->toBeGreaterThanOrEqual(3)
        ->and($html)->toContain('lastDayInfo(doctor).text');
});
