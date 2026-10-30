<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->admin = User::find(1);
});

/**
 * Helper: crea una persona con un created_at específico.
 */
function crearPersona(string $suffix, Carbon $createdAt): int
{
    $person = Person::create([
        'name'        => 'Paciente '.$suffix.' '.uniqid(),
        'entity_type' => 'persons',
    ]);

    $person->forceFill(['created_at' => $createdAt])->save();

    return $person->id;
}

/**
 * El listado de personas filtra por rango de fechas de registro (created_at)
 * usando start_date / end_date.
 */
it('el datagrid de personas filtra por rango de created_at', function () {
    $base = Carbon::now()->startOfMonth()->addDays(10);

    $dentro = crearPersona('Dentro', $base->copy());
    $fuera = crearPersona('Fuera', $base->copy()->subDays(60));

    $response = $this->actingAs($this->admin, 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('admin.contacts.persons.index', [
            'start_date' => $base->copy()->subDays(3)->format('Y-m-d'),
            'end_date'   => $base->copy()->addDays(3)->format('Y-m-d'),
        ]))
        ->assertStatus(200);

    $ids = collect($response->json('records'))->pluck('id');

    expect($ids)->toContain($dentro)
        ->not->toContain($fuera);
});

/**
 * Sin filtro de fechas, ambas personas aparecen (no rompe el listado normal).
 */
it('sin filtro de fechas el datagrid devuelve todos', function () {
    $a = crearPersona('A', Carbon::now());
    $b = crearPersona('B', Carbon::now()->subMonths(6));

    $response = $this->actingAs($this->admin, 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('admin.contacts.persons.index'))
        ->assertStatus(200);

    $ids = collect($response->json('records'))->pluck('id');

    expect($ids)->toContain($a)->toContain($b);
});

/**
 * La página index de personas muestra la barra de filtro de fechas.
 */
it('el index de personas renderiza la barra de filtro de fechas', function () {
    $this->actingAs($this->admin, 'user')
        ->get(route('admin.contacts.persons.index'))
        ->assertStatus(200)
        ->assertSee('Registrados:', false)
        ->assertSee('Este mes', false)
        // Usa el helper/cookie compartida para sincronizar con Tablero y Leads.
        ->assertSee('OdontoDateRange', false)
        ->assertSee('setCurrentMonth', false);
});
