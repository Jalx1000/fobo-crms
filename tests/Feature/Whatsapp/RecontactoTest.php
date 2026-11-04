<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\Recontactar;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

/**
 * Recontacto 6 horas antes de que cierre la ventana de 24 h.
 *
 * Si el cliente no contestó nuestro último mensaje -del agente o de un asesor,
 * por ejemplo la cotización-, a las 18 h de su último mensaje se le escribe una
 * vez para que responda mientras todavía se le puede escribir sin plantilla.
 * En horario (lun–sáb 08–19): si las 18 h caen de noche, se corre a la
 * apertura siempre que la ventana siga abierta.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();

    config([
        'whatsapp.recontacto.enabled' => true,
        'whatsapp.recontacto.horas_antes' => 6,
        'whatsapp.handoff.horario_inicio' => '08:00',
        'whatsapp.handoff.horario_fin' => '19:00',
        'whatsapp.handoff.horario_dias' => [1, 2, 3, 4, 5, 6],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function rcHora(string $fecha): Carbon
{
    $hora = Carbon::parse($fecha, config('app.timezone'));
    Carbon::setTestNow($hora);

    return $hora;
}

function rcConversacion(string $ultimoDelCliente): Conversation
{
    $conv = Conversation::create([
        'gateway' => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Ana Pérez',
        'ai_enabled' => true, 'status' => 'open',
        'last_inbound_at' => Carbon::parse($ultimoDelCliente, config('app.timezone')),
    ]);

    rcMensaje($conv, 'inbound', 'Hola, quiero cotizar', $ultimoDelCliente);

    return $conv;
}

function rcMensaje(Conversation $conv, string $dir, string $texto, string $cuando, string $sender = 'ia', array $payload = []): Message
{
    $m = Message::create([
        'conversation_id' => $conv->id, 'direction' => $dir, 'type' => 'text',
        'sender' => $dir === 'inbound' ? 'contact' : $sender, 'status' => 'sent', 'body' => $texto,
        'payload' => $payload ?: null,
    ]);
    $m->forceFill(['created_at' => Carbon::parse($cuando, config('app.timezone'))])->save();

    return $m;
}

function rcCorrer(Conversation $conv): void
{
    (new Recontactar($conv->id, $conv->last_inbound_at->toIso8601String()))->handle();
}

function rcEnviados(Conversation $conv)
{
    return Message::where('conversation_id', $conv->id)->where('payload->recontacto', true)->get();
}

it('el recontacto se agenda a las 18 h del último mensaje del cliente', function () {
    rcHora('2026-10-13 14:00:00'); // martes
    Recontactar::agendar(rcConversacion('2026-10-13 14:00:00'));

    Queue::assertPushed(Recontactar::class, fn ($job) => Carbon::parse($job->delay)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') === '2026-10-14 08:00');
});

it('si las 18 h caen de noche, se corre a la apertura mientras la ventana siga abierta', function () {
    rcHora('2026-10-13 10:00:00'); // 18 h = 04:00, la ventana cierra a las 10:00
    Recontactar::agendar(rcConversacion('2026-10-13 10:00:00'));

    Queue::assertPushed(Recontactar::class, fn ($job) => Carbon::parse($job->delay)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') === '2026-10-14 08:00');
});

it('si a la apertura ya cerró la ventana, va antes del cierre del día anterior', function () {
    rcHora('2026-10-13 03:00:00'); // 18 h = 21:00, la ventana cierra 03:00 del 14
    Recontactar::agendar(rcConversacion('2026-10-13 03:00:00'));

    Queue::assertPushed(Recontactar::class, fn ($job) => Carbon::parse($job->delay)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') === '2026-10-13 18:59');
});

it('si no contestó al agente, le escribe una vez', function () {
    $conv = rcConversacion('2026-10-13 10:00:00');
    rcMensaje($conv, 'outbound', '¿De qué ciudad nos escribe?', '2026-10-13 10:01:00');

    rcHora('2026-10-14 08:00:00');
    rcCorrer($conv);
    rcCorrer($conv);

    $enviados = rcEnviados($conv);

    expect($enviados)->toHaveCount(1)
        ->and($enviados->first()->body)->toContain('Ana')
        ->and($enviados->first()->sender)->toBe('system');

    Queue::assertPushed(SendMessage::class);
});

it('si no contestó la cotización que mandó un asesor, le pregunta por la cotización', function () {
    $conv = rcConversacion('2026-10-13 10:00:00');
    rcMensaje($conv, 'outbound', 'Cotizacion-123.pdf', '2026-10-13 11:00:00', 'human', ['quote_id' => 123]);

    rcHora('2026-10-14 08:00:00');
    rcCorrer($conv);

    expect(rcEnviados($conv)->first()->body)->toContain('cotización');
});

it('si el cliente volvió a escribir, este recontacto ya no corre', function () {
    $conv = rcConversacion('2026-10-13 10:00:00');
    rcMensaje($conv, 'outbound', 'Hola', '2026-10-13 10:01:00');
    $viejo = $conv->last_inbound_at->toIso8601String();

    $conv->forceFill(['last_inbound_at' => Carbon::parse('2026-10-13 20:00:00', config('app.timezone'))])->save();

    rcHora('2026-10-14 08:00:00');
    (new Recontactar($conv->id, $viejo))->handle();

    expect(rcEnviados($conv))->toHaveCount(0);
});

it('si nadie le contestó al cliente, no se recontacta (el pendiente es nuestro)', function () {
    $conv = rcConversacion('2026-10-13 10:00:00');

    rcHora('2026-10-14 08:00:00');
    rcCorrer($conv);

    expect(rcEnviados($conv))->toHaveCount(0);
});

it('con la ventana ya cerrada no se envía', function () {
    $conv = rcConversacion('2026-10-13 10:00:00');
    rcMensaje($conv, 'outbound', 'Hola', '2026-10-13 10:01:00');

    rcHora('2026-10-14 10:30:00');
    rcCorrer($conv);

    expect(rcEnviados($conv))->toHaveCount(0);
});

it('apagado por configuración no hace nada', function () {
    config(['whatsapp.recontacto.enabled' => false]);

    $conv = rcConversacion('2026-10-13 10:00:00');
    rcMensaje($conv, 'outbound', 'Hola', '2026-10-13 10:01:00');

    rcHora('2026-10-14 08:00:00');
    rcCorrer($conv);

    expect(rcEnviados($conv))->toHaveCount(0);
});
