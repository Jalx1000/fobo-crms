<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * El card "Total contactos" del tablero (over-all) cuenta las personas
 * (pacientes/contactos) creadas dentro del rango de fechas del filtro. Se
 * ubica a la izquierda de "Total de Consultas".
 */
it('over-all expone total_contacts con la forma de progreso', function () {
    $stats = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'over-all']))
        ->assertStatus(200)
        ->json('statistics');

    expect($stats)->toHaveKey('total_contacts');
    expect($stats['total_contacts'])->toHaveKeys(['previous', 'current', 'progress']);
});

it('total_contacts cuenta las personas creadas en el rango', function () {
    $params = [
        'type'  => 'over-all',
        'start' => now()->subDays(29)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ];

    $baseline = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', $params))
        ->assertStatus(200)
        ->json('statistics.total_contacts.current');

    Person::create([
        'name'        => 'Paciente Contacto '.uniqid(),
        'entity_type' => 'persons',
    ]);

    $current = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', $params))
        ->assertStatus(200)
        ->json('statistics.total_contacts.current');

    expect($current)->toBe($baseline + 1);
});
