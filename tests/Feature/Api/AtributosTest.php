<?php

use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * Leer atributos personalizados por API.
 *
 * Los recursos de krayin/rest-api devuelven una lista FIJA de campos: `nit` se
 * guarda bien pero la respuesta lo omite, y quien integra ve null y concluye que
 * falló. Este endpoint es la lectura que falta.
 */
beforeEach(function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->persona = app(PersonRepository::class)->create([
        'entity_type' => 'persons',
        'name'        => 'Atributos '.uniqid(),
        'nit'         => '1234567019',
    ]);
});

afterEach(function () {
    if (! isset($this->persona)) {
        return;
    }

    DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $this->persona->id)->delete();
    DB::table('persons')->where('id', $this->persona->id)->delete();
});

it('devuelve el NIT de una persona, que el recurso de Krayin omite', function () {
    $r = test()->getJson("/api/v1/atributos/persons/{$this->persona->id}")->assertOk();

    expect($r->json('atributos.nit'))->toBe('1234567019');
    expect($r->json('entity_type'))->toBe('persons');

    // El recurso de Krayin sigue sin devolverlo: este endpoint lo complementa,
    // no lo reemplaza.
    expect(test()->getJson("/api/v1/contacts/persons/{$this->persona->id}")->json('data.nit'))
        ->toBeNull();
});

it('devuelve el NIT de una cotización', function () {
    $q = app(QuoteRepository::class)->create([
        'entity_type' => 'quotes',
        'subject'     => 'Atributos '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(7)->toDateString(),
        'sub_total'   => 0,
        'grand_total' => 0,
        'items'       => [],
        'nit'         => '9998887066',
    ]);

    $r = test()->getJson("/api/v1/atributos/quotes/{$q->id}")->assertOk();

    expect($r->json('atributos.nit'))->toBe('9998887066');

    DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $q->id)->delete();
    DB::table('quote_items')->where('quote_id', $q->id)->delete();
    DB::table('quotes')->where('id', $q->id)->delete();
});

it('atributos es siempre un objeto, incluso sin nada cargado', function () {
    // Un array PHP vacío se serializa como [] y uno con claves como {}: quien
    // integra tendría que manejar dos tipos para el mismo campo.
    $r = test()->getJson("/api/v1/atributos/persons/{$this->persona->id}")->assertOk();

    expect($r->getContent())->toContain('"atributos":{');
});

it('rechaza una entidad que no está en la lista blanca', function () {
    // Sin lista blanca, cualquiera con un token leería los atributos de `users`
    // pasando el nombre de la tabla.
    test()->getJson('/api/v1/atributos/users/1')->assertNotFound();
});

it('404 cuando el id no existe', function () {
    test()->getJson('/api/v1/atributos/persons/99999999')->assertNotFound();
});
