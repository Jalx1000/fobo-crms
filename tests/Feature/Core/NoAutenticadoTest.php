<?php

/**
 * «No autenticado» responde 401 (JSON) o lleva al login, con APP_DEBUG
 * encendido o apagado. Con debug apagado (producción desde el 16/10) el
 * manejador de Krayin lo convertía en un 500 genérico, y sin JSON intentaba
 * redirigir a `customer.session.index`, una ruta de Bagisto que no existe.
 */
dataset('debug', ['debug encendido' => true, 'debug apagado' => false]);

it('la API sin token responde 401', function (bool $debug) {
    config(['app.debug' => $debug]);

    test()->postJson('/api/v1/whatsapp/conversations/1/handoff', [])
        ->assertStatus(401)
        ->assertJsonStructure(['message']);
})->with('debug');

it('sin JSON, lleva al login del CRM', function (bool $debug) {
    config(['app.debug' => $debug]);

    test()->post('/api/v1/whatsapp/conversations/1/handoff', [])
        ->assertRedirect(route('admin.session.create'));
})->with('debug');

it('una página de error del CRM sale con su código, no con 200', function (bool $debug) {
    config(['app.debug' => $debug]);
    test()->actingAs(getDefaultAdmin(), 'user');

    test()->get(route('admin.contacts.persons.view', 999999999))->assertNotFound();
})->with('debug');
