<?php

/*
|--------------------------------------------------------------------------
| Recordatorio de cita: va a quien agendó (decisión de negocio)
|--------------------------------------------------------------------------
| Si la cita la sacó otra persona por el formulario de WhatsApp (queda en
| activities.additional.booked_by_person_id), el recordatorio le llega a ella:
| un hijo agendado por su madre no tiene teléfono propio.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->day = Carbon::now(config('app.timezone'))->addDays(30)->startOfDay();
    $this->doctorId = auditDoctor(['name' => 'Dra Recordatorio '.uniqid()]);
});

function reminderAppointment(int $doctorId, int $patientId, Carbon $day, array $additional = []): int
{
    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita recordatorio',
        'schedule_from' => $day->copy()->setTime(10, 0),
        'schedule_to'   => $day->copy()->setTime(10, 30),
        'is_done'       => 0,
        'user_id'       => 1,
        'additional'    => $additional ? json_encode($additional) : null,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('activity_participants')->insert(['activity_id' => $activityId, 'person_id' => $patientId]);
    DB::table('doctor_activities')->insert(['activity_id' => $activityId, 'doctor_id' => $doctorId]);

    return $activityId;
}

it('una cita agendada por un tercero se le recuerda a quien agendó', function () {
    $mama = auditPerson(['name' => 'Mama Recordatorio', 'contact_numbers' => [['value' => '59171112222', 'label' => 'work']]]);
    $hijo = auditPerson(['name' => 'Hijo Sin Telefono', 'contact_numbers' => []]);
    reminderAppointment($this->doctorId, $hijo->id, $this->day, ['booked_by_person_id' => $mama->id, 'source' => 'whatsapp_flow']);

    $this->artisan('whatsapp:appointment-reminders', [
        '--date' => $this->day->toDateString(), '--person' => $hijo->id, '--dry-run' => true,
    ])->expectsOutputToContain('Hijo Sin Telefono (59171112222)')->assertSuccessful();
});

it('una cita sin quien agendó se le recuerda al paciente, como siempre', function () {
    $paciente = auditPerson(['name' => 'Paciente Directo', 'contact_numbers' => [['value' => '59173334444', 'label' => 'work']]]);
    reminderAppointment($this->doctorId, $paciente->id, $this->day);

    $this->artisan('whatsapp:appointment-reminders', [
        '--date' => $this->day->toDateString(), '--person' => $paciente->id, '--dry-run' => true,
    ])->expectsOutputToContain('Paciente Directo (59173334444)')->assertSuccessful();
});

it('un paciente sin teléfono y sin quien agendó se omite', function () {
    $paciente = auditPerson(['name' => 'Paciente Sin Nada', 'contact_numbers' => []]);
    reminderAppointment($this->doctorId, $paciente->id, $this->day);

    $this->artisan('whatsapp:appointment-reminders', [
        '--date' => $this->day->toDateString(), '--person' => $paciente->id, '--dry-run' => true,
    ])->expectsOutputToContain('sin teléfono')->assertSuccessful();
});
