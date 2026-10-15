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
 * Contrato del endpoint insurance-status, que el modal de detalle de cita
 * (doctor-day-calendar) consume para mostrar el seguro actual del paciente.
 * La UI depende de estas claves: ci, seguro_id, seguro_label, status.
 */
beforeEach(function () {
    $this->admin = User::find(1);

    $this->ciAttr = Attribute::where('code', 'ci_paciente')->first() ?: Attribute::create([
        'code' => 'ci_paciente', 'name' => 'CI', 'type' => 'text', 'entity_type' => 'persons', 'admin_name' => 'CI',
    ]);

    $this->seguroAttr = Attribute::where('code', 'seguro_paciente')->first() ?: Attribute::create([
        'code' => 'seguro_paciente', 'name' => 'Seguro', 'type' => 'select', 'entity_type' => 'persons', 'admin_name' => 'Seguro',
    ]);
});

it('insurance-status devuelve el seguro persistido del paciente (ci, seguro_id, seguro_label, status)', function () {
    $optionId = DB::table('attribute_options')->insertGetId([
        'name'         => 'Nacional Vida',
        'sort_order'   => 1,
        'attribute_id' => $this->seguroAttr->id,
    ]);

    // La columna donde se guarda el valor depende del tipo del atributo
    // (mapa de Webkul\Attribute\Traits\CustomAttribute). En esta instalación
    // seguro_paciente es multiselect (text_value), pero lo resolvemos dinámico.
    $seguroColumn = in_array($this->seguroAttr->type, ['select', 'lookup', 'price'], true)
        ? 'integer_value'
        : 'text_value';
    $seguroValue = $seguroColumn === 'integer_value' ? $optionId : (string) $optionId;

    $person = Person::create(['name' => 'Paciente Seguro '.uniqid()]);
    $person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, $seguroColumn => $seguroValue, 'entity_type' => 'persons']);

    DB::table('persons')->where('id', $person->id)->update(['insurance_status' => 'VIGENTE']);

    actingAs($this->admin, 'user')
        ->getJson(route('admin.contacts.persons.insurance_status', $person->id))
        ->assertStatus(200)
        ->assertJson([
            'ci'           => '5856583',
            'seguro_id'    => $seguroValue,
            'seguro_label' => 'Nacional Vida',
            'status'       => 'VIGENTE',
        ]);
});

it('insurance-status devuelve nulos cuando el paciente no tiene seguro registrado', function () {
    $person = Person::create(['name' => 'Paciente Sin Seguro '.uniqid()]);

    actingAs($this->admin, 'user')
        ->getJson(route('admin.contacts.persons.insurance_status', $person->id))
        ->assertStatus(200)
        ->assertJson([
            'ci'           => null,
            'seguro_id'    => null,
            'seguro_label' => null,
        ]);
});
