<?php

/*
|--------------------------------------------------------------------------
| Formularios de WhatsApp: cancelar y reprogramar cita
|--------------------------------------------------------------------------
| De punta a punta contra el endpoint, simulando a Meta y a SMD. Las acciones
| se aplican en segundo plano (en tests la cola es síncrona).
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Services\Flows\FlowToken;

beforeEach(function () {
    if (trim((string) shell_exec('command -v openssl')) === '') {
        $this->markTestSkipped('Falta el binario openssl para simular el cifrado de Meta.');
    }

    $this->smd = auditBootFakes();
    [$this->publicPem, $this->keyPath] = bootFlowEndpoint();
    // El orden respecto del agente se prueba en FlowReplyOrderTest.
    config(['whatsapp.flows.booking.days' => 14, 'whatsapp.flows.booking.duration_minutes' => 60, 'whatsapp.flows.confirm_wait_seconds' => 0]);

    $this->doctorId = auditDoctor(['name' => 'Dra Cambios '.uniqid()]);
    $this->doctorUid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    $specialty = DB::table('specialties')->insertGetId(['name' => 'Zq Cambios '.uniqid(), 'slug' => 'zq-c-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('doctor_specialty')->insert(['doctor_id' => $this->doctorId, 'specialty_id' => $specialty]);

    $this->day = Carbon::tomorrow(config('app.timezone'));
    auditShift($this->doctorId, $this->day, '08:00', '12:00');
    $this->smd['availability'] = auditSmdAvailability($this->doctorUid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));

    // Mamá (escribe), su hijo a cargo con una cita mañana 09:00, y un extraño con otra cita.
    $this->mama = auditPerson(['name' => 'Mama Cambios '.uniqid()]);
    $this->hijo = auditPerson(['name' => 'Hijo Cambios '.uniqid(), 'contact_numbers' => [], 'smd_patient_id' => bin2hex(random_bytes(12))]);
    DB::table('person_relations')->insert([
        'patient_person_id' => $this->hijo->id, 'responsible_person_id' => $this->mama->id,
        'relation'          => 'hijo', 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->eventId = 'evt-cambio-'.uniqid();
    $this->cita = auditAppointment($this->doctorId, $this->hijo->id, $this->day->copy()->setTime(9, 0), $this->eventId);

    $extrano = auditPerson(['name' => 'Extrano '.uniqid()]);
    $this->citaAjena = auditAppointment($this->doctorId, $extrano->id, $this->day->copy()->setTime(11, 0));

    // Sin catálogo: reprogramar consulta SMD (1 vez) al elegir la cita.
    \Illuminate\Support\Facades\Queue::fake([\Webkul\Whatsapp\Jobs\SendMessage::class]);

    $this->conv = flowConversation($this->mama->id);
});

afterEach(function () {
    @unlink($this->keyPath);
});

function lastMessage(int $conversationId): ?string
{
    return DB::table('whatsapp_messages')->where('conversation_id', $conversationId)->orderByDesc('id')->value('body');
}

/*
| Cancelar
*/

it('cancelar: lista solo las citas propias y de pacientes a cargo', function () {
    $token = FlowToken::issue($this->conv->id, 'cancelar_cita');
    $res = flowCall($this->publicPem, ['version' => '3.0', 'action' => 'INIT', 'flow_token' => $token])[1];

    $ids = collect($res['data']['appointments'])->pluck('id')->all();

    expect($res['screen'])->toBe('APPT_SELECT')
        ->and($ids)->toContain((string) $this->cita['activity_id'])
        ->not->toContain((string) $this->citaAjena['activity_id']);
});

it('cancelar: de punta a punta cancela en el CRM, avisa a SMD y responde por el chat', function () {
    $token = FlowToken::issue($this->conv->id, 'cancelar_cita');

    $confirm = step($this->publicPem, $token, 'APPT_SELECT', ['appointment' => (string) $this->cita['activity_id']]);
    expect($confirm['screen'])->toBe('CONFIRM_CANCEL')
        ->and($confirm['data']['detail'])->toContain($this->hijo->name);

    $done = step($this->publicPem, $token, 'CONFIRM_CANCEL', ['reason' => 'no_puedo']);
    expect($done['data']['extension_message_response']['params'])
        ->toMatchArray(['flow' => 'cancelar_cita', 'status' => 'pending', 'activity_id' => $this->cita['activity_id'], 'reason' => 'No puedo asistir']);

    expect((int) DB::table('leads')->where('id', $this->cita['lead_id'])->value('lead_pipeline_stage_id'))
        ->toBe((int) config('smd.stage_map.cancelled'));

    $patch = auditSent('/events/'.$this->eventId)[0];
    expect($patch->method())->toBe('PATCH')
        ->and($patch->data()['status'])->toBe(config('smd.smd_status.cancelled'))
        ->and(lastMessage($this->conv->id))->toContain('✅ Cita cancelada');
});

it('cancelar: no deja cancelar la cita de otra persona', function () {
    $token = FlowToken::issue($this->conv->id, 'cancelar_cita');

    $res = step($this->publicPem, $token, 'APPT_SELECT', ['appointment' => (string) $this->citaAjena['activity_id']]);

    expect($res['screen'])->toBe('APPT_SELECT')
        ->and($res['data']['error_message'])->toContain('Elegí una cita');
});

