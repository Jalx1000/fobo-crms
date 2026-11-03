<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\Bienvenida;
use Webkul\Whatsapp\Services\TemplateCatalog;
use Webkul\Whatsapp\Services\VideoDeBienvenida;

/**
 * El video de Sensia en el saludo: con la plantilla con video desde el Punto, como
 * mensaje común cuando el cliente escribe primero, sólo para los números de la lista
 * y una sola vez por conversación.
 */
uses(DatabaseTransactions::class);

const VIDEO_URL = 'https://sensia.test/whatsapp/bienvenida-sensia.mp4';

beforeEach(function () {
    Queue::fake();

    config([
        'whatsapp.bienvenida.enabled'        => true,
        'whatsapp.bienvenida.template'       => 'bienvenida_sensia',
        'whatsapp.bienvenida.video_template' => 'bienvenida_sensia_video',
        'whatsapp.bienvenida.language'       => 'es',
        'whatsapp.bienvenida.video'          => VIDEO_URL,
        'whatsapp.bienvenida.video_numeros'  => '59176616013',
    ]);
});

/** Finge el catálogo de Meta con las plantillas aprobadas que se pasen. */
function catalogoConPlantillas(array $aprobadas): void
{
    app()->instance(TemplateCatalog::class, new class($aprobadas) extends TemplateCatalog
    {
        public function __construct(protected array $aprobadas) {}

        public function find(string $name, string $language): ?array
        {
            if (! in_array($name, $this->aprobadas, true)) {
                return null;
            }

            return [
                'name'      => $name,
                'language'  => $language,
                'body'      => 'Hola {{1}}! Bienvenido a Sensia.',
                'variables' => 1,
                'header'    => str_ends_with($name, '_video') ? ['format' => 'VIDEO', 'media' => true] : null,
            ];
        }

        public function render(string $body, array $variables): string
        {
            return str_replace('{{1}}', $variables[0] ?? '', $body);
        }
    });
}

function ultimoMensaje(): object
{
    return DB::table('whatsapp_messages')->orderByDesc('id')->first();
}

const SALUDO = "¡Hola! 👋 Gracias por escribir a Sensia 💧. Soy Sofía, tu asistente.\nContame, ¿en qué zona te encontrás?\n\n1.-) San Salvador de Jujuy\n2.-) Barrio Alto Comedero\n3.-) Palpalá\n4.-) Otra zona";

/** Una conversación con la ventana abierta y el agente prendido; número al azar en la lista. */
function conversacionConCliente(?string $telefono = null): object
{
    $telefono ??= '549388'.random_int(1000000, 9999999);
    config(['whatsapp.bienvenida.video_numeros' => $telefono]);

    return ConversationProxy::modelClass()::create([
        'gateway' => 'cloud_api', 'wa_phone' => $telefono, 'wa_name' => 'Prueba',
        'ai_enabled' => true, 'last_inbound_at' => now(), 'last_message_at' => now(),
    ]);
}

function videoDe(): VideoDeBienvenida
{
    return app(VideoDeBienvenida::class);
}

function videosEnviadosA(string $telefono): int
{
    return DB::table('whatsapp_messages')
        ->join('whatsapp_conversations', 'whatsapp_conversations.id', '=', 'whatsapp_messages.conversation_id')
        ->where('whatsapp_conversations.wa_phone', 'like', '%'.substr($telefono, -8))
        ->where('whatsapp_messages.type', 'video')
        ->count();
}

// ───────────────────────── desde el Punto ─────────────────────────

it('a un número de la lista le manda la plantilla con el video', function () {
    catalogoConPlantillas(['bienvenida_sensia', 'bienvenida_sensia_video']);

    expect(app(Bienvenida::class)->enviarA('76616013', 'Juan'))->toBeTrue();

    $payload = json_decode(ultimoMensaje()->payload, true);

    expect($payload['template'])->toBe('bienvenida_sensia_video')
        // toEqual y no toBe: la columna JSON de MySQL reordena las claves.
        ->and($payload['header'])->toEqual(['format' => 'VIDEO', 'link' => VIDEO_URL]);
});

it('si la plantilla con video no está aprobada, sale la de siempre', function () {
    catalogoConPlantillas(['bienvenida_sensia']);

    expect(app(Bienvenida::class)->enviarA('76616013', 'Juan'))->toBeTrue();

    $payload = json_decode(ultimoMensaje()->payload, true);

    expect($payload['template'])->toBe('bienvenida_sensia')
        ->and($payload)->not->toHaveKey('header');
});

it('a un número fuera de la lista le manda la de siempre', function () {
    catalogoConPlantillas(['bienvenida_sensia', 'bienvenida_sensia_video']);

    expect(app(Bienvenida::class)->enviarA('549388'.random_int(1000000, 9999999), 'Juan'))->toBeTrue();

    expect(json_decode(ultimoMensaje()->payload, true)['template'])->toBe('bienvenida_sensia');
});

