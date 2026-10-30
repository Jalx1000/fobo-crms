<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\ReceptionStage;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
| La lista de WhatsApp dice quién tiene que responder cada chat. Caso real
| (dev, 29/10/2026): el paciente pidió una recepcionista, el agente dijo
| «Estoy avisando a una recepcionista» y nunca llamó a /handoff; en la lista
| seguía «IA» y nadie se enteró.
*/

beforeEach(function () {
    config(['whatsapp.ai.enabled' => true, 'whatsapp.ai.test_mode' => false]);
    ReceptionStage::flush();
});

function attentionConversation(array $attrs = []): Conversation
{
    $person = Person::create([
        'name'            => 'Paciente Atención '.uniqid(),
        'contact_numbers' => [['value' => '7'.rand(1000000, 9999999), 'label' => 'work']],
        'entity_type'     => 'persons',
    ]);

    return Conversation::create(array_merge([
        'gateway'         => config('whatsapp.gateway'),
        'wa_phone'        => '5917'.rand(1000000, 9999999),
        'status'          => 'open',
        'person_id'       => $person->id,
        'ai_enabled'      => true,
        'last_message_at' => now(),
    ], $attrs));
}

function attentionMessage(Conversation $conversation, string $direction, string $body, string $sender, $at): Message
{
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction'       => $direction,
        'type'            => 'text',
        'body'            => $body,
        'status'          => 'delivered',
        'sender'          => $sender,
    ]);

    $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

    return $message;
}

function conversationRow(Conversation $conversation, array $params = []): ?array
{
    $rows = actingAs(User::find(1), 'user')
        ->getJson(route('admin.whatsapp.conversations', $params + ['search' => $conversation->wa_phone]))
        ->assertOk()
        ->json('data');

    return collect($rows)->firstWhere('id', $conversation->id);
}

it('un paciente que pide una recepcionista y nadie le responde se marca aunque el agente siga activo', function () {
    $c = attentionConversation();
    attentionMessage($c, 'inbound', 'quiero hablar con una recepcionista', 'contact', now()->subMinutes(3));
    attentionMessage($c, 'outbound', 'Estoy avisando a una recepcionista', 'ia', now()->subMinutes(2));

    expect(conversationRow($c)['status'])->toBe('asks_human')
        ->and(conversationRow($c, ['filter' => 'attention']))->not->toBeNull();
});

it('reconoce el pedido sin importar mayúsculas', function () {
    $c = attentionConversation();
    attentionMessage($c, 'inbound', 'Quiero HABLAR CON ALGUIEN por favor', 'contact', now()->subMinute());

    expect(conversationRow($c)['status'])->toBe('asks_human');
});

it('cuando una persona responde, deja de pedir atención', function () {
    $c = attentionConversation(['ai_enabled' => false]);
    attentionMessage($c, 'inbound', 'quiero hablar con una recepcionista', 'contact', now()->subMinutes(5));
    attentionMessage($c, 'outbound', 'Hola, soy Ana de recepción', 'human', now()->subMinutes(4));

    expect(conversationRow($c)['status'])->toBe('human')
        ->and(conversationRow($c, ['filter' => 'attention']))->toBeNull();
});

it('un pedido de hace más de 24 h ya no cuenta', function () {
    $c = attentionConversation();
    attentionMessage($c, 'inbound', 'quiero hablar con una recepcionista', 'contact', now()->subHours(25));

    expect(conversationRow($c)['status'])->toBe('ia');
});

it('una derivación por /handoff sin respuesta es «Derivada», y deja de serlo al responder', function () {
    $c = attentionConversation(['ai_enabled' => false, 'ai_handoff_at' => now()->subMinutes(10), 'ai_disabled_at' => now()->subMinutes(10)]);
    attentionMessage($c, 'inbound', 'necesito cambiar mi cita', 'contact', now()->subMinutes(11));

    expect(conversationRow($c)['status'])->toBe('derived')
        ->and(conversationRow($c, ['filter' => 'attention']))->not->toBeNull();

    attentionMessage($c, 'outbound', 'Claro, ¿para qué día?', 'human', now()->subMinute());

    expect(conversationRow($c)['status'])->toBe('human');
});

it('una conversación normal con el agente es «IA» y sin agente es «Humano»', function () {
    $ia = attentionConversation();
    attentionMessage($ia, 'inbound', 'hola, quiero una cita', 'contact', now()->subMinute());

    $human = attentionConversation(['ai_enabled' => false]);
    attentionMessage($human, 'inbound', 'hola', 'contact', now()->subMinute());

    expect(conversationRow($ia)['status'])->toBe('ia')
        ->and(conversationRow($human)['status'])->toBe('human');
});

it('la lista trae los totales de no leídas y de «Atender»', function () {
    $c = attentionConversation(['unread_count' => 3]);
    attentionMessage($c, 'inbound', 'quiero hablar con un asesor', 'contact', now()->subMinute());

    $counts = actingAs(User::find(1), 'user')
        ->getJson(route('admin.whatsapp.conversations'))
        ->assertOk()
        ->json('counts');

    expect($counts['unread'])->toBeGreaterThanOrEqual(1)
        ->and($counts['attention'])->toBeGreaterThanOrEqual(1);
});

it('con el chat abierto, lo que llega se marca leído en cada consulta, no solo al abrir', function () {
    $c = attentionConversation(['unread_count' => 2]);

    actingAs(User::find(1), 'user')
        ->getJson(route('admin.whatsapp.thread', ['conversation_id' => $c->id, 'since' => now()->subMinute()->toDateTimeString()]))
        ->assertOk();

    expect($c->fresh()->unread_count)->toBe(0);
});
