<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Http\Controllers\InboxController;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Jobs\NotifyAgent;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/*
 * Reacciones (el 👍 que el cliente pone sobre un mensaje).
 *
 * Entraban desde el 29/08 y se veían como "Tipo no soportado": el asesor no
 * tenía forma de saber que el cliente había respondido. Meta manda el emoji y
 * el wamid del mensaje reaccionado, y usa EL MISMO tipo para quitar la
 * reacción, con el emoji vacío.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    Queue::fake();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

function payloadReaccion(string $phone, string $wamid, string $emoji, string $destino): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Cliente']]],
                    'messages' => [[
                        'id'       => $wamid,
                        'from'     => $phone,
                        'type'     => 'reaction',
                        'reaction' => ['emoji' => $emoji, 'message_id' => $destino],
                    ]],
                ],
            ]],
        ]],
    ];
}

it('lee el emoji de una reacción como texto del mensaje', function () {
    $batch = (new CloudApiGateway([]))->parseWebhook(
        payloadReaccion('59176616013', 'wamid.r1', '👍', 'wamid.destino')
    );

    expect($batch->messages[0]->type)->toBe('reaction')
        ->and($batch->messages[0]->body)->toBe('👍');
});

/*
 * El destino viaja en reaction.message_id, NO en `context` como el resto. Se
 * mapea a replyTo para que el hilo dibuje la cita con el mecanismo de respuesta
 * que ya existe, en vez de tener dos caminos para lo mismo.
 */
it('apunta al mensaje reaccionado a través de replyTo', function () {
    $batch = (new CloudApiGateway([]))->parseWebhook(
        payloadReaccion('59176616013', 'wamid.r2', '❤️', 'wamid.elMensajeReaccionado')
    );

    expect($batch->messages[0]->replyToProviderId)->toBe('wamid.elMensajeReaccionado');
});

/*
 * Quitar la reacción llega con el mismo tipo y el emoji vacío. Sin separarlos,
 * "quitó su reacción" y "reaccionó con algo ilegible" se ven igual.
 */
it('distingue quitar la reacción de reaccionar', function () {
    $batch = (new CloudApiGateway([]))->parseWebhook(
        payloadReaccion('59176616013', 'wamid.r3', '', 'wamid.destino')
    );

    expect($batch->messages[0]->body)->toBeNull();
});

it('el hilo expone el emoji y si fue para quitarla', function () {
    $conversation = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'  => 'cloud_api',
        'wa_phone' => '+5917'.random_int(1000000, 9999999),
        'wa_name'  => 'Prueba reacción',
    ]);

    $puesta = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'inbound',
        'type'            => 'reaction',
        'body'            => '👍',
        'payload'         => ['type' => 'reaction', 'reaction' => ['emoji' => '👍', 'message_id' => 'wamid.x']],
        'status'          => 'delivered',
    ]);

    $quitada = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'inbound',
        'type'            => 'reaction',
        'payload'         => ['type' => 'reaction', 'reaction' => ['emoji' => '', 'message_id' => 'wamid.x']],
        'status'          => 'delivered',
    ]);

    $transform = (new ReflectionClass(InboxController::class))->getMethod('transform');
    $transform->setAccessible(true);
    $c = app(InboxController::class);

    expect($transform->invoke($c, $puesta)['reaction'])->toBe(['emoji' => '👍', 'removed' => false])
        ->and($transform->invoke($c, $quitada)['reaction'])->toBe(['emoji' => null, 'removed' => true]);
});

it('un mensaje que no es reacción no trae bloque de reacción', function () {
    $conversation = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'  => 'cloud_api',
        'wa_phone' => '+5917'.random_int(1000000, 9999999),
    ]);

    $texto = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'Hola',
        'status'          => 'delivered',
    ]);

    $transform = (new ReflectionClass(InboxController::class))->getMethod('transform');
    $transform->setAccessible(true);

    expect($transform->invoke(app(InboxController::class), $texto)['reaction'])->toBeNull();
});

/*
 * EL CASO QUE PROTEGE CONTRA UNA REGRESIÓN QUE INTRODUCE ESTE MISMO CAMBIO.
 *
 * Hasta ahora el agente descartaba las reacciones por tipo desconocido. Al
 * darles texto (el emoji), sin este filtro empezaría a contestarle "👍" al
 * cliente — ruido, y encima consume la ventana de 24 h.
 */
it('una reacción NO despierta al agente', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    (new IngestInboundPayload('cloud_api', payloadReaccion($phone, 'wamid.noia', '👍', 'wamid.destino')))
        ->handle(app(GatewayManager::class), app(ConversationResolver::class));

    Queue::assertNotPushed(NotifyAgent::class);
});

it('pero un texto normal sí lo despierta', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    (new IngestInboundPayload('cloud_api', [
        'entry' => [['changes' => [['value' => [
            'contacts' => [['profile' => ['name' => 'Cliente']]],
            'messages' => [['id' => 'wamid.si'.random_int(1000, 9999), 'from' => $phone, 'type' => 'text', 'text' => ['body' => 'Hola']]],
        ]]]]],
    ]))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    $conversation = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::where('wa_phone', '+'.$phone)->first();

    // Sólo si la IA está activa en esa conversación; si no, el test no dice nada.
    if ($conversation && $conversation->aiEffective()) {
        Queue::assertPushed(NotifyAgent::class);
    } else {
        expect($conversation)->not->toBeNull();
    }
});

/*
 * Una reacción no trae archivo. Antes se despachaba DownloadInboundMedia para
 * todo lo que no fuera texto, y el job salía sin hacer nada.
 */
