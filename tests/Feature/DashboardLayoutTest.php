<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * La gráfica "Ventas por vendedor" (v-dashboard-ventas) fue retirada del
 * tablero. Todos los roles ven el mismo layout: leads-by-users y
 * tiempo-por-vendedor.
 */
it('un administrador ve leads-by-users y tiempo-por-vendedor, sin ventas', function () {
    $admin = User::find(1); // rol Administrator (id 1)

    $this->actingAs($admin, 'user')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertSee('v-dashboard-leads-by-users', false)
        ->assertSee('v-dashboard-tiempo-por-vendedor', false)
        ->assertDontSee('v-dashboard-ventas>', false);
});

/**
 * La gráfica "Pacientes" (v-dashboard-total-leads) fue reemplazada por
 * "Comportamiento de etapas" (total-leads-by-stages-over-time), que muestra
 * los leads de cada etapa del pipeline a lo largo del tiempo.
 */
it('el tablero muestra el comportamiento de etapas y no la gráfica de pacientes', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertSee('v-dashboard-total-leads-by-stages-over-time', false)
        ->assertDontSee('<v-dashboard-total-leads>', false);
});

/**
 * La torta "Servicios más vendidos" (quantity-products-pie) fue retirada del
 * tablero; solo queda la torta "Servicios Solicitados", que suma los servicios
 * pedidos en TODAS las etapas.
 */
it('el tablero muestra la torta de servicios solicitados y no la de más vendidos', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertSee('v-dashboard-services-requested', false)
        ->assertDontSee('v-dashboard-quantity-products-pie', false);
});

/**
 * UX del filtro de fechas: el botón de rango rápido activo (7/30/90 días,
 * Este mes) se resalta vía quickRangeStyle/activeQuick, y con un rango
 * personalizado el resaltado pasa a los date pickers (customRangeStyle).
 */
it('los filtros de rango exponen el estado activo para resaltar el seleccionado', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertSee('quickRangeStyle(7)', false)
        ->assertSee('quickRangeStyle(30)', false)
        ->assertSee('quickRangeStyle(90)', false)
        ->assertSee("quickRangeStyle('month')", false)
        ->assertSee('activeQuick', false)
        ->assertSee('customRangeStyle()', false)
        // El helper compartido de sincronización está presente.
        ->assertSee('OdontoDateRange', false);
});

it('un recepcionista ve tiempo-por-vendedor y no ve ventas', function () {
    $staff = User::create([
        'name'     => 'Recepcion '.uniqid(),
        'email'    => 'staff'.uniqid().'@test.com',
        'password' => bcrypt('secret'),
        'role_id'  => 2, // Recepcionista
        'status'   => 1,
    ]);

    $this->actingAs($staff, 'user')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertSee('v-dashboard-tiempo-por-vendedor', false)
        ->assertDontSee('<v-dashboard-ventas', false);
});
