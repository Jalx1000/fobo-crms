<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/**
 * Contrato del payload de perfil del inbox de WhatsApp.
 *
 * El asesor cotiza por chat, así que necesita ver la cobertura del paciente sin
 * salir de la conversación: la pestaña "Seguro" y el resumen de "Info" dependen
 * de estas claves.
 */
beforeEach(function () {
    $this->admin = User::find(1);

    $this->seguroAttr = Attribute::where('code', 'seguro_paciente')->first() ?: Attribute::create([
        'code' => 'seguro_paciente', 'name' => 'Seguro', 'type' => 'multiselect', 'entity_type' => 'persons', 'admin_name' => 'Seguro',
    ]);

    $this->makeOption = fn (string $name) => DB::table('attribute_options')->insertGetId([
        'name'         => $name,
        'sort_order'   => 1,
        'attribute_id' => $this->seguroAttr->id,
    ]);
});

it('el perfil de WhatsApp incluye cada aseguradora con su estado', function () {
    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente WA '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id,
        'entity_type'  => 'persons',
        'text_value'   => "{$alianza},{$nacional}",
    ]);

    DB::table('person_insurance_statuses')->insert([
        [
            'person_id'  => $person->id, 'attribute_option_id' => $alianza, 'seguro_name' => 'Alianza',
            'status'     => 'VIGENTE', 'badge' => 'success', 'message' => 'Cobertura activa',
            'payload'    => json_encode(['VIGENCIA HASTA' => '2027-01-01']),
            'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'person_id'  => $person->id, 'attribute_option_id' => $nacional, 'seguro_name' => 'Nacional Vida',
            'status'     => 'NO_REGISTRADO', 'badge' => 'danger', 'message' => 'No figura en el padrón',
            // Mismas columnas que la fila anterior: el insert masivo lo exige.
            'payload'    => null,
            'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    DB::table('persons')->where('id', $person->id)->update(['insurance_status' => 'VIGENTE']);

    $insurances = actingAs($this->admin, 'user')
        ->getJson(route('admin.whatsapp.person', $person->id))
        ->assertStatus(200)
        ->json('person.insurances');

    expect($insurances)->toHaveCount(2)
        ->and(array_column($insurances, 'name'))->toBe(['Alianza', 'Nacional Vida'])
        ->and(array_column($insurances, 'status'))->toBe(['VIGENTE', 'NO_REGISTRADO'])
        // El payload crudo alimenta el detalle desplegado en la pestaña Seguro.
        ->and($insurances[0]['payload'])->toBe(['VIGENCIA HASTA' => '2027-01-01']);
});

it('el perfil expone el veredicto agregado para el resumen de Info', function () {
    $alianza = ($this->makeOption)('Alianza');

    $person = Person::create(['name' => 'Paciente WA Agregado '.uniqid()]);
    $person->attribute_values()->create([
        'attribute_id' => $this->seguroAttr->id, 'entity_type' => 'persons', 'text_value' => (string) $alianza,
    ]);

    DB::table('persons')->where('id', $person->id)->update(['insurance_status' => 'EN_MORA']);

    actingAs($this->admin, 'user')
        ->getJson(route('admin.whatsapp.person', $person->id))
        ->assertStatus(200)
        ->assertJsonPath('person.insurance_status', 'EN_MORA');
});

/**
 * El asesor edita la cobertura sin salir del inbox, así que el módulo expone su
 * propio endpoint de guardado+verificación (mismo comportamiento que la ficha).
 */
it('el perfil trae CI y catálogo de aseguradoras para el editor inline', function () {
    ($this->makeOption)('Alianza');

    $ciAttr = Attribute::where('code', 'ci_paciente')->first();

    $person = Person::create(['name' => 'Paciente WA Editor '.uniqid()]);

    if ($ciAttr) {
        $person->attribute_values()->create([
            'attribute_id' => $ciAttr->id, 'entity_type' => 'persons', 'text_value' => '5856583',
        ]);
    }

    $payload = actingAs($this->admin, 'user')
        ->getJson(route('admin.whatsapp.person', $person->id))
        ->assertStatus(200)
        ->json('person');

    expect($payload['insurance_options'])->not->toBeEmpty();

    if ($ciAttr) {
        expect($payload['insurance_ci'])->toBe('5856583');
    }
});

it('guarda y verifica la cobertura desde el inbox', function () {
    $alianza = ($this->makeOption)('Alianza');
    $nacional = ($this->makeOption)('Nacional Vida');

    $person = Person::create(['name' => 'Paciente WA Guardar '.uniqid()]);

    $response = actingAs($this->admin, 'user')
        ->postJson(route('admin.whatsapp.person.insurance', $person->id), [
            'ci_paciente'     => '5856583',
            'seguro_paciente' => [$alianza, $nacional],
        ])
        ->assertStatus(200)
        ->json();

    expect($response)->toHaveKey('results')
        ->and(array_column($response['results'], 'seguro_name'))->toBe(['Alianza', 'Nacional Vida']);

    // Quedó persistido el estado de cada aseguradora.
    expect(DB::table('person_insurance_statuses')->where('person_id', $person->id)->count())->toBe(2);
});

it('el endpoint del inbox rechaza una cobertura vacía', function () {
    $person = Person::create(['name' => 'Paciente WA Vacio '.uniqid()]);

    actingAs($this->admin, 'user')
        ->postJson(route('admin.whatsapp.person.insurance', $person->id), [
            'ci_paciente'     => '5856583',
            'seguro_paciente' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seguro_paciente');
});

it('un paciente sin seguros devuelve la lista vacía, no un error', function () {
    $person = Person::create(['name' => 'Paciente WA Sin Seguro '.uniqid()]);

    actingAs($this->admin, 'user')
        ->getJson(route('admin.whatsapp.person', $person->id))
        ->assertStatus(200)
        ->assertJsonPath('person.insurances', []);
});