it('cancelar: sin citas próximas cierra sin hacer nada', function () {
    $sola = auditPerson(['name' => 'Sin Citas '.uniqid()]);
    $token = FlowToken::issue(flowConversation($sola->id)->id, 'cancelar_cita');

    $init = flowCall($this->publicPem, ['version' => '3.0', 'action' => 'INIT', 'flow_token' => $token])[1];
    expect($init['data']['appointments'][0]['id'])->toBe('none');

    step($this->publicPem, $token, 'APPT_SELECT', ['appointment' => 'none']);
    $done = step($this->publicPem, $token, 'CONFIRM_CANCEL', []);

    expect($done['data']['extension_message_response']['params'])->toMatchArray(['flow' => 'cancelar_cita', 'status' => 'none']);
});

/*
| Reprogramar
*/

it('reprogramar: de punta a punta mueve la cita, avisa a SMD y responde por el chat', function () {
    $token = FlowToken::issue($this->conv->id, 'reprogramar_cita');
    $antes = count(auditSent('/schedule/availability'));

    $dates = step($this->publicPem, $token, 'APPT_SELECT', ['appointment' => (string) $this->cita['activity_id']]);
    // Elegir la cita = 1 consulta a SMD (la especialidad del doctor).
    expect(count(auditSent('/schedule/availability')) - $antes)->toBe(1)
        ->and($dates['screen'])->toBe('SLOT_DATE')
        ->and(collect($dates['data']['dates'])->pluck('id')->all())->toBe([$this->day->toDateString()]);

    $times = step($this->publicPem, $token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    // 09:00 es la cita misma y 11:00 la del extraño: queda 10:00.
    expect(collect($times['data']['times'])->pluck('id')->all())->toBe(['10:00']);

    $summary = step($this->publicPem, $token, 'SLOT_TIME', ['time' => '10:00']);
    expect($summary['data']['detail'])->toContain('Antes:')->toContain('10:00');

    $done = step($this->publicPem, $token, 'SUMMARY', ['confirm' => 'si']);
    expect($done['data']['extension_message_response']['params'])->toMatchArray([
        'flow' => 'reprogramar_cita', 'status' => 'pending', 'previous_time' => '09:00', 'time' => '10:00',
    ]);

    $activity = DB::table('activities')->find($this->cita['activity_id']);
    expect(Carbon::parse($activity->schedule_from)->format('H:i'))->toBe('10:00')
        ->and(Carbon::parse($activity->schedule_to)->format('H:i'))->toBe('11:00');

    $patch = auditSent('/events/'.$this->eventId)[0];
    expect($patch->data()['startDate'])->toBe($this->day->copy()->setTime(10, 0)->utc()->format('Y-m-d\TH:i:s.000\Z'))
        ->and(lastMessage($this->conv->id))->toContain('✅ Cita reprogramada');
});

it('reprogramar: si el horario se ocupó antes de moverla, la deja como estaba y avisa', function () {
    $token = FlowToken::issue($this->conv->id, 'reprogramar_cita');
    step($this->publicPem, $token, 'APPT_SELECT', ['appointment' => (string) $this->cita['activity_id']]);
    step($this->publicPem, $token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    step($this->publicPem, $token, 'SLOT_TIME', ['time' => '10:00']);

    // SMD ya no tiene ese hueco (la verificación en vivo del job lo detecta).
    $this->smd['availability'] = [];

    step($this->publicPem, $token, 'SUMMARY', ['confirm' => 'si']);

    expect(Carbon::parse(DB::table('activities')->find($this->cita['activity_id'])->schedule_from)->format('H:i'))->toBe('09:00')
        ->and(lastMessage($this->conv->id))->toContain('ya no está disponible');
});

it('reprogramar: sin horarios con ese doctor deriva a recepción', function () {
    $this->smd['availability'] = [];
    $token = FlowToken::issue($this->conv->id, 'reprogramar_cita');

    $summary = step($this->publicPem, $token, 'APPT_SELECT', ['appointment' => (string) $this->cita['activity_id']]);
    expect($summary['screen'])->toBe('SUMMARY')
        ->and($summary['data']['heading'])->toContain('No hay horarios libres');

    $done = step($this->publicPem, $token, 'SUMMARY', ['confirm' => 'si']);
    expect($done['data']['extension_message_response']['params'])->toMatchArray(['flow' => 'reprogramar_cita', 'status' => 'handoff']);
});

it('reprogramar: no deja tocar la cita de otra persona', function () {
    $token = FlowToken::issue($this->conv->id, 'reprogramar_cita');

    $res = step($this->publicPem, $token, 'APPT_SELECT', ['appointment' => (string) $this->citaAjena['activity_id']]);

    expect($res['screen'])->toBe('APPT_SELECT');
});

/*
| Token y herramienta del agente
*/

it('el token lleva el formulario y los tokens viejos siguen siendo de paciente', function () {
    expect(FlowToken::flow(FlowToken::issue(5, 'cancelar_cita')))->toBe('cancelar_cita')
        ->and(FlowToken::conversationId(FlowToken::issue(5, 'reprogramar_cita')))->toBe(5);

    // Formato viejo de 3 partes, firmado igual que antes.
    $base = '7.abcdef123456';
    $viejo = $base.'.'.substr(hash_hmac('sha256', 'whatsapp-flow|'.$base, (string) config('app.key')), 0, 24);
    expect(FlowToken::flow($viejo))->toBe('paciente_tercero')->and(FlowToken::conversationId($viejo))->toBe(7);
});

it('la herramienta del agente envía los formularios de cancelar y reprogramar', function (string $flow) {
    \Illuminate\Support\Facades\Queue::fake();
    actingAsSanctumAuthenticatedAdmin();
    $this->conv->forceFill(['last_inbound_at' => now()])->save();

    $res = $this->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/flows", ['flow' => $flow])->assertOk();

    expect(FlowToken::flow($res->json('flow_token')))->toBe($flow);
})->with(['cancelar_cita', 'reprogramar_cita']);
