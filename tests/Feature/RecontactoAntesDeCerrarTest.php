<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Models\ConversationProxy;

/*
 * Recordatorio ~1 h antes de que se cierre la ventana de 24 h.
 *
 * La suite corre contra la base real, así que todo va dentro de una transacción.
 * Queue::fake() para que ningún mensaje salga de verdad a un cliente.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
});

/**
 * Una conversación en la franja de disparo: el cliente escribió hace
 * (ventana − 1 h), y lo último que pasó fue una respuesta del negocio.
 */
function enLaFranja(array $attrs = []): object
{
    $horas = app(\Webkul\Whatsapp\Gateways\GatewayManager::class)->active()->messagingWindowHours() ?? 24;

    $entrante = now()->subHours($horas)->addMinutes(50); // faltan ~50 min para cerrar

    return ConversationProxy::modelClass()::create(array_merge([
        'gateway'         => config('whatsapp.gateway'),
        'wa_phone'        => '5917'.random_int(1000000, 9999999),
        'wa_name'         => 'Cliente Recontacto',
        'status'          => 'open',
        'ai_enabled'      => true,
        'last_inbound_at' => $entrante,
        // Posterior al entrante: la pelota quedó del lado del cliente.
        'last_message_at' => $entrante->copy()->addMinutes(1),
    ], $attrs));
}

function correrRecontacto(): string
{
    test()->artisan('crm:recontactar-antes-de-cerrar');

    return \Illuminate\Support\Facades\Artisan::output();
}

/** Los mensajes salientes de esa conversación. */
function salientesDe(int $conversationId)
{
    return DB::table('whatsapp_messages')
        ->where('conversation_id', $conversationId)
        ->where('direction', 'outbound')
        ->get();
}

// ---------------------------------------------------------------------------
// El caso que el spec pide probar
// ---------------------------------------------------------------------------

it('manda el recordatorio a una conversación que quedó sin respuesta', function () {
    $conv = enLaFranja();

    correrRecontacto();

    $mensajes = salientesDe($conv->id);

    expect($mensajes)->toHaveCount(1)
        // En voz del agente, igual que el resto de sus respuestas.
        ->and($mensajes->first()->sender)->toBe('ia')
        ->and($mensajes->first()->type)->toBe('text')
        // Con el nombre de pila, no el string crudo del perfil.
        ->and($mensajes->first()->body)->toContain('Cliente')
        ->and($mensajes->first()->body)->not->toContain('{nombre}');

    // Y queda marcado para no repetir en esta ventana.
    expect($conv->fresh()->followup_sent_at)->not->toBeNull();
});

it('no manda nada si el cliente fue el último que escribió', function () {
    /*
     * La pelota está del lado del negocio: el agente le debe respuesta, y un
     * recordatorio ahí sonaría absurdo.
     */
    $conv = enLaFranja();
    $conv->forceFill(['last_message_at' => $conv->last_inbound_at->copy()->subMinute()])->save();

    correrRecontacto();

    expect(salientesDe($conv->id))->toHaveCount(0)
        ->and($conv->fresh()->followup_sent_at)->toBeNull();
});

it('no manda nada si la IA está apagada (el asesor tomó la conversación)', function () {
    $conv = enLaFranja(['ai_enabled' => false]);

    correrRecontacto();

    expect(salientesDe($conv->id))->toHaveCount(0);
});

it('no manda nada si la conversación está cerrada', function () {
    $conv = enLaFranja(['status' => 'closed']);

    correrRecontacto();

    expect(salientesDe($conv->id))->toHaveCount(0);
});

it('sigue recontactando a un número sin ficha de contacto', function () {
    /*
     * ConversationResolver guarda 'unassigned' cuando el número no tiene persona
     * asociada. Exigir status='open' habría apagado el recontacto justo para los
     * leads con más riesgo de perderse, que son la razón de ser de esto.
     */
    $conv = enLaFranja(['status' => 'unassigned']);

    correrRecontacto();

    expect(salientesDe($conv->id))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Ventana y franja
// ---------------------------------------------------------------------------

it('no manda nada fuera de la franja: todavía falta mucho para cerrar', function () {
    $conv = enLaFranja();
    // El cliente escribió recién: faltan ~24 h, no ~1.
    $conv->forceFill([
        'last_inbound_at' => now()->subMinutes(5),
        'last_message_at' => now()->subMinutes(4),
    ])->save();

    correrRecontacto();

    expect(salientesDe($conv->id))->toHaveCount(0);
});

it('no manda nada si la ventana ya se cerró', function () {
    $horas = app(\Webkul\Whatsapp\Gateways\GatewayManager::class)->active()->messagingWindowHours() ?? 24;

    $conv = enLaFranja();
    $conv->forceFill([
        'last_inbound_at' => now()->subHours($horas + 2),
        'last_message_at' => now()->subHours($horas + 1),
    ])->save();

    correrRecontacto();

    // Fuera de la ventana haría falta una plantilla aprobada, no texto libre.
    expect(salientesDe($conv->id))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Idempotencia: máximo uno por ventana
// ---------------------------------------------------------------------------

it('manda como máximo un recordatorio por ventana', function () {
    $conv = enLaFranja();

    correrRecontacto();
    correrRecontacto();
    correrRecontacto();

    expect(salientesDe($conv->id))->toHaveCount(1);
});

it('vuelve a recontactar en la ventana siguiente', function () {
    $conv = enLaFranja();

    correrRecontacto();
    expect(salientesDe($conv->id))->toHaveCount(1);

    /*
     * El cliente escribió de nuevo: eso reinicia la ventana. El
     * `followup_sent_at` anterior pertenece a una ventana ya vencida y no debe
     * bloquear el recordatorio de la nueva — de ahí la comparación contra
     * `last_inbound_at` en vez de un booleano.
     */
    $horas = app(\Webkul\Whatsapp\Gateways\GatewayManager::class)->active()->messagingWindowHours() ?? 24;
    $nuevoEntrante = now()->subHours($horas)->addMinutes(50);

    /*
     * El follow-up anterior tiene que quedar ANTES del nuevo entrante, que es la
     * única secuencia posible en producción: el cliente escribe después de haber
     * recibido el recordatorio. Dejarlo en `now()` y poner el entrante en el
     * pasado describe algo que no puede pasar, y la condición —con razón— lo lee
     * como "ya recontacté en esta ventana".
     */
    $conv->forceFill([
        'followup_sent_at' => $nuevoEntrante->copy()->subHour(),
        'last_inbound_at'  => $nuevoEntrante,
        'last_message_at'  => $nuevoEntrante->copy()->addMinute(),
    ])->save();

    correrRecontacto();

    expect(salientesDe($conv->id))->toHaveCount(2);
});

it('el dry-run no manda nada ni marca la conversación', function () {
    $conv = enLaFranja();

    test()->artisan('crm:recontactar-antes-de-cerrar', ['--dry-run' => true]);

    expect(salientesDe($conv->id))->toHaveCount(0)
        ->and($conv->fresh()->followup_sent_at)->toBeNull();
});
