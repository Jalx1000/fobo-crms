<?php

/*
|--------------------------------------------------------------------------
| Eliminar turnos recurrentes en un rango de fechas
|--------------------------------------------------------------------------
| Lo inverso de "Turnos recurrentes": entre dos fechas, borra los turnos del
| doctor que caen en los días de la semana elegidos (0 = domingo, como
| Carbon). Sin días = todos los días del rango.
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
    $this->doctor = Doctor::create(['name' => 'Doctor Rango '.uniqid(), 'is_active' => true]);
    $this->other = Doctor::create(['name' => 'Doctor Ajeno '.uniqid(), 'is_active' => true]);

    // Dos semanas completas (lun→dom), mañana y tarde, desde el lunes de la próxima semana.
    $this->monday = Carbon::today()->startOfWeek()->addWeek();

    for ($i = 0; $i < 14; $i++) {
        foreach ([['09:00', '12:30'], ['14:30', '17:30']] as [$from, $to]) {
            DB::table('doctor_shifts')->insert([
                'doctor_id'  => $this->doctor->id,
                'date'       => $this->monday->copy()->addDays($i)->toDateString(),
                'start_time' => $from,
                'end_time'   => $to,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $this->other->id,
        'date'       => $this->monday->toDateString(),
        'start_time' => '09:00',
        'end_time'   => '12:30',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->count = fn (int $doctorId) => DB::table('doctor_shifts')->where('doctor_id', $doctorId)->count();
});

it('sin días elegidos borra todos los turnos del rango (semana cerrada)', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_range', $this->doctor->id), [
            'start_date' => $this->monday->toDateString(),
            'end_date'   => $this->monday->copy()->addDays(6)->toDateString(),
        ])
        ->assertOk()
        ->assertJsonPath('deleted', 14);

    // Queda intacta la segunda semana.
    expect(($this->count)($this->doctor->id))->toBe(14);
});

it('con días elegidos borra solo esos días de la semana dentro del rango', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_range', $this->doctor->id), [
            'start_date' => $this->monday->toDateString(),
            'end_date'   => $this->monday->copy()->addDays(13)->toDateString(),
            'days'       => [6, 0], // sábados y domingos
        ])
        ->assertOk()
        ->assertJsonPath('deleted', 8); // 2 semanas × 2 días × 2 turnos

    $weekendLeft = DB::table('doctor_shifts')->where('doctor_id', $this->doctor->id)->pluck('date')
        ->filter(fn ($d) => in_array(Carbon::parse($d)->dayOfWeek, [0, 6]));

    expect($weekendLeft)->toBeEmpty()
        ->and(($this->count)($this->doctor->id))->toBe(20);
});

it('los extremos del rango se incluyen', function () {
    $day = $this->monday->copy()->addDays(2)->toDateString();

    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_range', $this->doctor->id), [
            'start_date' => $day,
            'end_date'   => $day,
        ])
        ->assertOk()
        ->assertJsonPath('deleted', 2);
});

it('no toca los turnos de otros doctores', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_range', $this->doctor->id), [
            'start_date' => $this->monday->toDateString(),
            'end_date'   => $this->monday->copy()->addDays(13)->toDateString(),
        ])
        ->assertOk();

    expect(($this->count)($this->other->id))->toBe(1);
});

it('rechaza un rango con la fecha de fin antes que la de inicio', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_range', $this->doctor->id), [
            'start_date' => $this->monday->copy()->addDays(5)->toDateString(),
            'end_date'   => $this->monday->toDateString(),
        ])
        ->assertUnprocessable();

    expect(($this->count)($this->doctor->id))->toBe(28);
});

it('un doctor que no existe da 404', function () {
    actingAs($this->adminUser)
        ->deleteJson(route('admin.schedules.delete_range', 99999999), [
            'start_date' => $this->monday->toDateString(),
            'end_date'   => $this->monday->toDateString(),
        ])
        ->assertNotFound();
});

it('el menú de gestionar horario ofrece eliminar turnos en un rango', function () {
    actingAs($this->adminUser)
        ->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertSee('openDeleteRangeModal', false)
        ->assertSee(route('admin.schedules.delete_range', 'replaceId'), false);
});
