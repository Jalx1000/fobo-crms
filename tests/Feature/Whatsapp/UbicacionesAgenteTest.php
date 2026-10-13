<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * Dónde quedan las plantas, en cada mensaje al agente (pedido del 13/10).
 *
 * Un solo almacén, Santa Cruz, con una ubicación por planta y su enlace de
 * Maps. El agente la usa cuando le preguntan dónde están; si el cliente es de
 * otra ciudad, le dice que se hacen envíos.
 */
uses(DatabaseTransactions::class);

function ubicacionesDelAgente(): array
{
    $conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Ubi',
        'ai_enabled' => true, 'status' => 'open',
    ]);

    $mensaje = Message::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'type' => 'text', 'sender' => 'contact',
        'status'          => 'received', 'body' => '¿Dónde quedan?',
    ]);

    return app(AgentPayload::class)->event($mensaje->fresh('conversation'))['ubicaciones'];
}

it('el agente recibe las tres plantas de Santa Cruz con su enlace de Maps', function () {
    $plantas = collect(ubicacionesDelAgente()['plantas'])->keyBy('nombre');

    expect($plantas->keys()->sort()->values()->all())->toBe(['División de Embalajes', 'Envases Flexibles', 'Tapas']);

    foreach ($plantas as $planta) {
        expect($planta['ciudad'])->toBe('Santa Cruz')
            ->and($planta['maps'])->toContain('maps.app.goo.gl');
    }
});

it('cada planta dice qué productos salen de ahí', function () {
    $plantas = collect(ubicacionesDelAgente()['plantas'])->keyBy('nombre');

    expect($plantas['Tapas']['productos'])->toContain('Tapas')
        ->and($plantas['División de Embalajes']['productos'])->toContain('Stretch Film');
});

it('si el cliente no está en Santa Cruz, el agente sabe que se hacen envíos', function () {
    expect(ubicacionesDelAgente()['fuera_de_ciudad'])->toContain('envíos');
});
