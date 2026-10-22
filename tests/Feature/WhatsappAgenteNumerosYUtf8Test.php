<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Agente para números puntuales con el agente apagado para todos, y texto UTF-8
 * inválido que no tumba el guardado.
 */
uses(DatabaseTransactions::class);

beforeEach(fn () => Queue::fake());

function numerosConversacion(string $telefono, ?bool $ia = null): object
{
    return ConversationProxy::modelClass()::create([
        'gateway' => 'cloud_api', 'wa_phone' => $telefono, 'wa_name' => 'Prueba',
        'ai_enabled' => $ia, 'last_inbound_at' => now(), 'last_message_at' => now(),
    ]);
}

function numerosHabilitar(string $lista): void
{
    DB::table('core_config')->updateOrInsert(['code' => 'whatsapp.agente.numeros_habilitados'], ['value' => $lista]);
    app()->forgetInstance('whatsapp.numeros_con_agente');
    app()->forgetInstance('core');
}

it('con el agente apagado para todos, un número de la lista lo tiene igual', function () {
    config(['whatsapp.ai.enabled' => false]);
    numerosHabilitar("76616013\n75640698, +59175565205");

    expect(numerosConversacion('+59175640698')->aiEffective())->toBeTrue()
        ->and(numerosConversacion('+59176616013')->aiEffective())->toBeTrue()
        ->and(numerosConversacion('+5493884000000')->aiEffective())->toBeFalse();
});

it('si un asesor lo apagó en la conversación, vale lo que eligió', function () {
    config(['whatsapp.ai.enabled' => false]);
    numerosHabilitar('75640698');

    expect(numerosConversacion('+59175640698', false)->aiEffective())->toBeFalse();
});

it('una respuesta del agente con UTF-8 inválido se guarda, sin el carácter roto', function () {
    actingAsSanctumAuthenticatedAdmin();
    $conv = numerosConversacion('+59175565205', true);

    // Medio emoji (surrogate suelto en CESU-8): lo que rompía el json_encode del payload.
    $texto = "Hola 💧 elegí:\n1.-) Sí\n2.-) No \xED\xA0\xBD";

    $respuesta = test()->call('POST', "/api/v1/whatsapp/conversations/{$conv->id}/messages", ['text' => $texto]);

    $respuesta->assertOk();

    $mensaje = $conv->messages()->latest('id')->first();
    $opciones = collect($mensaje->payload['interactive']['options'] ?? [])->pluck('title')->implode(' ');

    // Con opciones numeradas, el cuerpo es la pregunta y las opciones van como botones.
    expect(mb_check_encoding($mensaje->body, 'UTF-8'))->toBeTrue()
        ->and($mensaje->body)->toContain('💧')
        ->and(mb_check_encoding($opciones, 'UTF-8'))->toBeTrue()
        ->and($opciones)->toContain('Sí');
});

it('también toma los números de WHATSAPP_AI_NUMEROS, separados por coma', function () {
    config(['whatsapp.ai.enabled' => false, 'whatsapp.ai.numeros' => '76616013,+59175565205']);
    numerosHabilitar('');

    expect(numerosConversacion('+59175565205')->aiEffective())->toBeTrue()
        ->and(numerosConversacion('+59176616013')->aiEffective())->toBeTrue()
        ->and(numerosConversacion('+59175640698')->aiEffective())->toBeFalse();
});
