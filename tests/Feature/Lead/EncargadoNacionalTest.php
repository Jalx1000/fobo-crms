<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Encargado nacional (20/10/2026, pedido del usuario): Bulmaro, encargado de
 * Bolivia, sigue como Ejecutivo Comercial y ve solo lo suyo, pero puede
 * reasignar sus pedidos, cotizaciones, contactos y derivaciones, SOLO a otro
 * Ejecutivo Comercial. Cuando lo pasa, deja de verlo.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();
    $fecha = '2020-01-15 10:00:00';

    config(['reporting.encargados_nacionales' => [$this->bulmaro->email]]);

    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Encargado '.uniqid(), 'user_id' => $this->bulmaro->id, 'created_at' => $fecha, 'updated_at' => $fecha,
    ]);
    $this->pedido = DB::table('leads')->insertGetId([
        'title'            => 'Encargado '.uniqid(), 'user_id' => $this->bulmaro->id, 'person_id' => $this->persona, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'       => $fecha, 'updated_at' => $fecha,
    ]);
    $this->cotizacion = DB::table('quotes')->insertGetId([
        'subject' => 'Encargado', 'user_id' => $this->bulmaro->id, 'person_id' => $this->persona, 'created_at' => $fecha, 'updated_at' => $fecha,
    ]);
    DB::table('lead_quotes')->insert(['lead_id' => $this->pedido, 'quote_id' => $this->cotizacion]);
});

function asesorDeLa(string $tabla, int $id): int
{
    return (int) DB::table($tabla)->where('id', $id)->value('user_id');
}

it('pasa su pedido a otro ejecutivo: la cotización y el contacto lo siguen, y deja de verlo', function () {
    test()->actingAs($this->bulmaro, 'user')
        ->putJson(route('admin.leads.attributes.update', ['id' => $this->pedido]), ['user_id' => $this->eduardo->id])
        ->assertOk();

    expect(asesorDeLa('leads', $this->pedido))->toBe($this->eduardo->id)
        ->and(asesorDeLa('quotes', $this->cotizacion))->toBe($this->eduardo->id)
        ->and(asesorDeLa('persons', $this->persona))->toBe($this->eduardo->id);

    // Ya no es suyo: no lo puede abrir.
    expect(test()->actingAs($this->bulmaro, 'user')->get(route('admin.leads.view', $this->pedido))->status())->not->toBe(200);
});

it('no puede pasarlo a quien no es ejecutivo comercial', function () {
    test()->actingAs($this->bulmaro, 'user')
        ->putJson(route('admin.leads.attributes.update', ['id' => $this->pedido]), ['user_id' => $this->mariano->id]);

    expect(asesorDeLa('leads', $this->pedido))->toBe($this->bulmaro->id);
});

it('pasa una cotización y un contacto sueltos, solo a ejecutivos', function () {
    test()->actingAs($this->bulmaro, 'user');

    app(\Webkul\Quote\Repositories\QuoteRepository::class)->update(['user_id' => $this->mariano->id, 'entity_type' => 'quotes'], $this->cotizacion, ['user_id']);
    expect(asesorDeLa('quotes', $this->cotizacion))->toBe($this->bulmaro->id);

    app(\Webkul\Quote\Repositories\QuoteRepository::class)->update(['user_id' => $this->eduardo->id, 'entity_type' => 'quotes'], $this->cotizacion, ['user_id']);
    expect(asesorDeLa('quotes', $this->cotizacion))->toBe($this->eduardo->id);

    app(\Webkul\Contact\Repositories\PersonRepository::class)->update(['user_id' => $this->eduardo->id, 'entity_type' => 'persons'], $this->persona, ['user_id']);
    expect(asesorDeLa('persons', $this->persona))->toBe($this->eduardo->id);
});

it('pasa su derivación a otro ejecutivo, no a un supervisor', function () {
    $conv = Conversation::create([
        'gateway'              => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Encargado',
        'ai_enabled'           => false, 'status' => 'open', 'person_id' => $this->persona, 'lead_id' => $this->pedido,
        'handoff_state'        => Conversation::HANDOFF_ASSIGNED, 'handoff_requested_at' => now(),
        'assigned_user_id'     => $this->bulmaro->id, 'last_message_at' => now(),
    ]);

    test()->actingAs($this->bulmaro, 'user')
        ->postJson(route('admin.whatsapp.handoff.reassign', ['id' => $conv->id]), ['user_id' => $this->mariano->id])
        ->assertForbidden();

    test()->actingAs($this->bulmaro, 'user')
        ->postJson(route('admin.whatsapp.handoff.reassign', ['id' => $conv->id]), ['user_id' => $this->eduardo->id])
        ->assertOk();

    expect($conv->fresh()->assigned_user_id)->toBe($this->eduardo->id);
});

it('su lista de asesores trae solo ejecutivos comerciales y su formulario permite cambiarlo', function () {
    test()->actingAs($this->bulmaro, 'user');

    expect(collect(app(AttributeRepository::class)->getLookUpOptions('users'))->pluck('name')->all())
        ->toContain('Bulmaro Vaca', 'Eduardo Lujan')
        ->not->toContain('Mariano Pinell', 'Agente')
        ->and(test()->get(route('admin.leads.edit', $this->pedido))->assertOk()->getContent())->toContain('name="user_id"');
});

it('otro ejecutivo (no encargado) sigue sin poder reasignar', function () {
    DB::table('leads')->where('id', $this->pedido)->update(['user_id' => $this->eduardo->id]);

    test()->actingAs($this->eduardo, 'user')
        ->putJson(route('admin.leads.attributes.update', ['id' => $this->pedido]), ['user_id' => $this->bulmaro->id]);

    expect(asesorDeLa('leads', $this->pedido))->toBe($this->eduardo->id);
});