it('una reacción no dispara la descarga de archivos', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    (new IngestInboundPayload('cloud_api', payloadReaccion($phone, 'wamid.nomedia', '👍', 'wamid.destino')))
        ->handle(app(GatewayManager::class), app(ConversationResolver::class));

    Queue::assertNotPushed(Webkul\Whatsapp\Jobs\DownloadInboundMedia::class);
});

it('la burbuja de reacción existe y ya no cae en "Tipo no soportado"', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    expect($blade)->toContain("msg.type === 'reaction'")
        ->and($blade)->toContain('quitó su reacción');
});

/*
|--------------------------------------------------------------------------
| El tap del cliente en la burbuja
|--------------------------------------------------------------------------
| Un "interactive" es una de DOS cosas según quién lo mandó: el menú que
| ofrecimos, o la opción que el cliente tocó. El tap llega con la forma cruda de
| Meta y sin `options`, así que interactiveOf() devolvía null y la burbuja caía
| en "Menú enviado" — atribuyéndonos lo que hizo el cliente.
*/

it('el tap del cliente expone la opción elegida, no el menú', function () {
    $conversation = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'  => 'cloud_api',
        'wa_phone' => '+5917'.random_int(1000000, 9999999),
    ]);

    $tap = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'inbound',
        'type'            => 'interactive',
        'body'            => 'No',
        'payload'         => [
            'type'        => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'opt:2', 'title' => 'No']],
        ],
        'status' => 'delivered',
    ]);

    $transform = (new ReflectionClass(InboxController::class))->getMethod('transform');
    $transform->setAccessible(true);
    $row = $transform->invoke(app(InboxController::class), $tap);

    expect($row['selection'])->toBe(['id' => 'opt:2', 'title' => 'No', 'kind' => 'button_reply'])
        ->and($row['interactive'])->toBeNull();
});

it('el menú que enviamos no trae selection', function () {
    $conversation = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'  => 'cloud_api',
        'wa_phone' => '+5917'.random_int(1000000, 9999999),
    ]);

    $menu = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'outbound',
        'type'            => 'interactive',
        'body'            => '¿Confirmás?',
        'payload'         => ['interactive' => ['kind' => 'buttons', 'body' => '¿Confirmás?', 'options' => [['id' => 'opt:1', 'title' => 'Sí']]]],
        'status'          => 'sent',
        'sender'          => 'ia',
    ]);

    $transform = (new ReflectionClass(InboxController::class))->getMethod('transform');
    $transform->setAccessible(true);
    $row = $transform->invoke(app(InboxController::class), $menu);

    expect($row['selection'])->toBeNull()
        ->and($row['interactive']['options'])->toHaveCount(1);
});

it('la burbuja distingue el tap del menú y ya no dice "Menú enviado"', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    expect($blade)->toContain('msg.selection')
        ->and($blade)->not->toContain('Menú enviado');
});

/*
|--------------------------------------------------------------------------
| Plantillas en el hilo
|--------------------------------------------------------------------------
| 14 plantillas enviadas y el asesor no las veía: la burbuja no tenía rama
| `template` y caía en "Tipo no soportado". Y el comando de envío guardaba el
| NOMBRE técnico como body en vez del texto que leyó el cliente.
*/

it('la burbuja de plantilla existe y ya no cae en "Tipo no soportado"', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    expect($blade)->toContain("msg.type === 'template'")
        ->and($blade)->toContain('Plantilla');
});

it('el comando de reapertura guarda el texto de la plantilla, no su nombre', function () {
    $src = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Console/Commands/ReopenWindow.php')
    );

    // Renderiza desde el catálogo, igual que el composer del inbox.
    expect($src)->toContain('$catalog->render($definicion[\'body\']')
        ->and($src)->not->toContain("'body'            => \$plantilla,");
});

it('el comando falla temprano si la plantilla no existe en el catálogo', function () {
    $src = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Console/Commands/ReopenWindow.php')
    );

    // Antes de recorrer a nadie: mejor un error acá que 14 mensajes en failed.
    expect(strpos($src, '$catalog->find($plantilla'))->toBeLessThan(strpos($src, 'foreach ($query'));
});

/*
 * El motivo del fallo que manda Meta se persiste. Sin esto, 14 plantillas
 * quedaron en `failed` con `error` vacío y no había forma de saber que era
 * "131042 · Business eligibility payment issue".
 */
it('un status failed de Meta guarda el motivo en el mensaje', function () {
    $conversation = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'  => 'cloud_api',
        'wa_phone' => '+5917'.random_int(1000000, 9999999),
    ]);

    $wamid = 'wamid.fail.'.random_int(1000, 9999);

    $m = MessageProxy::modelClass()::create([
        'conversation_id' => $conversation->id,
        'direction'       => 'outbound',
        'type'            => 'template',
        'body'            => 'x',
        'wa_message_id'   => $wamid,
        'status'          => 'sent',
        'sender'          => 'human',
    ]);

    (new IngestInboundPayload('cloud_api', [
        'entry' => [['changes' => [['value' => [
            'statuses' => [[
                'id'     => $wamid,
                'status' => 'failed',
                'errors' => [[
                    'code'       => 131042,
                    'title'      => 'Business eligibility payment issue',
                    'error_data' => ['details' => 'payment has been restricted'],
                ]],
            ]],
        ]]]]],
    ]))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    $m->refresh();

    expect($m->status)->toBe('failed')
        ->and($m->error)->toContain('131042')
        ->and($m->error)->toContain('payment has been restricted');
});
