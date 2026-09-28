<?php

use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;

/**
 * Botones y listas interactivas.
 *
 * Los límites de Meta se validan en el DTO, no en el gateway, y por una razón
 * concreta: Meta rechaza un interactivo pasado de largo con un 400 genérico que
 * no dice qué campo fue. Estos tests fijan que el error nombre el campo.
 */
function botones(array $extra = []): OutboundInteractive
{
    return OutboundInteractive::fromArray(array_merge([
        'format'  => 'button',
        'body'    => '¿Qué querés ver?',
        'buttons' => [
            ['id' => 'fotos', 'title' => 'Ver fotos'],
            ['id' => 'ficha', 'title' => 'Ficha técnica'],
        ],
    ], $extra));
}

function lista(array $filas, array $extra = []): OutboundInteractive
{
    return OutboundInteractive::fromArray(array_merge([
        'format'      => 'list',
        'body'        => 'Elegí un producto',
        'list_button' => 'Ver catálogo',
        'sections'    => [['title' => 'Productos', 'rows' => $filas]],
    ], $extra));
}

it('acepta un mensaje de botones bien formado', function () {
    expect(botones()->problema())->toBeNull();
});

it('acepta una lista bien formada', function () {
    expect(lista([['id' => 'CM_00015', 'title' => 'Polipasacalle']])->problema())->toBeNull();
});

it('rechaza más de 3 botones y sugiere la lista', function () {
    // Es el error más probable: el agente tiene 5 productos y quiere un botón
    // para cada uno. El mensaje tiene que decirle qué hacer, no solo que no.
    $problema = botones(['buttons' => array_map(
        fn ($i) => ['id' => "op{$i}", 'title' => "Opción {$i}"],
        range(1, 4),
    )])->problema();

    expect($problema)->toContain('máximo 3 botones')
        ->and($problema)->toContain('usá una lista');
});

it('rechaza un título de botón de más de 20 caracteres nombrando el botón', function () {
    $problema = botones(['buttons' => [
        ['id' => 'ok', 'title' => 'Sí'],
        ['id' => 'largo', 'title' => 'Este título tiene muchísimos caracteres'],
    ]])->problema();

    expect($problema)->toContain('botón 2')
        ->and($problema)->toContain('20 caracteres');
});

it('rechaza ids repetidos', function () {
    /*
     * Meta los acepta y después manda la misma respuesta para dos botones
     * distintos: la conversación se rompe recién cuando el cliente elige.
     */
    $problema = botones(['buttons' => [
        ['id' => 'x', 'title' => 'Uno'],
        ['id' => 'x', 'title' => 'Dos'],
    ]])->problema();

    expect($problema)->toContain("id 'x' está repetido");
});

it('rechaza una opción sin id explicando para qué sirve', function () {
    $problema = botones(['buttons' => [['id' => '', 'title' => 'Sin id']]])->problema();

    expect($problema)->toContain('no tiene id')
        ->and($problema)->toContain('vuelve cuando el cliente elige');
});

it('cuenta el tope de 10 filas sobre el TOTAL, no por sección', function () {
    // Dos secciones de 6 son 12: pasa el tope aunque ninguna sección sola lo haga.
    $seccion = fn ($p) => [
        'title' => "Grupo {$p}",
        'rows'  => array_map(fn ($i) => ['id' => "{$p}{$i}", 'title' => "Item {$p}{$i}"], range(1, 6)),
    ];

    $problema = OutboundInteractive::fromArray([
        'format'      => 'list',
        'body'        => 'Elegí',
        'list_button' => 'Ver',
        'sections'    => [$seccion('a'), $seccion('b')],
    ])->problema();

    expect($problema)->toContain('máximo 10 opciones en total');
});

it('rechaza un cuerpo vacío y uno de más de 1024 caracteres', function () {
    expect(botones(['body' => '   '])->problema())->toContain('no puede estar vacío')
        ->and(botones(['body' => str_repeat('a', 1025)])->problema())->toContain('cuerpo supera los 1024');
});

