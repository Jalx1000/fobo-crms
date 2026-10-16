<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\User;

/**
 * C1 (spec docs/specs/tablero-por-asesor.md, D13): el asesor de un pedido lo
 * cambian solo Administrador y Supervisores. Un ejecutivo no puede pasarse ni
 * pasarle pedidos a otro por ningún camino; el reparto automático (sin
 * usuario logueado) sí los mueve.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();

    $this->pedido = DB::table('leads')->insertGetId([
        'title'            => 'Asesor '.uniqid(), 'user_id' => $this->bulmaro->id, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'       => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
    ]);
});

function duenoDe(int $pedido): int
{
    return (int) DB::table('leads')->where('id', $pedido)->value('user_id');
}

it('un ejecutivo no cambia el asesor desde la edición en línea', function () {
    test()->actingAs($this->bulmaro, 'user')
        ->putJson(route('admin.leads.attributes.update', ['id' => $this->pedido]), ['user_id' => $this->eduardo->id]);

    expect(duenoDe($this->pedido))->toBe($this->bulmaro->id);
});

it('un ejecutivo no cambia el asesor por el repositorio (formulario, inbox, cualquier camino)', function () {
    $this->actingAs($this->bulmaro, 'user');
    app(LeadRepository::class)->update(['user_id' => $this->eduardo->id], $this->pedido);

    expect(duenoDe($this->pedido))->toBe($this->bulmaro->id);
});

it('un pedido que crea un ejecutivo queda a su nombre aunque mande otro asesor', function () {
    $this->actingAs($this->bulmaro, 'user');
    $lead = app(LeadRepository::class)->create([
        'title'            => 'Nuevo '.uniqid(), 'user_id' => $this->eduardo->id, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'entity_type'      => 'leads',
    ]);

    expect((int) $lead->user_id)->toBe($this->bulmaro->id);
});

it('el supervisor sí cambia el asesor', function () {
    test()->actingAs($this->mariano, 'user')
        ->putJson(route('admin.leads.attributes.update', ['id' => $this->pedido]), ['user_id' => $this->eduardo->id])
        ->assertOk();

    expect(duenoDe($this->pedido))->toBe($this->eduardo->id);
});

it('sin usuario logueado (reparto, agente, jobs) el asesor se puede cambiar', function () {
    auth()->guard('user')->logout();
    app(LeadRepository::class)->update(['user_id' => $this->eduardo->id], $this->pedido);

    expect(duenoDe($this->pedido))->toBe($this->eduardo->id);
});

it('el formulario del pedido ofrece cambiar el asesor solo a quien puede', function () {
    $deBulmaro = test()->actingAs($this->bulmaro, 'user')->get(route('admin.leads.edit', $this->pedido))->assertOk()->getContent();
    $deMariano = test()->actingAs($this->mariano, 'user')->get(route('admin.leads.edit', $this->pedido))->assertOk()->getContent();

    expect($deMariano)->toContain('name="user_id"')
        ->and($deBulmaro)->not->toContain('name="user_id"');
});
