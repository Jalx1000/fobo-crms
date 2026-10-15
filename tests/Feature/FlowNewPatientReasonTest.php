<?php

/*
|--------------------------------------------------------------------------
| Formulario de agendar: "PACIENTE NUEVO" en el motivo de consulta
|--------------------------------------------------------------------------
| El motivo llega a Sofo (descripción del lead y comentario de la cita) y a SMD
| (description del evento, por el PATCH que asocia al paciente).
| Nuevo = sin ficha en SMD y sin citas en el CRM que no se hayan cancelado.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Services\PatientAppointments;
use Webkul\Whatsapp\Jobs\BookFlowAppointment;
use Webkul\Whatsapp\Jobs\ReplyAfterAgent;

beforeEach(function () {
    $this->smd = auditBootFakes();
    Queue::fake([ReplyAfterAgent::class]);

    $this->doctorId = auditDoctor(['name' => 'Dra Motivo '.uniqid()]);
    $uid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    $this->day = Carbon::tomorrow(config('app.timezone'));
    auditShift($this->doctorId, $this->day, '08:00', '12:00');
    $this->smd['availability'] = auditSmdAvailability($uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));
});

function bookByFlow(object $t, int $personId): object
{
    BookFlowAppointment::dispatchSync([
        'conversation_id' => 0, 'responsible_id' => $personId, 'patient_id' => $personId,
        'doctor_id'       => $t->doctorId, 'specialty_id' => 0, 'specialty' => 'Endodoncia',
        'date'            => $t->day->toDateString(), 'time' => '10:00', 'duration' => 60,
    ]);

    return DB::table('activities')
        ->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
        ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
        ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
        ->where('activity_participants.person_id', $personId)
        ->where('activities.schedule_from', $t->day->toDateString().' 10:00:00')
        ->first(['activities.comment', 'leads.description']);
}

function smdDescription(): ?string
{
    $patch = collect(auditSent('/events/'))->first(fn ($r) => $r->method() === 'PATCH');

    return $patch?->data()['description'] ?? null;
}

it('un paciente nuevo queda marcado en el motivo, en Sofo y en SMD', function () {
    $cita = bookByFlow($this, auditPerson()->id);

    // Sin cédula cargada el seguro no se verifica (FlowInsuranceCheckTest).
    expect($cita->comment)->toBe('PACIENTE NUEVO - Agendada por WhatsApp (formulario) - Endodoncia - Seguro: no verificado')
        ->and($cita->description)->toBe($cita->comment)
        ->and(smdDescription())->toBe($cita->comment);
});

it('un paciente que ya está en SMD no se marca como nuevo', function () {
    $cita = bookByFlow($this, auditPerson(['smd_patient_id' => bin2hex(random_bytes(12))])->id);

    expect($cita->comment)->toBe('Agendada por WhatsApp (formulario) - Endodoncia - Seguro: no verificado')
        ->and(smdDescription())->not->toContain('PACIENTE NUEVO');
});

it('un paciente con una cita anterior en el CRM no es nuevo', function () {
    $person = auditPerson();
    auditAppointment($this->doctorId, $person->id, Carbon::now()->subMonth(), null, (int) config('smd.stage_map.completed'));

    expect(app(PatientAppointments::class)->isNewPatient($person->id))->toBeFalse();
});

it('solo citas canceladas o sin asistir no lo hacen conocido: sigue siendo nuevo', function () {
    $person = auditPerson();
    auditAppointment($this->doctorId, $person->id, Carbon::now()->subMonth(), null, (int) config('smd.stage_map.cancelled'));
    auditAppointment($this->doctorId, $person->id, Carbon::now()->subWeek(), null, (int) config('smd.stage_map.no_show'));

    expect(app(PatientAppointments::class)->isNewPatient($person->id))->toBeTrue();
});

it('el job da de alta al paciente que el formulario dejó pendiente y le agenda la cita', function () {
    $mama = auditPerson();
    $ci = (string) rand(100000000, 999999999);

    BookFlowAppointment::dispatchSync([
        'conversation_id' => 0, 'responsible_id' => $mama->id, 'patient_id' => 0,
        'doctor_id'       => $this->doctorId, 'specialty_id' => 0, 'specialty' => 'Endodoncia',
        'date'            => $this->day->toDateString(), 'time' => '10:00', 'duration' => 60,
        'pending_patient' => [
            'input' => ['ci' => $ci, 'name' => 'Hijo Pendiente', 'birthdate' => null, 'relation' => 'hijo'],
            'found' => ['status' => 'new', 'ci' => $ci, 'display_name' => 'Hijo Pendiente'],
        ],
    ]);

    $hijo = DB::table('persons')->where('name', 'Hijo Pendiente')->first();

    expect($hijo)->not->toBeNull()
        ->and(DB::table('person_relations')->where('patient_person_id', $hijo->id)->value('responsible_person_id'))->toBe($mama->id)
        ->and(DB::table('activity_participants')->join('activities', 'activities.id', '=', 'activity_participants.activity_id')
            ->where('person_id', $hijo->id)->value('activities.comment'))->toStartWith('PACIENTE NUEVO - ');
});
