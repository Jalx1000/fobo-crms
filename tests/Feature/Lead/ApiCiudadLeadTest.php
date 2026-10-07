<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Webkul\User\Models\User;

/**
 * API REST de leads (krayin/rest-api): persistir y devolver el atributo
 * personalizado `ciudad` (select, entity_type leads).
 *
 * Handoff del agente IMPRIMIR (2026-10-07): `PUT /api/v1/leads/{id}` con
 * `{"lead_pipeline_id": 8, "lead_pipeline_stage_id": 31, "ciudad": 42}`
 * respondía 200 pero no guardaba nada, y el GET no devolvía la ciudad.
 *
 * Contra la base del contenedor con transacciones; nada sale (Mail, Http,
 * cola falseados).
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    Http::fake();

    Sanctum::actingAs(User::findOrFail(1), ['*']);

    $this->ciudadAttr = DB::table('attributes')->where('entity_type', 'leads')->where('code', 'ciudad')->value('id');
    $this->opcion = fn (string $nombre) => (int) DB::table('attribute_options')
        ->where('attribute_id', $this->ciudadAttr)->where('name', $nombre)->value('id');

    $this->stage = DB::table('lead_pipeline_stages')->orderBy('id')->first();

    $person = DB::table('persons')->insertGetId([
        'name' => 'API CIUDAD '.uniqid(), 'emails' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->leadId = DB::table('leads')->insertGetId([
        'title'            => 'Lead API ciudad', 'user_id' => 1, 'person_id' => $person, 'status' => 1,
        'lead_pipeline_id' => $this->stage->lead_pipeline_id, 'lead_pipeline_stage_id' => $this->stage->id,
        'created_at'       => now(), 'updated_at' => now(),
    ]);
});

function ciudadGuardada($test): ?int
{
    $v = DB::table('attribute_values')->where('entity_type', 'leads')
        ->where('entity_id', $test->leadId)->where('attribute_id', $test->ciudadAttr)->value('integer_value');

    return $v === null ? null : (int) $v;
}

function putLead($test, array $body)
{
    return $test->putJson('/api/v1/leads/'.$test->leadId, array_merge([
        'lead_pipeline_id'       => $test->stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $test->stage->id,
    ], $body));
}

it('el PUT del agente guarda la ciudad (id de opción)', function () {
    putLead($this, ['ciudad' => ($this->opcion)('Cochabamba')])->assertOk();

    expect(ciudadGuardada($this))->toBe(($this->opcion)('Cochabamba'));
});

it('acepta la ciudad como texto: id en string o nombre', function () {
    putLead($this, ['ciudad' => (string) ($this->opcion)('La Paz')])->assertOk();
    expect(ciudadGuardada($this))->toBe(($this->opcion)('La Paz'));

    putLead($this, ['ciudad' => 'Potosi'])->assertOk(); // sin tilde
    expect(ciudadGuardada($this))->toBe(($this->opcion)('Potosí'));
});

it('el GET devuelve la ciudad: id, nombre y bloque custom_attributes', function () {
    putLead($this, ['ciudad' => ($this->opcion)('Cochabamba')])->assertOk();

    $this->getJson('/api/v1/leads/'.$this->leadId)
        ->assertOk()
        ->assertJsonPath('data.ciudad', ($this->opcion)('Cochabamba'))
        ->assertJsonPath('data.ciudad_nombre', 'Cochabamba')
        ->assertJsonPath('data.custom_attributes.ciudad.value', ($this->opcion)('Cochabamba'))
        ->assertJsonPath('data.custom_attributes.ciudad.label', 'Cochabamba');
});

it('la respuesta del PUT también trae la ciudad', function () {
    putLead($this, ['ciudad' => ($this->opcion)('Tarija')])
        ->assertOk()
        // Forma del paquete: {"data": {lead}, "message": "..."}.
        ->assertJsonPath('data.ciudad_nombre', 'Tarija')
        ->assertJsonPath('message', fn ($m) => filled($m));
});

it('un PUT sin ciudad no borra la que ya tenía', function () {
    putLead($this, ['ciudad' => ($this->opcion)('Sucre')])->assertOk();
    putLead($this, [])->assertOk();

    expect(ciudadGuardada($this))->toBe(($this->opcion)('Sucre'));
});

it('una ciudad que no existe no se guarda ni rompe el PUT', function () {
    putLead($this, ['ciudad' => 'Atlantis'])->assertOk();

    expect(ciudadGuardada($this))->toBeNull();
});
