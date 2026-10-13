<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Bandeja de WhatsApp según el reparto de asesores.
 *
 * «Míos» mostraba solo las derivaciones abiertas a mi nombre: los clientes que
 * el reparto (producto × ciudad) me asignó no aparecían. Ahora el asesor de
 * una conversación es, en orden: el de la derivación abierta, el dueño del
 * pedido, el dueño del contacto. La lista lo muestra y se puede filtrar por él.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->fabio = User::where('name', 'Fabio Sandoval')->firstOrFail();
    $this->gabriela = User::where('name', 'Gabriela Marconi')->firstOrFail();
});

function bandejaConv(?int $leadOwner, ?int $personOwner = null, array $extra = []): Conversation
{
    $person = DB::table('persons')->insertGetId(['name' => 'Bandeja '.uniqid(), 'user_id' => $personOwner, 'created_at' => now(), 'updated_at' => now()]);

    $lead = $leadOwner === null ? null : DB::table('leads')->insertGetId([
        'title'            => 'Bandeja '.uniqid(), 'person_id' => $person, 'user_id' => $leadOwner, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'       => now(), 'updated_at' => now(),
    ]);

    return Conversation::create(array_merge([
        'gateway'         => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Bandeja',
        'ai_enabled'      => true, 'status' => 'open', 'person_id' => $person, 'lead_id' => $lead,
        'last_message_at' => now()->addMinute(),
    ], $extra));
}

function bandeja(User $como, array $params = []): array
{
    return collect(test()->actingAs($como, 'user')
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations', array_merge(['per_page' => 300], $params)))
        ->assertOk()
        ->json('data'))->keyBy('id')->all();
}

it('«Míos» trae las conversaciones de mis pedidos aunque no estén derivadas', function () {
    $mia = bandejaConv($this->fabio->id);
    $ajena = bandejaConv($this->gabriela->id);

    $lista = bandeja($this->fabio, ['filter' => 'mine']);

    expect($lista)->toHaveKey($mia->id)->not->toHaveKey($ajena->id);
});

it('una derivación abierta manda sobre el dueño del pedido', function () {
    $conv = bandejaConv($this->gabriela->id, null, [
        'handoff_state' => Conversation::HANDOFF_ASSIGNED, 'handoff_requested_at' => now(), 'assigned_user_id' => $this->fabio->id,
    ]);

    expect(bandeja($this->fabio, ['filter' => 'mine']))->toHaveKey($conv->id)
        ->and(bandeja($this->gabriela, ['filter' => 'mine']))->not->toHaveKey($conv->id);
});

it('una derivación ya cerrada no cuenta: vuelve a ser del dueño del pedido', function () {
    $conv = bandejaConv($this->gabriela->id, null, [
        'handoff_state' => Conversation::HANDOFF_RESOLVED, 'handoff_requested_at' => now()->subDay(), 'assigned_user_id' => $this->fabio->id,
    ]);

    expect(bandeja($this->gabriela, ['filter' => 'mine']))->toHaveKey($conv->id)
        ->and(bandeja($this->fabio, ['filter' => 'mine']))->not->toHaveKey($conv->id);
});

it('sin pedido, cuenta el dueño del contacto', function () {
    $conv = bandejaConv(null, $this->fabio->id);

    expect(bandeja($this->fabio, ['filter' => 'mine']))->toHaveKey($conv->id);
});

it('cada conversación dice de qué asesor es', function () {
    $conv = bandejaConv($this->gabriela->id);

    expect(bandeja(User::findOrFail(1))[$conv->id]['asesor'] ?? null)->toBe('Gabriela Marconi');
});

it('se puede filtrar la bandeja por asesor', function () {
    $deFabio = bandejaConv($this->fabio->id);
    $deGabriela = bandejaConv($this->gabriela->id);

    $lista = bandeja(User::findOrFail(1), ['asesor' => $this->gabriela->id]);

    expect($lista)->toHaveKey($deGabriela->id)->not->toHaveKey($deFabio->id);
});

it('la bandeja trae la lista de asesores para el filtro', function () {
    $asesores = test()->actingAs(User::findOrFail(1), 'user')
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations'))
        ->json('asesores');

    expect(collect($asesores)->pluck('name'))->toContain('Fabio Sandoval', 'Gabriela Marconi', 'Mariano Pinell');
});

it('la bandeja carga con el filtro de asesores', function () {
    test()->actingAs(User::findOrFail(1), 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertOk()
        ->assertSee('Todos los asesores', false);
});
