<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/**
 * Este test valida la sincronización bidireccional de CI y Seguro
 * entre las entidades de Paciente (Person) y Prospecto (Lead).
 */
beforeEach(function () {
    $this->adminUser = User::find(1);

    // Atributos de Person
    $this->ciPersonAttr = Attribute::where('code', 'ci_paciente')->where('entity_type', 'persons')->first();
    $this->seguroPersonAttr = Attribute::where('code', 'seguro_paciente')->where('entity_type', 'persons')->first();

    // Atributos de Lead
    $this->ciLeadAttr = Attribute::where('code', 'ci')->where('entity_type', 'leads')->first();
    $this->insuranceLeadAttr = Attribute::where('code', 'insurance')->where('entity_type', 'leads')->first();
});

it('sincroniza de Paciente a Prospecto al actualizar el paciente', function () {
    // 0. Obtener ID dinámico para "Nacional Vida" en Persons
    $nacionalVidaPersonId = $this->seguroPersonAttr->options->where('name', 'Nacional Vida')->first()->id;
    $nacionalVidaLeadId = $this->insuranceLeadAttr->options->where('name', 'Nacional Vida')->first()->id;

    // 1. Crear paciente y prospecto vinculado
    $person = Person::create(['name' => 'Paciente Sincronizado '.uniqid()]);

    $lead = Lead::create([
        'title'                  => 'Lead Sincronizado',
        'person_id'              => $person->id,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => 1,
        'status'                 => 1, // Activo
        'user_id'                => 1,
    ]);

    // 2. Actualizar el paciente con CI y Seguro dinámico
    $updateData = [
        'name'            => $person->name,
        'ci_paciente'     => '88877766',
        'seguro_paciente' => $nacionalVidaPersonId,
        'entity_type'     => 'persons',
    ];

    actingAs($this->adminUser, 'user')
        ->put(route('admin.contacts.persons.update', $person->id), $updateData)
        ->assertRedirect();

    // 3. Verificar que el Lead se haya actualizado automáticamente
    $lead->refresh();

    $ciValue = DB::table('attribute_values')
        ->where('entity_id', $lead->id)
        ->where('entity_type', 'leads')
        ->where('attribute_id', $this->ciLeadAttr->id)
        ->value('text_value');

    $insuranceValue = DB::table('attribute_values')
        ->where('entity_id', $lead->id)
        ->where('entity_type', 'leads')
        ->where('attribute_id', $this->insuranceLeadAttr->id)
        ->value('text_value');

    expect($ciValue)->toBe('88877766');
    expect($insuranceValue)->toContain((string) $nacionalVidaLeadId);
});

it('sincroniza de Prospecto a Paciente al crear/actualizar el prospecto', function () {
    // 0. Obtener ID dinámico para "Alianza"
    $alianzaLeadId = $this->insuranceLeadAttr->options->where('name', 'Alianza')->first()->id;
    $alianzaPersonId = $this->seguroPersonAttr->options->where('name', 'Alianza')->first()->id;

    // 1. Crear paciente vacío
    $person = Person::create(['name' => 'Paciente Receptor '.uniqid()]);

    // 2. Crear lead con datos de CI e Insurance dinámicos
    $leadData = [
        'title'                  => 'Lead Emisor',
        'person'                 => ['id' => $person->id],
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => 1,
        'user_id'                => 1,
        'ci'                     => '11223344',
        'insurance'              => [$alianzaLeadId],
        'entity_type'            => 'leads',
    ];

    actingAs($this->adminUser, 'user')
        ->post(route('admin.leads.store'), $leadData)
        ->assertRedirect();

    // 3. Verificar que el Paciente se haya actualizado automáticamente
    $person->refresh();

    $ciValue = DB::table('attribute_values')
        ->where('entity_id', $person->id)
        ->where('entity_type', 'persons')
        ->where('attribute_id', $this->ciPersonAttr->id)
        ->value('text_value');

    $seguroValue = DB::table('attribute_values')
        ->where('entity_id', $person->id)
        ->where('entity_type', 'persons')
        ->where('attribute_id', $this->seguroPersonAttr->id)
        ->value('integer_value');

    expect($ciValue)->toBe('11223344');
    expect($seguroValue)->toBe($alianzaPersonId);
});
