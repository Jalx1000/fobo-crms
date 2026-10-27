<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\Bienvenida;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\TemplateCatalog;

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

/** Un mensaje del cliente, como lo deja IngestInboundPayload. */
function clienteEscribe(string $telefono): void
{
    $conversacion = app(ConversationResolver::class)->findOrCreate($telefono, 'Prueba');

    $mensaje = MessageProxy::modelClass()::create([
        'conversation_id' => $conversacion->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'Hola',
        'wa_message_id'   => 'wamid.test.'.uniqid(),
        'status'          => 'delivered',
        'sender'          => 'contact',
    ]);

    Event::dispatch('whatsapp.message.received', $mensaje);
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

// ─────────────────────── el cliente escribe primero ───────────────────────

it('cuando escribe un número de la lista le manda el video una sola vez', function () {
    $telefono = '59176616013';
    DB::table('whatsapp_messages')->where('type', 'video')->where('media_path', VIDEO_URL)->delete();

    clienteEscribe($telefono);

    $video = ultimoMensaje();

    expect($video->type)->toBe('video')
        ->and($video->direction)->toBe('outbound')
        ->and($video->sender)->toBe('sistema')
        ->and(json_decode($video->payload, true)['media']['url'])->toBe(VIDEO_URL);

    Queue::assertPushed(SendMessage::class, fn ($job) => $job->messageId === $video->id);

    clienteEscribe($telefono);

    expect(videosEnviadosA($telefono))->toBe(1);
});

it('cuando escribe un número fuera de la lista no manda nada', function () {
    $telefono = '549388'.random_int(1000000, 9999999);

    clienteEscribe($telefono);

    expect(videosEnviadosA($telefono))->toBe(0);
});
