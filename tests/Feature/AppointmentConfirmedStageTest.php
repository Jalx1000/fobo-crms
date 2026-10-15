<?php

/*
|--------------------------------------------------------------------------
| Una cita confirmada queda en "Cita Confirmada"
|--------------------------------------------------------------------------
| Bug (2026-10-12): las citas del formulario de WhatsApp para un tercero (sin
| teléfono propio) volvían a "Consultas". El paciente no se registraba en SMD,
| no se hacía el PATCH que deja el evento CONFIRMED, SMD lo devolvía con status
| vacío y la sync lo mapeaba a Consultas.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Whatsapp\Jobs\BookFlowAppointment;
use Webkul\Whatsapp\Jobs\ReplyAfterAgent;

beforeEach(function () {
    $this->smd = auditBootFakes();
    Queue::fake([ReplyAfterAgent::class]);
    config(['services.insurance.cache_enabled' => false]);

    $this->doctorId = auditDoctor(['name' => 'Dra Etapa '.uniqid()]);
    $uid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    $this->day = Carbon::tomorrow(config('app.timezone'));
    auditShift($this->doctorId, $this->day, '08:00', '12:00');
    $this->smd['availability'] = auditSmdAvailability($uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));
    $this->confirmed = (int) config('smd.stage_map.confirmed');
});

function leadOfPatient(int $personId): object
{
    return DB::table('leads')->join('lead_activities', 'lead_activities.lead_id', '=', 'leads.id')
        ->join('activity_participants', 'activity_participants.activity_id', '=', 'lead_activities.activity_id')
        ->where('activity_participants.person_id', $personId)->orderByDesc('leads.id')->first(['leads.id', 'leads.lead_pipeline_stage_id']);
}

it('una cita para un tercero sin teléfono queda en Cita Confirmada y el paciente se registra en SMD por cédula', function () {
    $mama = auditPerson(['contact_numbers' => [['value' => '59170000001', 'label' => 'work']]]);
    $ci = (string) rand(100000000, 999999999);

    BookFlowAppointment::dispatchSync([
        'conversation_id' => 0, 'responsible_id' => $mama->id, 'patient_id' => 0,
        'doctor_id'       => $this->doctorId, 'specialty_id' => 0, 'specialty' => 'Endodoncia',
        'date'            => $this->day->toDateString(), 'time' => '10:00', 'duration' => 60,
        'pending_patient' => [
            'input' => ['ci' => $ci, 'name' => 'Hijo Sin Telefono', 'birthdate' => null, 'relation' => 'hijo'],
            'found' => ['status' => 'new', 'ci' => $ci, 'display_name' => 'Hijo Sin Telefono'],
        ],
    ]);

    $hijo = DB::table('persons')->where('name', 'Hijo Sin Telefono')->first();
    $alta = collect(auditSent('/patients/user'))->first();
    $patch = collect(auditSent('/events/'))->first(fn ($r) => $r->method() === 'PATCH');

    expect(leadOfPatient($hijo->id)->lead_pipeline_stage_id)->toBe($this->confirmed)
        ->and($hijo->smd_patient_id)->not->toBeNull()
        // Con el teléfono de la madre como contacto, pero identificado por la cédula.
        ->and(json_encode($alta?->data()))->toContain('59170000001')->toContain($ci)
        ->and($patch?->data()['status'])->toBe(config('smd.smd_status.confirmed'))
        ->and(collect($patch?->data()['attendances'])->firstWhere('type', 'patient')['_id'] ?? null)->toBe($hijo->smd_patient_id);
});

it('el PATCH que confirma el evento en SMD se hace aunque no haya paciente de SMD', function () {
    $persona = auditPerson(['contact_numbers' => []]);

    BookFlowAppointment::dispatchSync([
        'conversation_id' => 0, 'responsible_id' => $persona->id, 'patient_id' => $persona->id,
        'doctor_id'       => $this->doctorId, 'specialty_id' => 0, 'specialty' => 'Endodoncia',
        'date'            => $this->day->toDateString(), 'time' => '10:00', 'duration' => 60,
    ]);

    $patch = collect(auditSent('/events/'))->first(fn ($r) => $r->method() === 'PATCH');

    expect($patch?->data()['status'])->toBe(config('smd.smd_status.confirmed'))
        ->and(leadOfPatient($persona->id)->lead_pipeline_stage_id)->toBe($this->confirmed);
});

it('un status vacío que vuelve de SMD no baja una cita confirmada a Consultas', function () {
    $persona = auditPerson();
    $eventId = 'evt-etapa-'.uniqid();
    $cita = auditAppointment($this->doctorId, $persona->id, $this->day->copy()->setTime(10, 0), $eventId, $this->confirmed);
    $uid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');

    app(IncomingAppointmentService::class)->updateDropbox([
        '_id'         => $eventId,
        'archived'    => false,
        'status'      => '',
        'startDate'   => $this->day->copy()->setTime(10, 0)->utc()->format('Y-m-d\TH:i:s.000\Z'),
        'endDate'     => $this->day->copy()->setTime(11, 0)->utc()->format('Y-m-d\TH:i:s.000\Z'),
        'summary'     => 'CONSULTA: test',
        'attendances' => [['_id' => $uid, 'name' => 'Dra', 'lastName' => 'Etapa', 'type' => 'physician', 'phone' => '']],
    ], (object) ['lead_id' => $cita['lead_id'], 'activity_id' => $cita['activity_id']]);

    expect((int) DB::table('leads')->where('id', $cita['lead_id'])->value('lead_pipeline_stage_id'))->toBe($this->confirmed);
});

it('un status vacío sigue dejando en Consultas a una cita que no estaba confirmada', function () {
    $persona = auditPerson();
    $eventId = 'evt-etapa-'.uniqid();
    $cita = auditAppointment($this->doctorId, $persona->id, $this->day->copy()->setTime(10, 0), $eventId, (int) config('smd.stage_map.completed'));
    $uid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');

    app(IncomingAppointmentService::class)->updateDropbox([
        '_id'         => $eventId, 'archived' => false, 'status' => '',
        'startDate'   => $this->day->copy()->setTime(10, 0)->utc()->format('Y-m-d\TH:i:s.000\Z'),
        'endDate'     => $this->day->copy()->setTime(11, 0)->utc()->format('Y-m-d\TH:i:s.000\Z'),
        'summary'     => 'CONSULTA: test',
        'attendances' => [['_id' => $uid, 'name' => 'Dra', 'lastName' => 'Etapa', 'type' => 'physician', 'phone' => '']],
    ], (object) ['lead_id' => $cita['lead_id'], 'activity_id' => $cita['activity_id']]);

    expect((int) DB::table('leads')->where('id', $cita['lead_id'])->value('lead_pipeline_stage_id'))->toBe((int) config('smd.stage_map.default'));
});
