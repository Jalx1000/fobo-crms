<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Las derivaciones abiertas se cierran solas pasadas las horas configuradas en
 * Configuración → WhatsApp → Derivaciones (12 por defecto).
 */
uses(DatabaseTransactions::class);

beforeEach(fn () => Queue::fake());

function derivadaHace(int $horas, string $estado = Conversation::HANDOFF_REQUESTED): object
{
    $conversacion = ConversationProxy::modelClass()::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '+54938'.random_int(10000000, 99999999),
        'wa_name'         => 'Cliente derivado',
        'ai_enabled'      => false,
        'last_message_at' => now(),
    ]);

    $conversacion->forceFill([
        'handoff_state'        => $estado,
        'handoff_source'       => 'ai',
        'handoff_requested_at' => now()->subHours($horas),
    ])->save();

    return $conversacion;
}

function configurarCierre(bool $activo, int $horas, bool $devolver = true): void
{
    foreach (['cierre_automatico' => (int) $activo, 'horas' => $horas, 'devolver_al_agente' => (int) $devolver] as $campo => $valor) {
        DB::table('core_config')->updateOrInsert(['code' => "whatsapp.derivaciones.{$campo}"], ['value' => $valor]);
    }

    app()->forgetInstance('core');
}

it('cierra la derivación de más de 12 horas y devuelve la conversación al agente', function () {
    configurarCierre(true, 12);
    config(['whatsapp.ai.enabled' => true]);
    $vieja = derivadaHace(13);
    $nueva = derivadaHace(5, Conversation::HANDOFF_ASSIGNED);

    test()->artisan('whatsapp:cerrar-derivaciones')->assertSuccessful();

    expect($vieja->fresh()->handoff_state)->toBe(Conversation::HANDOFF_RESOLVED)
        // Vuelve a lo general (null): con el agente prendido para todos, contesta.
        ->and($vieja->fresh()->ai_enabled)->toBeNull()
        ->and($vieja->fresh()->aiEffective())->toBeTrue()
        ->and($nueva->fresh()->handoff_state)->toBe(Conversation::HANDOFF_ASSIGNED);
});

it('respeta las horas configuradas en el portal', function () {
    configurarCierre(true, 2);
    $derivada = derivadaHace(3);

    test()->artisan('whatsapp:cerrar-derivaciones')->assertSuccessful();

    expect($derivada->fresh()->handoff_state)->toBe(Conversation::HANDOFF_RESOLVED);
});

it('puede cerrarla sin devolverla al agente', function () {
    configurarCierre(true, 12, devolver: false);
    $derivada = derivadaHace(13);

    test()->artisan('whatsapp:cerrar-derivaciones')->assertSuccessful();

    expect($derivada->fresh()->handoff_state)->toBe(Conversation::HANDOFF_RESOLVED)
        ->and($derivada->fresh()->ai_enabled)->toBeFalse();
});

it('con el cierre automático apagado no toca nada', function () {
    configurarCierre(false, 12);
    $derivada = derivadaHace(30);

    test()->artisan('whatsapp:cerrar-derivaciones')->assertSuccessful();

    expect($derivada->fresh()->handoff_state)->toBe(Conversation::HANDOFF_REQUESTED);
});
