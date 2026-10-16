<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
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
require_once __DIR__.'/helpers.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->fabio = User::where('name', 'Fabio Sandoval')->firstOrFail();
    $this->gabriela = User::where('name', 'Gabriela Marconi')->firstOrFail();
});

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

    // Desde el 14/10 Mariano es supervisor: no atiende clientes ni figura en el filtro.
    expect(collect($asesores)->pluck('name'))->toContain('Fabio Sandoval', 'Gabriela Marconi', 'Bulmaro Vaca');
});

it('cada asesor trae cuántos clientes tiene en la bandeja', function () {
    $asesores = fn () => collect(test()->actingAs(User::findOrFail(1), 'user')
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations'))
        ->json('asesores'))->keyBy('name');

    $antes = $asesores()['Fabio Sandoval']['total'];

    bandejaConv($this->fabio->id);
    bandejaConv($this->fabio->id);
    bandejaConv($this->gabriela->id, null, [
        'handoff_state' => Conversation::HANDOFF_ASSIGNED, 'handoff_requested_at' => now(), 'assigned_user_id' => $this->fabio->id,
    ]);

    expect($asesores()['Fabio Sandoval']['total'])->toBe($antes + 3);
});

it('el total de un asesor coincide con lo que trae su filtro', function () {
    bandejaConv($this->gabriela->id);

    $total = collect(test()->actingAs(User::findOrFail(1), 'user')
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations'))
        ->json('asesores'))->firstWhere('name', 'Gabriela Marconi')['total'];

    $filtrada = test()->actingAs(User::findOrFail(1), 'user')
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations', ['asesor' => $this->gabriela->id]))
        ->json('total');

    expect($total)->toBe($filtrada);
});

it('la bandeja carga con el filtro de asesores', function () {
    test()->actingAs(User::findOrFail(1), 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertOk()
        ->assertSee('Todos los asesores', false);
});