it('rechaza una lista sin el texto del botón que la abre', function () {
    $problema = lista([['id' => 'a', 'title' => 'A']], ['list_button' => ''])->problema();

    expect($problema)->toContain('texto del botón');
});

it('arma el nodo de botones como lo espera la Cloud API', function () {
    $nodo = botones(['header' => 'Polipasacalle', 'footer' => 'Imprimir'])->toNode();

    expect($nodo['type'])->toBe('button')
        ->and($nodo['header'])->toBe(['type' => 'text', 'text' => 'Polipasacalle'])
        ->and($nodo['body'])->toBe(['text' => '¿Qué querés ver?'])
        ->and($nodo['footer'])->toBe(['text' => 'Imprimir'])
        ->and($nodo['action']['buttons'][0])->toBe([
            'type'  => 'reply',
            'reply' => ['id' => 'fotos', 'title' => 'Ver fotos'],
        ]);
});

it('omite header y footer cuando no se mandan', function () {
    // Meta rechaza un header vacío; no mandarlo es distinto de mandarlo en blanco.
    $nodo = botones()->toNode();

    expect($nodo)->not->toHaveKey('header')
        ->and($nodo)->not->toHaveKey('footer');
});

it('arma el nodo de lista con secciones y descripciones', function () {
    $nodo = lista([
        ['id' => 'CM_00015', 'title' => 'Polipasacalle', 'description' => 'Material POP para exteriores'],
        ['id' => 'CM_00013', 'title' => 'Bolsa camiseta'],
    ])->toNode();

    expect($nodo['type'])->toBe('list')
        ->and($nodo['action']['button'])->toBe('Ver catálogo')
        ->and($nodo['action']['sections'][0]['title'])->toBe('Productos')
        ->and($nodo['action']['sections'][0]['rows'][0])->toBe([
            'id'          => 'CM_00015',
            'title'       => 'Polipasacalle',
            'description' => 'Material POP para exteriores',
        ])
        // Sin descripción, la clave no viaja: Meta rechaza el string vacío.
        ->and($nodo['action']['sections'][0]['rows'][1])->not->toHaveKey('description');
});

it('el label del hilo no queda en blanco', function () {
    // Un interactivo no tiene texto plano: sin label, la lista de
    // conversaciones mostraría un renglón vacío donde hubo un menú.
    expect(botones()->label())->toContain('¿Qué querés ver?')
        ->and(botones()->label())->toContain('2 botón(es)')
        ->and(lista([['id' => 'a', 'title' => 'A']])->label())->toContain('1 opción(es)');
});

it('sobrevive el ida y vuelta por la columna payload', function () {
    // El DTO se reconstruye en el worker desde el JSON guardado: si toArray y
    // fromArray no son simétricos, el mensaje sale distinto de como se encoló.
    $original = botones(['header' => 'Hola', 'footer' => 'Chau']);

    $reconstruido = OutboundInteractive::fromArray($original->toArray());

    expect($reconstruido->toNode())->toBe($original->toNode());
});

/*
|------------------------------------------------------------------------------
| El endpoint del agente y el camino de vuelta
|------------------------------------------------------------------------------
*/

function conversacionInteractiva(array $extra = []): Webkul\Whatsapp\Models\Conversation
{
    return Webkul\Whatsapp\Models\Conversation::create(array_merge([
        'wa_id'           => '591'.random_int(60000000, 79999999),
        'wa_phone'        => '591'.random_int(60000000, 79999999),
        'gateway'         => 'cloud_api',
        'ai_enabled'      => true,
        'handoff_state'   => null,
        'last_inbound_at' => now(),
        'last_message_at' => now(),
    ], $extra));
}

