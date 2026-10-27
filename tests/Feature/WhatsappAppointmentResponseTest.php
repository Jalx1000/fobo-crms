<?php

/*
|--------------------------------------------------------------------------
| Respuesta del paciente al recordatorio (herramienta del agente)
|--------------------------------------------------------------------------
| POST /api/v1/whatsapp/conversations/{id}/appointments/respond
| "confirma" pasa la cita a confirmada y la empuja a SMD como CONFIRMED;
| "no_asiste" solo lo anota para recepción. El contexto del agente lista los
| recordatorios con su respuesta.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Services\PatientContext;

beforeEach(function () {
    $this->smd = auditBootFakes();
    actingAsSanctumAuthenticatedAdmin();

    $this->doctorId = auditDoctor(['name' => 'Dra Respuesta '.uniqid()]);
    $this->day = Carbon::tomorrow(config('app.timezone'));

    $this->mama = auditPerson(['name' => 'Mama Respuesta '.uniqid()]);
    $this->conv = flowConversation($this->mama->id);

    // Cita de SMD con estado vacío: está en Consultas hasta que alguien la confirme.
    $this->eventId = 'evt-resp-'.uniqid();
    $this->cita = auditAppointment($this->doctorId, $this->mama->id, $this->day->copy()->setTime(9, 0), $this->eventId, (int) config('smd.stage_map.default'));
});

function remindedTo(int $conversationId, int $activityId): void
{
    DB::table('whatsapp_appointment_reminders')->insert([
        'activity_id' => $activityId, 'conversation_id' => $conversationId,
        'sent_at'     => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function respondUrl(int $conversationId): string
{
    return "/api/v1/whatsapp/conversations/{$conversationId}/appointments/respond";
}

it('"confirma" sin id toma el único recordatorio pendiente, confirma la cita y la empuja a SMD', function () {
    remindedTo($this->conv->id, $this->cita['activity_id']);

    $res = $this->postJson(respondUrl($this->conv->id), ['respuesta' => 'confirma'])->assertOk();

    expect($res->json('cita.activity_id'))->toBe($this->cita['activity_id'])
        ->and((int) DB::table('leads')->where('id', $this->cita['lead_id'])->value('lead_pipeline_stage_id'))->toBe((int) config('smd.stage_map.confirmed'))
        ->and(auditSent('/events/'.$this->eventId)[0]->data()['status'])->toBe(config('smd.smd_status.confirmed'))
        ->and(DB::table('whatsapp_appointment_reminders')->where('activity_id', $this->cita['activity_id'])->value('response'))->toBe('confirma');

    $nota = DB::table('lead_activities')->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
        ->where('lead_activities.lead_id', $this->cita['lead_id'])->where('activities.type', 'note')->value('comment');
    expect($nota)->toContain('CONFIRMÓ');
});

it('"no_asiste" no toca la cita ni SMD, solo lo anota para recepción', function () {
    remindedTo($this->conv->id, $this->cita['activity_id']);

    $this->postJson(respondUrl($this->conv->id), ['respuesta' => 'no_asiste'])->assertOk();

    expect((int) DB::table('leads')->where('id', $this->cita['lead_id'])->value('lead_pipeline_stage_id'))->toBe((int) config('smd.stage_map.default'))
        ->and(auditSent('/events/'.$this->eventId))->toBeEmpty()
        ->and(DB::table('whatsapp_appointment_reminders')->where('activity_id', $this->cita['activity_id'])->value('response'))->toBe('no_asiste');

    $additional = json_decode(DB::table('activities')->where('id', $this->cita['activity_id'])->value('additional'), true);
    expect($additional['patient_response']['respuesta'])->toBe('no_asiste');
});

it('con dos recordatorios sin contestar pide el activity_id y los lista', function () {
    $otra = auditAppointment($this->doctorId, $this->mama->id, $this->day->copy()->setTime(10, 0));
    remindedTo($this->conv->id, $this->cita['activity_id']);
    remindedTo($this->conv->id, $otra['activity_id']);

    $res = $this->postJson(respondUrl($this->conv->id), ['respuesta' => 'confirma'])->assertStatus(422);

    expect(collect($res->json('recordatorios'))->pluck('activity_id')->all())
        ->toEqualCanonicalizing([$this->cita['activity_id'], $otra['activity_id']]);
});

it('confirma una cita propia por lead_id aunque no haya recordatorio', function () {
    $this->postJson(respondUrl($this->conv->id), ['respuesta' => 'confirma', 'lead_id' => $this->cita['lead_id']])
        ->assertOk()
        ->assertJsonPath('cita.activity_id', $this->cita['activity_id']);
});

it('rechaza la cita de otra persona', function () {
    $extrano = auditPerson(['name' => 'Extrano Respuesta '.uniqid()]);
    $ajena = auditAppointment($this->doctorId, $extrano->id, $this->day->copy()->setTime(11, 0));

    $this->postJson(respondUrl($this->conv->id), ['respuesta' => 'confirma', 'activity_id' => $ajena['activity_id']])
        ->assertStatus(422);

    expect((int) DB::table('leads')->where('id', $ajena['lead_id'])->value('lead_pipeline_stage_id'))->toBe((int) config('smd.stage_map.confirmed'))
        ->and(DB::table('lead_activities')->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
            ->where('lead_activities.lead_id', $ajena['lead_id'])->where('activities.type', 'note')->exists())->toBeFalse();
});

it('el contexto del agente lista el recordatorio con su respuesta', function () {
    remindedTo($this->conv->id, $this->cita['activity_id']);

    $antes = app(PatientContext::class)->build($this->conv->fresh())['recordatorios'];
    expect($antes)->toHaveCount(1)
        ->and($antes[0])->toMatchArray(['activity_id' => $this->cita['activity_id'], 'lead_id' => $this->cita['lead_id'], 'hora' => '09:00', 'respuesta' => null]);

    $this->postJson(respondUrl($this->conv->id), ['respuesta' => 'confirma'])->assertOk();

    expect(app(PatientContext::class)->build($this->conv->fresh())['recordatorios'][0]['respuesta'])->toBe('confirma');
});

it('valida la respuesta', function () {
    $this->postJson(respondUrl($this->conv->id), ['respuesta' => 'quizas'])->assertStatus(422)->assertJsonValidationErrors('respuesta');
});
