<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\CerrarDerivacion;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Cierre automático de derivaciones: a las 6 horas sin actividad.
 *
 * Una derivación solo se cerraba a mano y casi nunca se cerraba (12/10: 48
 * abiertas, 8 cerradas). Mientras está abierta la IA no le contesta a ese
 * cliente, ni siquiera si vuelve días después. Ahora se cierra sola a las 6 h
 * del último mensaje (del cliente o del asesor) y la IA vuelve a atender.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00', config('app.timezone')));

    config(['whatsapp.handoff.notify' => false, 'whatsapp.handoff.cierre_horas' => 6]);

    $this->conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Cierre',
        'ai_enabled' => true, 'status' => 'open', 'last_inbound_at' => now(),
    ]);

    app(Handoff::class)->request($this->conv, 'Pide precio', 'ai', null, 1);
    $this->conv = $this->conv->fresh();
});

afterEach(fn () => Carbon::setTestNow());

function cierreMensaje($conv, string $direccion = 'inbound'): void
{
    Message::create([
        'conversation_id' => $conv->id, 'direction' => $direccion, 'type' => 'text',
        'sender'          => $direccion === 'inbound' ? 'contact' : 'human', 'status' => 'sent', 'body' => 'Hola',
    ]);
}

function correrCierre(Conversation $conv): void
{
    (new CerrarDerivacion($conv->id, $conv->handoff_requested_at->toIso8601String()))->handle(app(Handoff::class));
}

it('al derivar agenda el cierre', function () {
    Queue::assertPushed(CerrarDerivacion::class, fn ($job) => $job->delay !== null);
});

it('a las 6 horas sin actividad la cierra y la IA vuelve a atender', function () {
    Carbon::setTestNow(now()->addHours(6)->addMinute());

    correrCierre($this->conv);

    $conv = $this->conv->fresh();

    expect($conv->handoff_state)->toBe(Conversation::HANDOFF_RESOLVED)
        ->and((bool) $conv->ai_enabled)->toBeTrue();
});

it('si hubo mensajes, cuenta las 6 horas desde el último y reagenda', function () {
    Carbon::setTestNow(now()->addHours(4));
    cierreMensaje($this->conv, 'outbound');

    Carbon::setTestNow(now()->addHours(2)->addMinute());
    Queue::fake();
    // Con `sync` el job no se reagenda (correría al instante); en producción es Redis.
    config(['queue.default' => 'redis']);

    correrCierre($this->conv);

    expect($this->conv->fresh()->handoffOpen())->toBeTrue();

    Queue::assertPushed(CerrarDerivacion::class, function ($job) {
        return Carbon::parse($job->delay)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') === '2026-10-13 20:00';
    });
});

it('una derivación ya cerrada no se toca', function () {
    app(Handoff::class)->resolve($this->conv, 1, false);
    Carbon::setTestNow(now()->addHours(7));

    correrCierre($this->conv);

    expect((bool) $this->conv->fresh()->ai_enabled)->toBeFalse();
});

it('una derivación nueva deja sin efecto el cierre agendado para la anterior', function () {
    $vieja = $this->conv->handoff_requested_at->toIso8601String();

    app(Handoff::class)->resolve($this->conv, 1);
    Carbon::setTestNow(now()->addHour());
    app(Handoff::class)->request($this->conv->fresh(), 'Otra vez', 'ai', null, 1);

    Carbon::setTestNow(now()->addHours(6)->addMinute());
    (new CerrarDerivacion($this->conv->id, $vieja))->handle(app(Handoff::class));

    expect($this->conv->fresh()->handoffOpen())->toBeTrue();
});

it('el comando cierra las derivaciones abiertas que llevan 6 horas sin actividad', function () {
    $vieja = Conversation::create([
        'gateway'              => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Vieja',
        'ai_enabled'           => false, 'status' => 'open', 'handoff_state' => Conversation::HANDOFF_ASSIGNED,
        'handoff_requested_at' => now()->subDays(2), 'assigned_user_id' => 1,
    ]);

    $this->artisan('whatsapp:cerrar-derivaciones')->assertSuccessful();

    expect($vieja->fresh()->handoff_state)->toBe(Conversation::HANDOFF_RESOLVED)
        ->and($this->conv->fresh()->handoffOpen())->toBeTrue();
});
