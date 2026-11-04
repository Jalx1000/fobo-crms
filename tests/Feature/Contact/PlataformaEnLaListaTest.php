<?php

use Illuminate\Support\Facades\DB;

/**
 * Personas en el celular (21/10/2026): la plataforma se elige en el panel
 * «Filtros», que la deja en la URL, igual que en Pedidos y Cotizaciones. La tabla
 * tiene que leerla de ahí, o el filtro no hacía nada.
 */
beforeEach(function () {
    $this->personaPor = function (string $gateway) {
        $persona = DB::table('persons')->insertGetId([
            'name' => 'Plataforma '.uniqid(), 'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);

        DB::table('whatsapp_conversations')->insert([
            'gateway'         => $gateway, 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Plataforma',
            'person_id'       => $persona, 'status' => 'open', 'ai_enabled' => false,
            'last_message_at' => '2020-01-15 10:00:00', 'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);

        return $persona;
    };

    $this->porMessenger = ($this->personaPor)('messenger');
    $this->porKommo = ($this->personaPor)('kommo');
});

function idsDePersonasPorPlataforma(array $params): array
{
    return array_column(
        test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('admin.contacts.persons.index', array_merge(['pagination' => ['page' => 1, 'per_page' => 50]], $params)))->assertOk()->json('records'),
        'id'
    );
}

it('la lista de personas filtra por la plataforma de la URL', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    expect(idsDePersonasPorPlataforma([]))->toContain($this->porMessenger, $this->porKommo)
        ->and(idsDePersonasPorPlataforma(['platform' => 'messenger']))
        ->toContain($this->porMessenger)->not->toContain($this->porKommo);
});
