<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\CiudadCliente;
use Webkul\Lead\Services\PipelineDeCiudad;
use Webkul\Product\Services\Responsables;
use Webkul\User\Models\User;

/**
 * Auditoría del 14/10: los pedidos sin ciudad nacían en el tablero de Santa
 * Cruz, y los chats seguían con el asesor anterior cuando el pedido cambiaba
 * de dueño.
 */
uses(DatabaseTransactions::class);

function pipelineId(string $nombre): int
{
    return (int) DB::table('lead_pipelines')->where('name', $nombre)->value('id');
}

function pedidoDePrueba(string $pipeline, ?int $userId = null): object
{
    $pipelineId = pipelineId($pipeline);
    $etapa = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipelineId)->where('code', 'solicitud-recibida')->value('id');

    $personId = DB::table('persons')->insertGetId([
        'name' => 'Prueba pipeline '.uniqid(), 'contact_numbers' => '[]', 'emails' => '[]',
        'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $leadId = DB::table('leads')->insertGetId([
        'title' => 'Prueba pipeline', 'status' => 1, 'user_id' => $userId, 'person_id' => $personId,
        'lead_pipeline_id' => $pipelineId, 'lead_pipeline_stage_id' => $etapa,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return DB::table('leads')->find($leadId);
}

it('un pedido sin ciudad nace en «Sin ciudad» y con ciudad en la suya', function () {
    $p = app(PipelineDeCiudad::class);

    expect($p->para(null)->name)->toBe('Sin ciudad')
        ->and($p->para('La Paz')->name)->toBe('La Paz')
        ->and($p->para('cochabamba')->name)->toBe('Cochabamba');
});

it('al conocer la ciudad, el pedido deja «Sin ciudad» y conserva la etapa', function () {
    $lead = pedidoDePrueba('Sin ciudad');

    app(CiudadCliente::class)->propagar((int) $lead->person_id, 'Tupiza');

    $despues = DB::table('leads')->find($lead->id);
    $etapa = DB::table('lead_pipeline_stages')->find($despues->lead_pipeline_stage_id);

    expect((int) $despues->lead_pipeline_id)->toBe(pipelineId('Potosí'))
        ->and($etapa->code)->toBe('solicitud-recibida');
});

it('un pedido que un asesor puso en el pipeline de una ciudad no se mueve', function () {
    $lead = pedidoDePrueba('Cochabamba');

    expect(app(PipelineDeCiudad::class)->moverSiEspera((int) $lead->id, 'La Paz'))->toBeFalse()
        ->and((int) DB::table('leads')->where('id', $lead->id)->value('lead_pipeline_id'))->toBe(pipelineId('Cochabamba'));
});

it('el chat y el contacto siguen al asesor del pedido', function () {
    $bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();

    $lead = pedidoDePrueba('Sin ciudad', $bulmaro->id);

    $conv = DB::table('whatsapp_conversations')->insertGetId([
        'gateway' => 'cloud_api', 'wa_phone' => '5917'.random_int(1000000, 9999999), 'status' => 'open',
        'person_id' => $lead->person_id, 'lead_id' => $lead->id, 'assigned_user_id' => $bulmaro->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('leads')->where('id', $lead->id)->update(['user_id' => $eduardo->id]);

    Responsables::todoConElPedido((int) $lead->id);

    expect((int) DB::table('whatsapp_conversations')->where('id', $conv)->value('assigned_user_id'))->toBe($eduardo->id)
        ->and((int) DB::table('persons')->where('id', $lead->person_id)->value('user_id'))->toBe($eduardo->id);
});
