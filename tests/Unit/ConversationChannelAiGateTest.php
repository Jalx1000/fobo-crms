<?php

use Illuminate\Support\Facades\Config;
use Webkul\Whatsapp\Models\Conversation;

/**
 * El interruptor de IA por canal.
 *
 * Antes, `MESSENGER_AI_ENABLED` solo fijaba el valor inicial de `ai_enabled` al
 * crear la conversacion. Un hilo que alguien habia encendido desde el inbox
 * seguia mandando eventos al agente aunque el canal estuviera apagado en el
 * despliegue. Estos casos fijan que el canal manda.
 *
 * Unit y no Feature a proposito: solo se leen atributos en memoria y config,
 * sin tocar la base.
 */
uses(Tests\TestCase::class);

function conversationOn(string $gateway, ?bool $aiEnabled): Conversation
{
    $conversation = new Conversation;

    $conversation->gateway = $gateway;
    $conversation->ai_enabled = $aiEnabled;

    return $conversation;
}

beforeEach(function () {
    Config::set('whatsapp.ai.enabled', true);
    Config::set('whatsapp.gateways.messenger.ai_default', false);
    Config::set('whatsapp.gateways.cloud_api.ai_default', true);
});

it('blocks the agent on a channel whose AI is off, even when the row says on', function () {
    // El caso que se escapaba: el asesor encendio el switch en el inbox y el
    // canal estaba apagado por configuracion.
    expect(conversationOn('messenger', true)->aiEffective())->toBeFalse()
        ->and(conversationOn('messenger', null)->aiEffective())->toBeFalse()
        ->and(conversationOn('messenger', false)->aiEffective())->toBeFalse();
});

it('leaves the per-conversation switch in charge when the channel allows AI', function () {
    expect(conversationOn('cloud_api', true)->aiEffective())->toBeTrue()
        ->and(conversationOn('cloud_api', false)->aiEffective())->toBeFalse();
});

it('falls back to the global default when the conversation has no preference', function () {
    expect(conversationOn('cloud_api', null)->aiEffective())->toBeTrue();

    Config::set('whatsapp.ai.enabled', false);

    expect(conversationOn('cloud_api', null)->aiEffective())->toBeFalse();
});

it('allows a channel that declares no preference of its own', function () {
    // Kommo no declara ai_default. Los canales que existian antes de esta
    // puerta no deben quedar bloqueados por omision.
    expect(conversationOn('kommo', true)->aiEffective())->toBeTrue()
        ->and(conversationOn('kommo', true)->channelAiAllowed())->toBeTrue();
});

it('reports the channel gate so the inbox can disable the switch', function () {
    expect(conversationOn('messenger', null)->channelAiAllowed())->toBeFalse()
        ->and(conversationOn('cloud_api', null)->channelAiAllowed())->toBeTrue();
});
