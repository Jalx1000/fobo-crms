<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Webkul\Whatsapp\Jobs\NotifyAgent;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * CRM-2: varios mensajes seguidos del cliente, un solo aviso al agente.
 *
 * Auditoría del 12/10: 156 ráfagas, y el agente contestaba cada mensaje por
 * separado. En la 3686 respondió «Buenas tardes» con la presentación de Magia
 * Verde e ignoró la pregunta que llegó dos segundos después. Ahora el aviso sale
 * con unos segundos de espera; si mientras tanto llega otro mensaje, ese aviso
 * se descarta y el del último lleva todos los mensajes en `parts`.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    config([
        'whatsapp.ai.webhook_url'      => 'https://agente.test/webhook',
        'whatsapp.ai.token'            => 'x',
        'whatsapp.ai.debounce_seconds' => 8,
    ]);

    Http::fake(['agente.test/*' => Http::response(['ok' => true])]);

    $this->conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Ráfaga',
        'ai_enabled' => true, 'status' => 'open', 'last_inbound_at' => now(),
    ]);
});

function rafMensaje($conv, string $texto, string $direccion = 'inbound'): Message
{
    return Message::create([
        'conversation_id' => $conv->id, 'direction' => $direccion, 'type' => 'text',
        'sender'          => $direccion === 'inbound' ? 'contact' : 'ia',
        'status'          => 'delivered', 'body' => $texto,
    ]);
}

function rafCorrer(Message $m): void
{
    (new NotifyAgent($m->id))->handle(app(AgentPayload::class));
}

it('tres mensajes seguidos generan un solo aviso, con los tres', function () {
    $a = rafMensaje($this->conv, 'Buenas tardes');
    $b = rafMensaje($this->conv, 'Quisiera saber si tienen bolsas');
    $c = rafMensaje($this->conv, 'para envasar al vacío');

    rafCorrer($a);
    rafCorrer($b);
    rafCorrer($c);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($a, $b, $c) {
        $msg = $request['message'];

        return $msg['id'] === $c->id
            && array_column($msg['parts'], 'id') === [$a->id, $b->id, $c->id]
            && $msg['text'] === "Buenas tardes\nQuisiera saber si tienen bolsas\npara envasar al vacío";
    });
});

it('las partes empiezan después de la última respuesta', function () {
    rafMensaje($this->conv, 'Hola');
    rafMensaje($this->conv, '¡Hola! ¿Qué producto le interesa?', 'outbound');
    $nuevo = rafMensaje($this->conv, 'Stretch film');

    rafCorrer($nuevo);

    Http::assertSent(fn ($r) => array_column($r['message']['parts'], 'id') === [$nuevo->id]
        && $r['message']['text'] === 'Stretch film');
});

it('un mensaje aislado se avisa igual que siempre', function () {
    $m = rafMensaje($this->conv, 'Hola');

    rafCorrer($m);

    Http::assertSentCount(1);
});

it('sin espera configurada, cada mensaje se avisa como antes', function () {
    config(['whatsapp.ai.debounce_seconds' => 0]);

    $a = rafMensaje($this->conv, 'Uno');
    $b = rafMensaje($this->conv, 'Dos');

    rafCorrer($a);
    rafCorrer($b);

    Http::assertSentCount(2);
});

it('el aviso se encola con la espera configurada', function () {
    Illuminate\Support\Facades\Queue::fake();

    NotifyAgent::despachar(rafMensaje($this->conv, 'Hola')->id);

    Illuminate\Support\Facades\Queue::assertPushed(NotifyAgent::class, fn ($job) => $job->delay !== null);
});
