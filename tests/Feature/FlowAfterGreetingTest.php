<?php

/*
|--------------------------------------------------------------------------
| Formularios: el saludo del agente llega ANTES que el formulario
|--------------------------------------------------------------------------
| El agente llama a la herramienta del formulario a mitad de su turno y manda
| su texto ("¡Hola! … le envié un formulario") ~2 s después, así que el
| formulario llegaba primero (conversación 6065, 2026-10-29). SendFlowAfterAgent
| lo retiene hasta que el texto del agente ya salió (o vence el tope).
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\SendFlowAfterAgent;
use Webkul\Whatsapp\Jobs\SendMessage;

beforeEach(function () {
    Queue::fake([SendMessage::class]);
    // El .env de producción tiene el modo prueba del agente: acá no aplica.
    config(['whatsapp.ai.enabled' => true, 'whatsapp.ai.test_mode' => false, 'whatsapp.flows.send_wait_seconds' => 10]);

    $this->conv = flowConversation(auditPerson()->id);
    $this->conv->forceFill(['ai_enabled' => true])->save();
});

function outboundIa(int $conversationId, string $type, string $status, $at = null): int
{
    return DB::table('whatsapp_messages')->insertGetId([
        'conversation_id' => $conversationId, 'direction' => 'outbound', 'type' => $type,
        'body'            => $type === 'flow' ? 'Para agendar tu cita, completá este formulario.' : '¡Hola! 👋 Le envié un formulario',
        'status'          => $status, 'sender' => 'ia',
        'created_at'      => $at ?? now(), 'updated_at' => $at ?? now(),
    ]);
}

function flowSent(int $flowId): bool
{
    return Queue::pushed(SendMessage::class, fn ($job) => $job->messageId === $flowId)->isNotEmpty();
}

it('mientras el agente no mandó su texto, el formulario espera', function () {
    $flow = outboundIa($this->conv->id, 'flow', 'queued');

    (new SendFlowAfterAgent($flow, time()))->handle();

    expect(flowSent($flow))->toBeFalse();
});

it('si el texto del agente todavía está en cola, el formulario sigue esperando', function () {
    $flow = outboundIa($this->conv->id, 'flow', 'queued');
    outboundIa($this->conv->id, 'text', 'queued');

    (new SendFlowAfterAgent($flow, time()))->handle();

    expect(flowSent($flow))->toBeFalse();
});

it('cuando el texto del agente ya salió, el formulario sale después', function () {
    $flow = outboundIa($this->conv->id, 'flow', 'queued');
    outboundIa($this->conv->id, 'text', 'sent');

    (new SendFlowAfterAgent($flow, time()))->handle();

    expect(flowSent($flow))->toBeTrue();
});

it('un texto del agente ANTERIOR al formulario no cuenta como saludo', function () {
    outboundIa($this->conv->id, 'text', 'sent', now()->subMinute());
    $flow = outboundIa($this->conv->id, 'flow', 'queued');

    (new SendFlowAfterAgent($flow, time()))->handle();

    expect(flowSent($flow))->toBeFalse();
});

it('si el agente no manda texto, el formulario sale igual al vencer el tope', function () {
    $flow = outboundIa($this->conv->id, 'flow', 'queued');

    (new SendFlowAfterAgent($flow, time() - 11))->handle();

    expect(flowSent($flow))->toBeTrue();
});

it('sin agente activo el formulario sale al toque', function () {
    $this->conv->forceFill(['ai_enabled' => false])->save();
    $flow = outboundIa($this->conv->id, 'flow', 'queued');

    (new SendFlowAfterAgent($flow, time()))->handle();

    expect(flowSent($flow))->toBeTrue();
});

it('con la espera en 0 el formulario sale al toque', function () {
    config(['whatsapp.flows.send_wait_seconds' => 0]);
    $flow = outboundIa($this->conv->id, 'flow', 'queued');

    (new SendFlowAfterAgent($flow, time()))->handle();

    expect(flowSent($flow))->toBeTrue();
});
