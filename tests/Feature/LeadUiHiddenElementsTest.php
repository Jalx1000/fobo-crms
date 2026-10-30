<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Requerimiento: en la barra superior de etapas del detalle de un lead se
 * OCULTA (no se elimina) el selector "Ganado/Perdido". El bloque queda
 * envuelto en @if(false), por lo que Blade no lo renderiza.
 */
it('la barra de etapas del lead no muestra el selector Ganado/Perdido', function () {
    $lead = crearLeadDePrueba();

    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.leads.view', $lead->id))
        ->assertStatus(200)
        // El toggle "Ganado/Perdido" (label combinado y único) ya no se pinta.
        ->assertDontSee(trans('admin::app.leads.view.stages.won-lost'))
        // El handler que abre el modal desde el dropdown Won/Lost tampoco está.
        ->assertDontSee('openModal(this.stages.find', false);
});

/**
 * Requerimiento: ocultar el "Valor total" en el detalle del lead. El atributo
 * lead_value se excluyó de la vista de atributos (NOTIN), por lo que no se
 * renderiza su binding de actualización.
 */
it('el detalle del lead no expone el atributo de valor (lead_value)', function () {
    $lead = crearLeadDePrueba();

    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.leads.view', $lead->id))
        ->assertStatus(200)
        // El control del atributo lead_value en el panel de atributos usa el
        // binding Vue ::name="'lead_value'"; al excluirlo del NOTIN ya no se pinta.
        ->assertDontSee("::name=\"'lead_value'\"", false);
});

/**
 * Requerimiento: ocultar el "Valor total" en el Kanban de Leads, tanto el
 * total por etapa (cabecera de columna) como el valor por tarjeta. Ambos
 * quedan comentados con {{-- --}}, así que sus bindings Vue desaparecen del HTML.
 */
it('el kanban de leads no renderiza el valor total por etapa ni por tarjeta', function () {
    crearLeadDePrueba();

    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.leads.index'))
        ->assertStatus(200)
        // Total por etapa (cabecera de columna).
        ->assertDontSee('formatPrice(stage.lead_value)', false)
        // Valor por tarjeta del lead.
        ->assertDontSee('element.formatted_lead_value', false);
});

/**
 * Helper: crea un lead válido en el pipeline por defecto para poder abrir su
 * vista de detalle.
 */
function crearLeadDePrueba()
{
    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();
    $stage = $pipeline->stages->first();

    $person = Person::create([
        'name'        => 'Paciente UI '.uniqid(),
        'entity_type' => 'persons',
    ]);

    return app(LeadRepository::class)->create([
        'title'                  => 'Lead UI '.uniqid(),
        'entity_type'            => 'leads',
        'lead_pipeline_id'       => $pipeline->id,
        'lead_pipeline_stage_id' => $stage->id,
        'user_id'                => 1,
        'person'                 => ['id' => $person->id],
    ]);
}
