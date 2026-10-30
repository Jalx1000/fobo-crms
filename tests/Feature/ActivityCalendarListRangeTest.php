<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Doctor\Models\Doctor;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminUser = User::find(1);
    $this->repo = app(ActivityRepository::class);
    $this->doctor = Doctor::create(['name' => 'Doctor Lista '.uniqid(), 'is_active' => true]);
});

/**
 * Helper: crea una cita (meeting) asignada al doctor de prueba en la fecha dada.
 */
function crearCita($repo, $doctorId, $adminId, Carbon $from): int
{
    $activity = $repo->create([
        'type'          => 'meeting',
        'title'         => 'Cita '.$from->format('Y-m-d H:i'),
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $from->copy()->addHour()->format('Y-m-d H:i:s'),
        'user_id'       => $adminId,
        'participants'  => ['doctors' => [$doctorId]],
    ]);

    return $activity->id;
}

/**
 * La vista Lista del calendario (calendar_view=list) devuelve solo las citas
 * dentro del rango [range_start, range_end], ordenadas cronológicamente.
 */
it('la vista lista devuelve las citas dentro del rango inicio/fin', function () {
    $base = Carbon::now()->startOfMonth()->addDays(10)->setTime(9, 0);

    $dentro1 = crearCita($this->repo, $this->doctor->id, $this->adminUser->id, $base->copy());
    $dentro2 = crearCita($this->repo, $this->doctor->id, $this->adminUser->id, $base->copy()->addDays(3));
    $fuera = crearCita($this->repo, $this->doctor->id, $this->adminUser->id, $base->copy()->addDays(40));

    $response = $this->actingAs($this->adminUser, 'user')
        ->getJson(route('admin.activities.get').'?'.http_build_query([
            'view_type'     => 'calendar',
            'calendar_mode' => 'doctor',
            'calendar_view' => 'list',
            'range_start'   => $base->copy()->subDay()->format('Y-m-d'),
            'range_end'     => $base->copy()->addDays(7)->format('Y-m-d'),
        ]))
        ->assertStatus(200);

    $ids = collect($response->json('appointments'))->pluck('id');

    expect($ids)->toContain($dentro1)
        ->toContain($dentro2)
        ->not->toContain($fuera);

    // La vista lista no pagina por días de grilla.
    expect($response->json('days'))->toBe([]);
    expect($response->json('calendar_view'))->toBe('list');
});

/**
 * El rango invertido (fin < inicio) se normaliza y aún así trae las citas.
 */
it('normaliza el rango cuando fin es anterior a inicio', function () {
    $base = Carbon::now()->startOfMonth()->addDays(10)->setTime(9, 0);
    $cita = crearCita($this->repo, $this->doctor->id, $this->adminUser->id, $base->copy());

    $response = $this->actingAs($this->adminUser, 'user')
        ->getJson(route('admin.activities.get').'?'.http_build_query([
            'view_type'     => 'calendar',
            'calendar_mode' => 'doctor',
            'calendar_view' => 'list',
            // Invertidas a propósito.
            'range_start'   => $base->copy()->addDays(5)->format('Y-m-d'),
            'range_end'     => $base->copy()->subDays(5)->format('Y-m-d'),
        ]))
        ->assertStatus(200);

    expect(collect($response->json('appointments'))->pluck('id'))->toContain($cita);
});

/**
 * La página del listado de citas expone el botón "Lista" (nueva vista por rango)
 * y el botón "Editar Cita" en el detalle de la cita.
 */
it('el listado de citas muestra el botón Lista y el de editar cita', function () {
    $this->actingAs($this->adminUser, 'user')
        ->get(route('admin.activities.index'))
        ->assertStatus(200)
        ->assertSee('>Lista<', false)
        ->assertSee("setView('list')", false)
        ->assertSee('Editar Cita', false)
        ->assertSee('editFromView', false);
});
