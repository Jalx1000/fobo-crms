<?php

use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;

/**
 * get_pedidos asks one question — "what has this number ordered?" — and it has
 * to answer the same whether the caller says 59176616013 or 76616013, and
 * whether the CRM stored the number with a country code or without.
 */
beforeEach(fn () => DB::beginTransaction());

afterEach(fn () => DB::rollBack());

function orderingCustomer(): array
{
    do {
        $phone = '5917'.random_int(1000000, 9999999);
    } while (app(PersonRepository::class)->findByPhone($phone));

    $pipeline = app(PipelineRepository::class)->getDefaultPipeline();

    $lead = app(LeadRepository::class)->create([
        'title'                  => 'Promo Varietales',
        'entity_type'            => 'leads',
        'lead_value'             => 239,
        'lead_pipeline_id'       => $pipeline->id,
        'lead_pipeline_stage_id' => $pipeline->stages()->first()->id,
        'person'                 => [
            'name'            => 'Cliente de prueba',
            'emails'          => [],
            'contact_numbers' => [['label' => 'work', 'value' => $phone]],
        ],
    ]);

    return [$phone, $lead];
}

it('returns the order history for the full number', function () {
    [$phone, $lead] = orderingCustomer();

    actingAsSanctumAuthenticatedAdmin();

    $response = test()->getJson(route('api.pedidos.por-telefono', ['telefono' => $phone]));

    $response->assertStatus(200)
        ->assertJsonPath('persona.id', $lead->person_id)
        ->assertJsonPath('pedidos.0.id', $lead->id)
        ->assertJsonPath('pedidos.0.titulo', 'Promo Varietales');
});

it('finds the same history from the local number, without the country code', function () {
    [$phone, $lead] = orderingCustomer();

    actingAsSanctumAuthenticatedAdmin();

    // The number as a customer would type it: no 591, no "+".
    $local = substr($phone, 3);

    test()->getJson(route('api.pedidos.por-telefono', ['telefono' => $local]))
        ->assertStatus(200)
        ->assertJsonPath('persona.id', $lead->person_id)
        ->assertJsonPath('total', 1);
});

it('ignores "+" and spaces', function () {
    [$phone, $lead] = orderingCustomer();

    actingAsSanctumAuthenticatedAdmin();

    $formatted = '+'.substr($phone, 0, 3).' '.substr($phone, 3);

    test()->getJson(route('api.pedidos.por-telefono', ['telefono' => $formatted]))
        ->assertStatus(200)
        ->assertJsonPath('persona.id', $lead->person_id);
});

it('answers an unknown number with an empty list, not a 404', function () {
    actingAsSanctumAuthenticatedAdmin();

    do {
        $phone = '5917'.random_int(1000000, 9999999);
    } while (app(PersonRepository::class)->findByPhone($phone));

    test()->getJson(route('api.pedidos.por-telefono', ['telefono' => $phone]))
        ->assertStatus(200)
        ->assertJsonPath('persona', null)
        ->assertJsonPath('pedidos', [])
        ->assertJsonPath('total', 0);
});

it('rejects a number too short to identify anybody', function () {
    actingAsSanctumAuthenticatedAdmin();

    test()->getJson(route('api.pedidos.por-telefono', ['telefono' => '123']))->assertStatus(400);
    test()->getJson(route('api.pedidos.por-telefono'))->assertStatus(400);
});

it('requires authentication', function () {
    test()->flushHeaders()->getJson(route('api.pedidos.por-telefono', ['telefono' => '59177493380']))
        ->assertStatus(401);
});
