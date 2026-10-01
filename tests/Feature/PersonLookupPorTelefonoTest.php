<?php

use Webkul\Whatsapp\Support\Currency;

/*
 * La búsqueda de contacto por teléfono y el símbolo de moneda del módulo de
 * WhatsApp. Los casos son deliberadamente de sólo lectura: la suite corre
 * contra la base de producción, así que ninguno crea ni borra contactos.
 */

beforeEach(fn () => actingAsSanctumAuthenticatedAdmin());

it('exige un teléfono de al menos 7 dígitos', function () {
    $response = $this->getJson('/api/personas/por-telefono?telefono=123');

    $response->assertStatus(400)
        ->assertJsonPath('message', 'El parámetro telefono es requerido y debe tener al menos 7 dígitos.');
});

it('devuelve persona nula, no 404, para un número desconocido', function () {
    $response = $this->getJson('/api/personas/por-telefono?telefono=59100000000');

    $response->assertOk()
        ->assertJsonPath('persona', null)
        ->assertJsonPath('telefono', '+59100000000');
});

it('usa Bs como símbolo cuando la moneda del CRM es BOB', function () {
    config(['app.currency' => 'BOB']);

    expect(Currency::symbol())->toBe('Bs');
});

it('cae en el formateador del sistema para monedas sin override', function () {
    config(['app.currency' => 'USD']);

    // ICU en locale es devuelve "US$" para USD, que es justamente lo que se
    // quiere: el símbolo lo decide el formateador, no una tabla a mano.
    expect(Currency::symbol())->toBe('US$');
});