it('encola un mensaje de botones con sender=ia', function () {
    Illuminate\Support\Facades\Queue::fake();

    actingAsSanctumAuthenticatedAdmin();

    $c = conversacionInteractiva();

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => '¿Qué querés ver del Polipasacalle?',
        'buttons' => [
            ['id' => 'fotos:CM_00015', 'title' => 'Ver fotos'],
            ['id' => 'precio:CM_00015', 'title' => 'Precio'],
        ],
    ])->assertOk()->assertJsonPath('message.sender', 'ia');

    $m = Webkul\Whatsapp\Models\Message::where('conversation_id', $c->id)->first();

    expect($m->type)->toBe('interactive')
        ->and($m->status)->toBe('queued')
        // El label evita el renglón en blanco en la lista de conversaciones.
        ->and($m->body)->toContain('¿Qué querés ver del Polipasacalle?')
        // El DTO viaja completo: el worker lo reconstruye desde acá.
        ->and($m->payload['interactive']['buttons'][0]['id'])->toBe('fotos:CM_00015');

    $m->delete();
    $c->delete();
});

it('rechaza con 422 y mensaje accionable antes de encolar nada', function () {
    /*
     * Lo importante es el "antes de encolar": si esto llegara a Meta, el 400
     * genérico aparecería en el worker segundos después de que el agente ya le
     * dijo al cliente "elegí una opción".
     */
    Illuminate\Support\Facades\Queue::fake();

    actingAsSanctumAuthenticatedAdmin();

    $c = conversacionInteractiva();

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => 'Elegí',
        'buttons' => array_map(fn ($i) => ['id' => "o{$i}", 'title' => "Opción {$i}"], range(1, 4)),
    ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'máximo 3 botones'));

    expect(Webkul\Whatsapp\Models\Message::where('conversation_id', $c->id)->count())->toBe(0);

    $c->delete();
});

it('rechaza con 409 si la conversación fue derivada', function () {
    actingAsSanctumAuthenticatedAdmin();

    $c = conversacionInteractiva(['ai_enabled' => false]);

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format'  => 'button',
        'body'    => 'Elegí',
        'buttons' => [['id' => 'a', 'title' => 'A']],
    ])->assertStatus(409);

    $c->delete();
});

it('exige list_button y sections cuando el formato es lista', function () {
    actingAsSanctumAuthenticatedAdmin();

    $c = conversacionInteractiva();

    test()->postJson("/api/v1/whatsapp/conversations/{$c->id}/interactive", [
        'format' => 'list',
        'body'   => 'Elegí',
    ])->assertStatus(422)->assertJsonValidationErrors(['list_button', 'sections']);

    $c->delete();
});

it('el gateway declara interactive en send y en receive', function () {
    /*
     * La Capabilities es lo que enciende controles del inbox y lo que valida el
     * endpoint. Declararla antes de que el driver funcionara ya dejó una vez un
     * botón conectado a un mock, que decía "enviado" sin enviar nada.
     */
    $caps = app(Webkul\Whatsapp\Gateways\GatewayManager::class)
        ->driver('cloud_api')
        ->capabilities();

    expect($caps->canSend('interactive'))->toBeTrue()
        ->and($caps->receive)->toContain('interactive');
});

it('la respuesta del cliente NO llega en blanco', function () {
    /*
     * El modo de falla que hace inútil toda la función: mandar botones y que la
     * elección vuelva con body null. El hilo mostraría un renglón vacío y el
     * agente recibiría text=null justo cuando el cliente acaba de responderle.
     */
    $gateway = app(Webkul\Whatsapp\Gateways\GatewayManager::class)->driver('cloud_api');

    $lote = $gateway->parseWebhook([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Ana']]],
                    'messages' => [[
                        'id'          => 'wamid.TEST',
                        'from'        => '59171234567',
                        'type'        => 'interactive',
                        'interactive' => [
                            'type'         => 'button_reply',
                            'button_reply' => ['id' => 'fotos:CM_00015', 'title' => 'Ver fotos'],
                        ],
                    ]],
                ],
            ]],
        ]],
    ]);

    expect($lote->messages)->toHaveCount(1);

    $m = $lote->messages[0];

    expect($m->type)->toBe('interactive')
        ->and($m->body)->toBe('Ver fotos')
        // El id sobrevive en raw, que es lo que se guarda en payload.
        ->and($m->raw['interactive']['button_reply']['id'])->toBe('fotos:CM_00015');
});
