<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * CRM-1: la opción elegida en un menú, aunque llegue como texto.
 *
 * Por Kommo, tocar un botón o una fila de lista llega como un mensaje de texto
 * con el título, sin el `interactive` de Meta. El agente no sabía qué se había
 * elegido y repetía el menú (auditoría del 12/10: 357 de 1.256 mensajes del
 * cliente eran el título exacto de una opción). Ahora el CRM reconoce el título
 * contra el ÚLTIMO menú enviado y arma `selection` con su id.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Sel',
        'ai_enabled' => true, 'status' => 'open',
    ]);
});

function selMenu($conv, array $interactive): Message
{
    return Message::create([
        'conversation_id' => $conv->id, 'direction' => 'outbound', 'type' => 'interactive', 'sender' => 'ia',
        'status'          => 'sent', 'body' => $interactive['body'] ?? 'menú', 'payload' => ['interactive' => $interactive],
    ]);
}

function selBotones($conv): Message
{
    return selMenu($conv, ['format' => 'button', 'body' => '¿Qué tipo de rollo?', 'sections' => [], 'buttons' => [
        ['id' => 'Manual 4,7 kg', 'title' => 'Manual (4,7 kg)'],
        ['id' => 'Automático 15,7 kg', 'title' => 'Automático (15,7 kg)'],
    ]]);
}

function selLista($conv): Message
{
    return selMenu($conv, ['format' => 'list', 'body' => '¿Qué producto?', 'buttons' => [], 'sections' => [[
        'title' => 'Productos', 'rows' => [
            ['id' => 'CM_00002', 'title' => '🟢 Bolsas Magia Verde'],
            ['id' => 'CM_00001', 'title' => '⚪ Stretch Film'],
        ],
    ]]]);
}

function selCliente($conv, string $texto, array $payload = []): Message
{
    return Message::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'type' => $payload ? 'interactive' : 'text',
        'sender'          => 'contact', 'status' => 'received', 'body' => $texto, 'payload' => $payload ?: null,
    ]);
}

function selDe(Message $m): ?array
{
    return app(AgentPayload::class)->event($m->fresh('conversation'))['message']['selection'];
}

it('reconoce el título de un botón y manda su id', function () {
    selBotones($this->conv);

    expect(selDe(selCliente($this->conv, 'Manual (4,7 kg)')))
        ->toBe(['id' => 'Manual 4,7 kg', 'title' => 'Manual (4,7 kg)']);
});

it('reconoce una fila de lista, sin importar mayúsculas ni espacios', function () {
    selLista($this->conv);

    expect(selDe(selCliente($this->conv, '  ⚪ STRETCH FILM ')))
        ->toBe(['id' => 'CM_00001', 'title' => '⚪ Stretch Film']);
});

it('el texto libre no es una selección', function () {
    selLista($this->conv);

    expect(selDe(selCliente($this->conv, 'Quiero stretch film para embalar')))->toBeNull();
});

it('solo cuenta el último menú enviado', function () {
    selLista($this->conv);
    selBotones($this->conv);

    expect(selDe(selCliente($this->conv, '⚪ Stretch Film')))->toBeNull();
});

it('la respuesta interactiva nativa de Cloud API sigue funcionando', function () {
    selLista($this->conv);

    $m = selCliente($this->conv, '⚪ Stretch Film', ['interactive' => [
        'type' => 'list_reply', 'list_reply' => ['id' => 'CM_00001', 'title' => '⚪ Stretch Film'],
    ]]);

    expect(selDe($m))->toBe(['id' => 'CM_00001', 'title' => '⚪ Stretch Film']);
});
