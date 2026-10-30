<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Models\Person;

/*
| Alta de paciente: «The value has already been taken» con un teléfono o email
| que nadie usaba. La unicidad miraba attribute_values, y al borrar una persona
| sus valores quedan (en producción había 3.277 huérfanos). Ahora solo cuentan
| los de personas que existen, y el mensaje dice qué paciente lo tiene.
*/

uses(DatabaseTransactions::class);

function orphanPhoneAttributeId(): int
{
    return (int) DB::table('attributes')->where('entity_type', 'persons')->where('code', 'contact_numbers')->value('id');
}

function storePersonWithPhone(string $phone)
{
    test()->actingAs(getDefaultAdmin());

    return test()->postJson(route('admin.contacts.persons.store'), [
        'entity_type'     => 'persons',
        'name'            => 'Paciente Unicidad '.uniqid(),
        'contact_numbers' => [['value' => $phone, 'label' => 'work']],
    ], ['X-Requested-With' => 'XMLHttpRequest']);
}

it('un teléfono de un paciente borrado se puede volver a usar', function () {
    $phone = '7'.random_int(1000000, 9999999);
    $deletedId = (int) DB::table('persons')->max('id') + 1000;

    // Lo que deja un paciente borrado: su teléfono sigue en attribute_values.
    DB::table('attribute_values')->insert([
        'entity_type' => 'persons',
        'entity_id'   => $deletedId,
        'attribute_id'=> orphanPhoneAttributeId(),
        'json_value'  => json_encode([['value' => $phone, 'label' => 'work']]),
        'unique_id'   => $deletedId.'|'.orphanPhoneAttributeId(),
    ]);

    storePersonWithPhone($phone)->assertOk();
});

it('un teléfono de un paciente que existe sigue bloqueado y dice de quién es', function () {
    $phone = '7'.random_int(1000000, 9999999);
    $owner = Person::create(['name' => 'Dueño Del Teléfono '.uniqid(), 'entity_type' => 'persons']);

    DB::table('attribute_values')->insert([
        'entity_type' => 'persons',
        'entity_id'   => $owner->id,
        'attribute_id'=> orphanPhoneAttributeId(),
        'json_value'  => json_encode([['value' => $phone, 'label' => 'work']]),
        'unique_id'   => $owner->id.'|'.orphanPhoneAttributeId(),
    ]);

    storePersonWithPhone($phone)
        ->assertStatus(422)
        ->assertJsonPath('errors', fn ($errors) => str_contains(json_encode($errors, JSON_UNESCAPED_UNICODE), "ya lo tiene el paciente {$owner->name} (#{$owner->id})"));
});

it('borrar un paciente borra también sus valores de atributos', function () {
    $person = Person::create(['name' => 'Paciente A Borrar '.uniqid(), 'entity_type' => 'persons']);

    DB::table('attribute_values')->insert([
        'entity_type'  => 'persons',
        'entity_id'    => $person->id,
        'attribute_id' => orphanPhoneAttributeId(),
        'json_value'   => json_encode([['value' => '7'.random_int(1000000, 9999999), 'label' => 'work']]),
        'unique_id'    => $person->id.'|'.orphanPhoneAttributeId(),
    ]);

    app(\Webkul\Contact\Repositories\PersonRepository::class)->delete($person->id);

    expect(DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $person->id)->count())->toBe(0);
});

it('los importadores que borran en bloque limpian los valores de atributos', function () {
    foreach (['Persons' => 'persons', 'Leads' => 'leads', 'Products' => 'products'] as $dir => $entityType) {
        $source = file_get_contents(base_path("packages/Webkul/DataTransfer/src/Helpers/Importers/{$dir}/Importer.php"));

        expect($source)->toMatch("/->deleteWhere\\(\\[\\['id', 'IN', \\\$idsToDelete\\]\\]\\);.*?\\\$this->attributeValueRepository->deleteWhere\\(\\[\\s*\\['entity_type', '=', '{$entityType}'\\],\\s*\\['entity_id', 'IN', \\\$idsToDelete\\],/s");
    }
});
