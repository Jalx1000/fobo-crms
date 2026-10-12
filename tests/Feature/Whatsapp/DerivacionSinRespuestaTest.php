<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Jobs\AvisarDerivacionSinRespuesta;
use Webkul\Whatsapp\Mail\DerivacionSinRespuesta;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\Handoff;

/**
 * CRM-4: derivación que nadie contesta → correo al asesor asignado, con copia.
 *
 * Auditoría del 12/10: de 52 derivaciones, 28 sin respuesta humana registrada y
 * el resto con unas 18 h de mediana. Al derivar se agenda un control: si a los
 * 30 minutos (en horario) no hubo respuesta humana, sale un correo al asesor
 * asignado con copia a jalejandromogro1@gmail.com.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    Queue::fake();

    // Lunes 10:00 en La Paz: dentro del horario.
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone')));

    config([
        'whatsapp.handoff.notify'                    => false,
        'whatsapp.handoff.sin_respuesta_minutos'     => 30,
        'whatsapp.handoff.sin_respuesta_copia'       => ['jalejandromogro1@gmail.com'],
        'whatsapp.handoff.horario_inicio'            => '08:00',
        'whatsapp.handoff.horario_fin'               => '19:00',
        'whatsapp.handoff.horario_dias'              => [1, 2, 3, 4, 5, 6],
    ]);

    $this->asesor = User::where('status', 1)->whereNotNull('email')->first();

    $this->conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Sin respuesta',
        'ai_enabled' => true, 'status' => 'open', 'last_inbound_at' => now(),
    ]);

    Message::create([
        'conversation_id' => $this->conv->id, 'direction' => 'inbound', 'type' => 'text',
        'sender'          => 'contact', 'status' => 'delivered', 'body' => 'Cuánto me sale 50 unidades?',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function derivar($test, ?int $asesorId): Conversation
{
    app(Handoff::class)->request($test->conv, 'Pide precio', 'ai', null, $asesorId);

    return $test->conv->fresh();
}

function correrControl(Conversation $conv): void
{
    (new AvisarDerivacionSinRespuesta($conv->id, $conv->handoff_requested_at->toIso8601String()))->handle();
}

it('al derivar agenda el control para dentro de 30 minutos', function () {
    derivar($this, $this->asesor->id);

    Queue::assertPushed(AvisarDerivacionSinRespuesta::class, fn ($job) => $job->delay !== null);
});

it('sin respuesta humana, avisa al asesor asignado con copia', function () {
    $conv = derivar($this, $this->asesor->id);
    Carbon::setTestNow(now()->addMinutes(31));

    correrControl($conv);

    Mail::assertSent(DerivacionSinRespuesta::class, fn ($m) => $m->hasTo($this->asesor->email)
        && $m->hasCc('jalejandromogro1@gmail.com'));
});

it('si el asesor ya respondió, no avisa', function () {
    $conv = derivar($this, $this->asesor->id);
    Carbon::setTestNow(now()->addMinutes(10));

    Message::create([
        'conversation_id' => $conv->id, 'direction' => 'outbound', 'type' => 'text',
        'sender'          => 'human', 'status' => 'sent', 'body' => 'Hola, le paso el precio',
    ]);

    Carbon::setTestNow(now()->addMinutes(25));
    correrControl($conv);

    Mail::assertNothingSent();
});

it('si la derivación ya se cerró, no avisa', function () {
    $conv = derivar($this, $this->asesor->id);
    app(Handoff::class)->resolve($conv, $this->asesor->id);

    correrControl($conv);

    Mail::assertNothingSent();
});

it('sin asesor asignado, el aviso va igual a la copia', function () {
    $conv = derivar($this, null);
    $conv->forceFill(['assigned_user_id' => null])->save();

    correrControl($conv->fresh());

    Mail::assertSent(DerivacionSinRespuesta::class, fn ($m) => $m->hasTo('jalejandromogro1@gmail.com'));
});

it('fuera de horario no avisa: reagenda para la apertura', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 22:00:00', config('app.timezone')));
    $conv = derivar($this, $this->asesor->id);
    Queue::fake(); // descartar el control que agendó la derivación

    Carbon::setTestNow(now()->addMinutes(31));
    correrControl($conv);

    Mail::assertNothingSent();
    Queue::assertPushed(AvisarDerivacionSinRespuesta::class, function ($job) {
        $hora = Carbon::parse($job->delay)->setTimezone(config('app.timezone'));

        return $hora->format('Y-m-d H:i') === '2026-10-13 08:30';
    });
});

it('una derivación nueva deja sin efecto el control de la anterior', function () {
    $conv = derivar($this, $this->asesor->id);
    $viejo = $conv->handoff_requested_at->toIso8601String();

    app(Handoff::class)->resolve($conv, $this->asesor->id);
    Carbon::setTestNow(now()->addMinutes(5));
    derivar($this, $this->asesor->id);

    Carbon::setTestNow(now()->addMinutes(31));
    (new AvisarDerivacionSinRespuesta($conv->id, $viejo))->handle();

    Mail::assertNothingSent();
});
