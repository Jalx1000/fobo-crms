<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\User\Models\User;

/**
 * Ficha del contacto (vista): editar email y teléfono con el lápiz en línea.
 *
 * El componente inline manda un PUT a admin.contacts.persons.update con UN
 * solo campo (`{"emails": [...]}` o `{"contact_numbers": [...]}`), por AJAX.
 *
 * Contra la base del contenedor con transacciones; nada sale.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    Http::fake();

    $this->actingAs(User::findOrFail(1), 'user');

    $this->person = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type'     => 'persons',
        'name'            => 'Inline '.uniqid(),
        'emails'          => [['value' => 'viejo'.uniqid().'@test.local', 'label' => 'work']],
        'contact_numbers' => [['value' => '5917'.random_int(1000000, 9999999), 'label' => 'work']],
    ]));
});

function editarEnLinea($test, array $campo)
{
    return $test->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->put(route('admin.contacts.persons.update', $test->person->id), $campo);
}

it('cambia el teléfono desde la ficha', function () {
    $nuevo = '5916'.random_int(1000000, 9999999);

    editarEnLinea($this, ['contact_numbers' => [['value' => $nuevo, 'label' => 'work']]])->assertOk();

    expect(Person::find($this->person->id)->contact_numbers[0]['value'])->toBe($nuevo);
});

it('cambia el email desde la ficha', function () {
    $nuevo = 'nuevo'.uniqid().'@test.local';

    editarEnLinea($this, ['emails' => [['value' => $nuevo, 'label' => 'work']]])->assertOk();

    expect(Person::find($this->person->id)->emails[0]['value'])->toBe($nuevo);
});

it('editar solo el teléfono no borra el email (ni al revés)', function () {
    $email = $this->person->emails[0]['value'];

    editarEnLinea($this, ['contact_numbers' => [['value' => '59160000001', 'label' => 'work']]])->assertOk();

    expect(Person::find($this->person->id)->emails[0]['value'])->toBe($email);
});
