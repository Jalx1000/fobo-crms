<?php

/*
|--------------------------------------------------------------------------
| Al borrar una entidad con atributos se borran sus attribute_values
|--------------------------------------------------------------------------
| Si quedan huérfanos, el próximo registro que reciba ese id los hereda (un
| paciente nuevo mostraba la cédula/teléfono de otro). Ver CustomAttribute.
*/

use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;

function attributeValuesOf(string $type, int $id): int
{
    return DB::table('attribute_values')->where('entity_type', $type)->where('entity_id', $id)->count();
}

it('borrar un contacto por el repositorio borra sus atributos', function () {
    $person = app(PersonRepository::class)->create([
        'entity_type'     => 'persons',
        'name'            => 'Contacto a borrar '.uniqid(),
        'emails'          => [],
        'contact_numbers' => [['label' => 'work', 'value' => '70000001']],
        'ci_paciente'     => '1234567',
    ]);

    expect(attributeValuesOf('persons', $person->id))->toBeGreaterThan(0);

    app(PersonRepository::class)->delete($person->id);

    expect(attributeValuesOf('persons', $person->id))->toBe(0);
});

it('borrar un contacto por el controlador (UI) borra sus atributos', function () {
    $person = app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Contacto UI '.uniqid(), 'emails' => [], 'contact_numbers' => [],
    ]);

    test()->actingAs(getDefaultAdmin(), 'user')
        ->deleteJson(route('admin.contacts.persons.delete', $person->id))
        ->assertOk();

    expect(attributeValuesOf('persons', $person->id))->toBe(0);
});

it('borrar un lead borra sus atributos', function () {
    $lead = app(LeadRepository::class)->create([
        'entity_type' => 'leads',
        'title'       => 'Lead a borrar '.uniqid(),
        'description' => 'x',
        'person'      => ['id' => app(PersonRepository::class)->create(['entity_type' => 'persons', 'name' => 'P '.uniqid(), 'emails' => [], 'contact_numbers' => []])->id],
    ]);

    expect(attributeValuesOf('leads', $lead->id))->toBeGreaterThan(0);

    app(LeadRepository::class)->delete($lead->id);

    expect(attributeValuesOf('leads', $lead->id))->toBe(0);
});
