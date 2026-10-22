<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Las fichas de pedido y de persona abren en la pestaña WhatsApp (el panel
 * derecho), que es donde se atiende al cliente.
 */
uses(DatabaseTransactions::class);

it('la ficha del pedido abre en WhatsApp', function () {
    $lead = DB::table('leads')->value('id') ?: test()->markTestSkipped('No hay pedidos.');

    test()->actingAs(getDefaultAdmin(), 'user')
        ->get(route('admin.leads.view', $lead))
        ->assertOk()
        ->assertSee('active-type="whatsapp"', false);
});

it('la ficha de la persona abre en WhatsApp', function () {
    $persona = DB::table('persons')->value('id') ?: test()->markTestSkipped('No hay personas.');

    test()->actingAs(getDefaultAdmin(), 'user')
        ->get(route('admin.contacts.persons.view', $persona))
        ->assertOk()
        ->assertSee('active-type="whatsapp"', false);
});
