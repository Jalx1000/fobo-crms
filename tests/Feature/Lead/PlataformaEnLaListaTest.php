<?php

use Illuminate\Support\Facades\DB;

/**
 * Pedidos en el celular (22/10/2026): la plataforma se elige en el panel
 * «Filtros», que la deja en la URL. El kanban ya la leía de ahí; la lista
 * (tabla) también tiene que hacerlo, o el filtro no hacía nada en esa vista.
 * Todo el 15/01/2020, para no mezclarse con los datos reales.
 */
beforeEach(function () {
    $etapa = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', 'no-atendidos')->value('id');

    $this->pedidoPor = function (string $gateway) use ($etapa) {
        $persona = DB::table('persons')->insertGetId([
            'name' => 'Plataforma '.uniqid(), 'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);

        DB::table('whatsapp_conversations')->insert([
            'gateway'         => $gateway, 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Plataforma',
            'person_id'       => $persona, 'status' => 'open', 'ai_enabled' => false,
            'last_message_at' => '2020-01-15 10:00:00', 'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);

        return DB::table('leads')->insertGetId([
            'title'            => 'Plataforma '.uniqid(), 'user_id' => getDefaultAdmin()->id, 'status' => 1, 'person_id' => $persona,
            'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => $etapa,
            'created_at'       => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
        ]);
    };

    $this->porMessenger = ($this->pedidoPor)('messenger');
    $this->porKommo = ($this->pedidoPor)('kommo');
    $this->fechas = ['date_filter' => 'custom', 'date_from' => '2020-01-15', 'date_to' => '2020-01-15'];
});

function idsDeLaListaPorPlataforma(array $params): array
{
    return array_column(
        test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('admin.leads.index', array_merge(['pipeline_id' => 1, 'view_type' => 'table'], $params)))->assertOk()->json('records'),
        'id'
    );
}

it('la lista de pedidos filtra por la plataforma de la URL, igual que el kanban', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    expect(idsDeLaListaPorPlataforma($this->fechas))->toContain($this->porMessenger, $this->porKommo)
        ->and(idsDeLaListaPorPlataforma([...$this->fechas, 'platform' => 'messenger']))
        ->toContain($this->porMessenger)->not->toContain($this->porKommo);
});
