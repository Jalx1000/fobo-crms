<?php

/**
 * Sesión vencida: la página va al login y el AJAX recibe un 401 marcado.
 *
 * Antes el AJAX también recibía la redirección; axios la seguía en silencio y el
 * Punto quedaba "colgado" sin avisar.
 */
it('a un pedido AJAX sin sesión le responde 401 con X-Sesion-Vencida', function () {
    test()->getJson(route('admin.dashboard.index'))
        ->assertStatus(401)
        ->assertHeader('X-Sesion-Vencida', '1');
});

it('a la página sin sesión la sigue mandando al login', function () {
    test()->get(route('admin.dashboard.index'))
        ->assertRedirect(route('admin.session.create'));
});