it('con * se lo manda a cualquiera', function () {
    config(['whatsapp.bienvenida.video_numeros' => '*']);
    catalogoConPlantillas(['bienvenida_sensia', 'bienvenida_sensia_video']);

    app(Bienvenida::class)->enviarA('549388'.random_int(1000000, 9999999), 'Juan');

    expect(json_decode(ultimoMensaje()->payload, true)['template'])->toBe('bienvenida_sensia_video');
});

// ─────────────────── el agente saluda (el cliente escribió primero) ───────────────────

it('reconoce el saludo de Sofía, con o sin tildes, y nada más', function () {
    expect(videoDe()->esSaludo(SALUDO))->toBeTrue()
        ->and(videoDe()->esSaludo('¡Hola, Juan! 👋 Gracias por escribir a Sensia 💧. Soy Sofía, tu asistente.'))->toBeTrue()
        ->and(videoDe()->esSaludo('hola! soy sofia,  tu asistente'))->toBeTrue()
        // La despedida también dice "Gracias por escribir a Sensia" y no es el saludo.
        ->and(videoDe()->esSaludo('Tu pedido fue cancelado. 👍 Gracias por escribir a Sensia.'))->toBeFalse()
        ->and(videoDe()->esSaludo(null))->toBeFalse();
});

it('con el saludo del agente manda el video, antes del saludo', function () {
    actingAsSanctumAuthenticatedAdmin();
    $conv = conversacionConCliente();

    test()->call('POST', "/api/v1/whatsapp/conversations/{$conv->id}/messages", ['text' => SALUDO])->assertOk();

    [$video, $saludo] = $conv->messages()->orderBy('id')->get()->all();

    expect($video->type)->toBe('video')
        ->and($video->sender)->toBe('sistema')
        ->and($video->payload['media']['url'])->toBe(VIDEO_URL)
        ->and($saludo->sender)->toBe('ia')
        ->and($saludo->body)->toContain('Soy Sofía');

    Queue::assertPushed(SendMessage::class, fn ($job) => $job->messageId === $video->id);
});

it('con cualquier otra respuesta del agente no manda el video', function () {
    actingAsSanctumAuthenticatedAdmin();
    $conv = conversacionConCliente();

    test()->call('POST', "/api/v1/whatsapp/conversations/{$conv->id}/messages", ['text' => '¿Cuántos bidones necesitás?'])->assertOk();

    expect(videosEnviadosA($conv->wa_phone))->toBe(0);
});

it('si el agente saluda dos veces en el día, el video sale una sola vez', function () {
    $conv = conversacionConCliente();

    expect(videoDe()->enviarConSaludo($conv, SALUDO))->toBeTrue()
        ->and(videoDe()->enviarConSaludo($conv, SALUDO))->toBeFalse()
        ->and(videosEnviadosA($conv->wa_phone))->toBe(1);
});

it('en una conversación vieja que vuelve, el saludo trae el video otra vez', function () {
    $conv = conversacionConCliente();
    videoDe()->enviarConSaludo($conv, SALUDO);

    // El video anterior es de hace dos días.
    DB::table('whatsapp_messages')->where('conversation_id', $conv->id)->update(['created_at' => now()->subDays(2)]);

    expect(videoDe()->enviarConSaludo($conv, SALUDO))->toBeTrue()
        ->and(videosEnviadosA($conv->wa_phone))->toBe(2);
});

it('aunque el cliente ya tenga pedidos, el saludo trae el video', function () {
    // Un cliente con lead: lo que antes lo dejaba sin video.
    $personaConPedido = DB::table('leads')->whereNotNull('person_id')->value('person_id');
    $conv = conversacionConCliente();
    $conv->forceFill(['person_id' => $personaConPedido])->save();

    expect(videoDe()->enviarConSaludo($conv, SALUDO))->toBeTrue();
});

it('a un número fuera de la lista el saludo no trae video', function () {
    $conv = conversacionConCliente();
    config(['whatsapp.bienvenida.video_numeros' => '59100000000']);

    expect(videoDe()->enviarConSaludo($conv, SALUDO))->toBeFalse();
});

it('cuando el cliente escribe ya no sale el video solo', function () {
    $conv = conversacionConCliente();

    $mensaje = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'type' => 'text',
        'body' => 'Hola', 'wa_message_id' => 'wamid.test.'.uniqid(), 'status' => 'delivered', 'sender' => 'contact',
    ]);
    Event::dispatch('whatsapp.message.received', $mensaje);

    expect(videosEnviadosA($conv->wa_phone))->toBe(0);
});
