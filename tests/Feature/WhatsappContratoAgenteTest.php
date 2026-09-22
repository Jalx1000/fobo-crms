<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;

/*
 * El contrato con el agente, alineado con imprimir.
 *
 * El agente atiende a los DOS tenants desde el mismo proceso. Kohlberg le
 * entregaba la opción tocada bajo la clave `interactive` y no ofrecía el
 * endpoint estructurado; el agente buscaba `selection` y llamaba a
 * .../interactive, así que en kohlberg "no detectaba los botones ni las
 * listas". Estos tests fijan las dos formas.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    DB::beginTransaction();

    actingAsSanctumAuthenticatedAdmin();
});

afterEach(fn () => DB::rollBack());

function conversacionAgente(): object
{
    return ConversationProxy::modelClass()::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '+5917'.random_int(1000000, 9999999),
        'wa_name'         => 'Contrato agente',
        'ai_enabled'      => true,
        'last_inbound_at' => now(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Lo que el agente RECIBE cuando el cliente toca una opción
|--------------------------------------------------------------------------
*/

it('entrega la opción tocada bajo la clave "selection", como imprimir', function () {
    $entrante = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionAgente()->id,
        'direction'       => 'inbound',
        'type'            => 'interactive',
        'body'            => 'Santa Cruz',
        'payload'         => [
            'type'        => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'ciudad_scz', 'title' => 'Santa Cruz']],
        ],
        'status' => 'delivered',
    ]);

    $evento = app(AgentPayload::class)->event($entrante);

    expect($evento['message']['selection'])->toBe(['id' => 'ciudad_scz', 'title' => 'Santa Cruz'])
        // El título también en `text`, que es lo que el cliente ve.
        ->and($evento['message']['text'])->toBe('Santa Cruz')
        // Y el tipo real, no normalizado: es lo que espera el mismo agente en imprimir.
        ->and($evento['message']['type'])->toBe('interactive');
});

it('un mensaje de texto trae selection en null', function () {
    $entrante = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionAgente()->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'Hola',
        'payload'         => ['type' => 'text'],
        'status'          => 'delivered',
    ]);

    expect(app(AgentPayload::class)->event($entrante)['message']['selection'])->toBeNull();
});

/*
 * La detección tiene que funcionar aunque el nodo NO traiga `type`. La versión
 * anterior leía interactive.type y lo usaba como nombre de la clave anidada;
 * imprimir busca las claves conocidas directamente, que es a prueba de eso.
 */
it('detecta la opción aunque el payload no traiga interactive.type', function () {
    $entrante = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionAgente()->id,
        'direction'       => 'inbound',
        'type'            => 'interactive',
        'body'            => 'Sí',
        'payload'         => [
            'type'        => 'interactive',
            'interactive' => ['button_reply' => ['id' => 'si', 'title' => 'Sí']],
        ],
        'status' => 'delivered',
    ]);

    expect(app(AgentPayload::class)->event($entrante)['message']['selection']['id'])->toBe('si');
});

/*
|--------------------------------------------------------------------------
| Lo que el agente ENVÍA: el endpoint estructurado
|--------------------------------------------------------------------------
*/

it('acepta botones en el formato estructurado de imprimir', function () {
    $c = conversacionAgente();

    $r = test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => '¿Confirmás el pedido?',
        'buttons' => [
            ['id' => 'si', 'title' => 'Sí'],
            ['id' => 'no', 'title' => 'No'],
        ],
    ])->assertOk();

    $m = MessageProxy::modelClass()::findOrFail($r->json('message.id'));

    expect($m->type)->toBe('interactive')
        ->and($m->payload['interactive']['kind'])->toBe('buttons')
        ->and($m->payload['interactive']['options'])->toBe([
            ['id' => 'si', 'title' => 'Sí'],
            ['id' => 'no', 'title' => 'No'],
        ]);
});

it('acepta listas con secciones y las aplana', function () {
    $c = conversacionAgente();

    $r = test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'      => 'list',
        'body'        => '¿Desde qué ciudad nos escribís?',
        'list_button' => 'Ver ciudades',
        'sections'    => [
            ['title' => 'Oriente', 'rows' => [['id' => 'scz', 'title' => 'Santa Cruz']]],
            ['title' => 'Valles', 'rows' => [['id' => 'cbba', 'title' => 'Cochabamba'], ['id' => 'tja', 'title' => 'Tarija']]],
        ],
    ])->assertOk();

    $i = MessageProxy::modelClass()::findOrFail($r->json('message.id'))->payload['interactive'];

    // El límite de Meta es sobre el TOTAL de filas, no por sección: aplanadas,
    // una sola validación lo cubre.
    expect($i['kind'])->toBe('list')
        ->and($i['options'])->toHaveCount(3)
        ->and($i['list_button'])->toBe('Ver ciudades');
});

it('el id que manda el agente se conserva tal cual', function () {
    $c = conversacionAgente();

    $r = test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => '¿Seguimos?',
        'buttons' => [['id' => 'ciudad:3', 'title' => 'Santa Cruz']],
    ])->assertOk();

    $i = MessageProxy::modelClass()::findOrFail($r->json('message.id'))->payload['interactive'];

    expect($i['options'][0]['id'])->toBe('ciudad:3');
});

it('rechaza un formato que no es button ni list', function () {
    $c = conversacionAgente();

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format' => 'carousel',
        'body'   => 'Hola',
    ])->assertStatus(422);
});

it('devuelve 422 con el límite violado cuando manda 4 botones', function () {
    $c = conversacionAgente();

    $botones = array_map(fn ($n) => ['id' => "b$n", 'title' => "Opción $n"], range(1, 4));

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => '¿Cuál?',
        'buttons' => $botones,
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'WhatsApp acepta hasta 3 botones; se intentó enviar 4.']);
});

it('devuelve 409 si la conversación fue derivada a un asesor', function () {
    $c = conversacionAgente();
    $c->forceFill(['handoff_state' => 'requested', 'handoff_requested_at' => now()])->save();

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => '¿Seguimos?',
        'buttons' => [['id' => 'si', 'title' => 'Sí']],
    ])->assertStatus(409);
});

it('devuelve 422 fuera de la ventana de 24 h', function () {
    $c = conversacionAgente();
    $c->forceFill(['last_inbound_at' => now()->subHours(30)])->save();

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => '¿Seguimos?',
        'buttons' => [['id' => 'si', 'title' => 'Sí']],
    ])->assertStatus(422);
});

/*
 * El marcador "1.-)" sigue funcionando: no exige nada del agente y ya mandó 105
 * mensajes en producción. Los dos caminos terminan en el mismo tipo y payload.
 */
it('el marcador de texto sigue conviviendo con el endpoint', function () {
    $c = conversacionAgente();

    $r = test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/messages", [
        'text' => "¿Desde qué ciudad?\n\n1.-) Santa Cruz\n2.-) Cochabamba",
    ])->assertOk();

    expect(MessageProxy::modelClass()::findOrFail($r->json('message.id'))->type)->toBe('interactive');
});
