<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Persistencia del filtro de fechas del tablero: el JS guarda el rango elegido
 * en la cookie "global_date_range" ("Y-m-d|Y-m-d") y el backend la usa de
 * fallback cuando el request no trae start/end. Así la fecha filtrada se
 * mantiene entre visitas hasta que el usuario la cambie.
 *
 * La cookie viaja sin encriptar (la escribe document.cookie), por eso va
 * excluida en el middleware EncryptCookies y en los tests se envía con
 * withUnencryptedCookie(). Ojo: getJson() solo adjunta cookies si se llama
 * withCredentials().
 */
it('usa el rango persistido en la cookie cuando el request no trae fechas', function () {
    $stats = $this->actingAs(User::find(1), 'user')
        ->withCredentials()
        ->withUnencryptedCookie('global_date_range', '2026-06-01|2026-06-15')
        ->getJson(route('admin.dashboard.stats', ['type' => 'total-leads-by-stages-over-time']))
        ->assertStatus(200)
        ->json('statistics');

    expect($stats['current_range'])->toBe('01 Jun 2026 - 15 Jun 2026');
});

it('los start/end explícitos del request tienen prioridad sobre la cookie', function () {
    $stats = $this->actingAs(User::find(1), 'user')
        ->withCredentials()
        ->withUnencryptedCookie('global_date_range', '2026-06-01|2026-06-15')
        ->getJson(route('admin.dashboard.stats', [
            'type'  => 'total-leads-by-stages-over-time',
            'start' => '2026-05-01',
            'end'   => '2026-05-10',
        ]))
        ->assertStatus(200)
        ->json('statistics');

    expect($stats['current_range'])->toBe('01 May 2026 - 10 May 2026');
});

it('una cookie corrupta se ignora y aplican los defaults sin romper el tablero', function () {
    $expectedDefault = now()->subDays(1)->format('d M').' - '.now()->format('d M');

    $response = $this->actingAs(User::find(1), 'user')
        ->withCredentials()
        ->withUnencryptedCookie('global_date_range', 'no-es-fecha|tampoco')
        ->getJson(route('admin.dashboard.stats', ['type' => 'total-leads-by-stages-over-time']))
        ->assertStatus(200);

    expect($response->json('date_range'))->toBe($expectedDefault);
});

it('el tablero precarga los date pickers con el rango persistido', function () {
    $this->actingAs(User::find(1), 'user')
        ->withUnencryptedCookie('global_date_range', '2026-06-01|2026-06-15')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertSee('2026-06-01')
        ->assertSee('2026-06-15');
});
