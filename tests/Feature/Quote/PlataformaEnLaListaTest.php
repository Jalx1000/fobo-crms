<?php

use Illuminate\Support\Facades\DB;

/**
 * Cotizaciones en el celular (21/10/2026): la plataforma se elige en el panel
 * «Filtros», que la deja en la URL, igual que en la lista de Pedidos. La tabla
 * tiene que leerla de ahí, o el filtro no hacía nada.
 */
beforeEach(function () {
    $this->cotizacionPor = function (string $gateway) {
        $persona = DB::table('persons')->insertGetId([
            'name' => 'Plataforma '.uniqid(), 'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);

        DB::table('whatsapp_conversations')->insert([
            'gateway'         => $gateway, 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Plataforma',
            'person_id'       => $persona, 'status' => 'open', 'ai_enabled' => false,
            'last_message_at' => '2020-01-15 10:00:00', 'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);

        return DB::table('quotes')->insertGetId([
            'subject'    => 'Plataforma '.uniqid(), 'person_id' => $persona, 'user_id' => getDefaultAdmin()->id,
            'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);
    };

    $this->porMessenger = ($this->cotizacionPor)('messenger');
    $this->porKommo = ($this->cotizacionPor)('kommo');
});

function idsDeCotizacionesPorPlataforma(array $params): array
{
    return array_column(
        test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('admin.quotes.index', array_merge(['pagination' => ['page' => 1, 'per_page' => 50]], $params)))->assertOk()->json('records'),
        'id'
    );
}

it('la lista de cotizaciones filtra por la plataforma de la URL', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    expect(idsDeCotizacionesPorPlataforma([]))->toContain($this->porMessenger, $this->porKommo)
        ->and(idsDeCotizacionesPorPlataforma(['platform' => 'messenger']))
        ->toContain($this->porMessenger)->not->toContain($this->porKommo);
});
