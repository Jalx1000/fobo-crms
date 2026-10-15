<?php

/*
|--------------------------------------------------------------------------
| Formularios: la confirmación llega DESPUÉS del "estoy registrando…" del agente
|--------------------------------------------------------------------------
| La cita se crea en ~3 s y el agente tarda más en contestar el flow_reply,
| así que la "✅ Cita confirmada" llegaba primero. ReplyAfterAgent la retiene
| hasta que el agente contestó (o vence el tope).
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\CancelFlowAppointment;
use Webkul\Whatsapp\Jobs\ReplyAfterAgent;
use Webkul\Whatsapp\Jobs\SendMessage;

beforeEach(function () {
    Queue::fake([SendMessage::class]);
    config(['whatsapp.ai.enabled' => true, 'whatsapp.flows.confirm_wait_seconds' => 40]);

    $this->conv = flowConversation(auditPerson()->id);
    $this->conv->forceFill(['ai_enabled' => true])->save();
});

function systemReplies(int $conversationId): array
{
    return DB::table('whatsapp_messages')->where('conversation_id', $conversationId)->where('sender', 'system')->pluck('body')->all();
}

function agentSays(int $conversationId, string $text): void
{
    DB::table('whatsapp_messages')->insert([
        'conversation_id' => $conversationId, 'direction' => 'outbound', 'type' => 'text',
        'body'            => $text, 'status' => 'sent', 'sender' => 'ia', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('mientras el agente no contestó el formulario, la confirmación espera', function () {
    (new ReplyAfterAgent($this->conv->id, '✅ Cita confirmada', time()))->handle();

    expect(systemReplies($this->conv->id))->toBe([]);
    Queue::assertNotPushed(SendMessage::class);
});

it('cuando el agente ya contestó, la confirmación sale después de su mensaje', function () {
    $since = time() - 5;
    agentSays($this->conv->id, '¡Perfecto! Estoy registrando su solicitud');

    (new ReplyAfterAgent($this->conv->id, '✅ Cita confirmada', $since))->handle();

    $bodies = DB::table('whatsapp_messages')->where('conversation_id', $this->conv->id)->orderBy('id')->pluck('body')->all();
    expect($bodies)->toBe(['¡Perfecto! Estoy registrando su solicitud', '✅ Cita confirmada']);
    Queue::assertPushed(SendMessage::class);
});

it('un mensaje del agente ANTERIOR al formulario no cuenta como respuesta', function () {
    DB::table('whatsapp_messages')->insert([
        'conversation_id' => $this->conv->id, 'direction' => 'outbound', 'type' => 'text',
        'body'            => 'Te envío un formulario 👇', 'status' => 'sent', 'sender' => 'ia',
        'created_at'      => now()->subMinute(), 'updated_at' => now()->subMinute(),
    ]);

    (new ReplyAfterAgent($this->conv->id, '✅ Cita confirmada', time()))->handle();

    expect(systemReplies($this->conv->id))->toBe([]);
});

it('si el agente no contesta, se envía igual al vencer el tope', function () {
    (new ReplyAfterAgent($this->conv->id, '✅ Cita confirmada', time() - 41))->handle();

    expect(systemReplies($this->conv->id))->toBe(['✅ Cita confirmada']);
});

it('sin agente activo (lo atiende una persona) se envía al toque', function () {
    $this->conv->forceFill(['ai_enabled' => false])->save();

    (new ReplyAfterAgent($this->conv->id, '✅ Cita confirmada', time()))->handle();

    expect(systemReplies($this->conv->id))->toBe(['✅ Cita confirmada']);
});

it('los jobs de los formularios mandan su resultado por ReplyAfterAgent', function () {
    Queue::fake([ReplyAfterAgent::class]);

    // Sea cual sea el resultado (acá una actividad inexistente), sale por la misma vía.
    (new CancelFlowAppointment(999999999, $this->conv->id))->handle(app(\Webkul\Admin\Services\PatientAppointments::class));

    Queue::assertPushed(ReplyAfterAgent::class, fn ($job) => $job->conversationId === $this->conv->id && $job->since <= time());
});
