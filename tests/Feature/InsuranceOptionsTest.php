<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;
use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminUser = User::find(1);

    if (! $this->adminUser) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->seguroAttr = Attribute::where('code', 'seguro_paciente')->first()
        ?: Attribute::create([
            'code'        => 'seguro_paciente',
            'name'        => 'Seguro',
            'type'        => 'select',
            'entity_type' => 'persons',
            'admin_name'  => 'Seguro',
        ]);

    $this->ciAttr = Attribute::where('code', 'ci_paciente')->first()
        ?: Attribute::create([
            'code'        => 'ci_paciente',
            'name'        => 'CI',
            'type'        => 'text',
            'entity_type' => 'persons',
            'admin_name'  => 'CI',
        ]);
});

it('GET insurance-options devuelve array de opciones', function () {
    actingAs($this->adminUser, 'user')
        ->getJson(route('admin.contacts.persons.insurance_options'))
        ->assertOk()
        ->assertJsonStructure(['options'])
        ->assertJsonPath('options', fn ($v) => is_array($v));
});

it('GET insurance-options incluye opciones del atributo seguro_paciente', function () {
    $option = AttributeOption::firstOrCreate(
        ['attribute_id' => $this->seguroAttr->id, 'name' => 'Alianza Test'],
        ['sort_order' => 99]
    );

    $response = actingAs($this->adminUser, 'user')
        ->getJson(route('admin.contacts.persons.insurance_options'))
        ->assertOk();

    $options = collect($response->json('options'));
    expect($options->pluck('id')->toArray())->toContain($option->id);
    expect($options->pluck('name')->toArray())->toContain('Alianza Test');
});

it('search de personas incluye ci_paciente en la respuesta', function () {
    $person = Person::create([
        'name' => 'Paciente CI Test ' . uniqid(),
    ]);

    actingAs($this->adminUser, 'user')
        ->getJson(route('admin.contacts.persons.search', ['query' => 'Paciente CI Test']))
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'ci_paciente']]]);
});

it('POST store de persona via AJAX devuelve JSON con data y message', function () {
    $response = actingAs($this->adminUser, 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('admin.contacts.persons.store'), [
            'entity_type' => 'persons',
            'name'        => 'Paciente Ajax ' . uniqid(),
        ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name'], 'message']);

    expect($response->json('data.id'))->toBeInt();
});

it('POST store de persona via AJAX crea la persona en base de datos', function () {
    $name = 'Paciente Ajax DB ' . uniqid();

    actingAs($this->adminUser, 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('admin.contacts.persons.store'), [
            'entity_type' => 'persons',
            'name'        => $name,
        ])
        ->assertOk();

    expect(Person::where('name', $name)->exists())->toBeTrue();
});

it('POST verify-insurance-quick valida campos requeridos', function () {
    actingAs($this->adminUser, 'user')
        ->postJson(route('admin.contacts.persons.verify_insurance_quick'), [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors']);
});
