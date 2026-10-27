<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Admin\Services\AppointmentService;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Contact\Models\Person;
use Webkul\Doctor\Models\Doctor;
use Webkul\Lead\Models\Lead;

uses(DatabaseTransactions::class);

/*
| Un paciente no puede tener dos citas activas con el mismo doctor el mismo día
| creadas por el CRM (pantalla, API del agente, formulario de WhatsApp). Antes
| solo se rechazaba si el DOCTOR estaba ocupado en ese horario: mandar dos veces
| el formulario con horarios distintos creaba dos citas.
*/

function samePatientDoctor(): Doctor
{
    $doctor = Doctor::create([
        'name'      => 'Doctor Mismo Dia '.uniqid(),
        'email'     => 'doc'.uniqid().'@example.com',
        'unique_id' => 'smd-doc-'.uniqid(),
        'is_active' => true,
    ]);

    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctor->id,
        'date'       => Carbon::tomorrow()->toDateString(),
        'start_time' => '08:00',
        'end_time'   => '18:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $doctor;
}

function samePatientPerson(): Person
{
    return Person::create([
        'name'            => 'Paciente Mismo Dia '.uniqid(),
        'contact_numbers' => [['value' => '7'.rand(1000000, 9999999), 'label' => 'work']],
        'smd_patient_id'  => 'smd-pat-'.uniqid(),
        'entity_type'     => 'persons',
    ]);
}

/** Cita previa del paciente con el doctor, con su lead en la etapa/estado dados. */
function samePatientExistingAppointment(int $doctorId, int $personId, Carbon $from, ?int $stageId, ?int $status = 1): int
{
    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita previa '.uniqid(),
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $from->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('doctor_activities')->insert(['doctor_id' => $doctorId, 'activity_id' => $activityId]);
    DB::table('activity_participants')->insert(['activity_id' => $activityId, 'person_id' => $personId]);

    $lead = Lead::create(['title' => 'Lead previo '.uniqid(), 'description' => 'Test', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update(['status' => $status, 'lead_pipeline_stage_id' => $stageId]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    return $activityId;
}

function samePatientBook(array $doctorIds, int $personId, Carbon $from): array
{
    return app(AppointmentService::class)->process([
        'doctor_id'     => $doctorIds[0],
        'doctor_ids'    => $doctorIds,
        'person'        => ['id' => $personId],
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $from->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
        'reason'        => 'Test mismo día',
    ]);
}

beforeEach(function () {
    // Sin SMD: el control es local y tiene que valer igual con o sin validación.
    config(['smd.validate_availability' => false]);

    $stages = DB::table('lead_pipeline_stages')->orderBy('id')->pluck('id');
    $this->openStage = (int) $stages->first();
    $this->cancelledStage = (int) $stages->last();
    $this->noShowStage = (int) $stages->get($stages->count() - 2);

    config([
        'smd.stage_map.cancelled' => $this->cancelledStage,
        'smd.stage_map.no_show'   => $this->noShowStage,
    ]);

    $this->morning = Carbon::tomorrow()->setTime(9, 0);
    $this->afternoon = Carbon::tomorrow()->setTime(15, 0);
});

it('rechaza una segunda cita del mismo paciente con el mismo doctor el mismo día', function () {
    $doctor = samePatientDoctor();
    $person = samePatientPerson();
    $existing = samePatientExistingAppointment($doctor->id, $person->id, $this->morning, $this->openStage);

    try {
        samePatientBook([$doctor->id], $person->id, $this->afternoon);
        $this->fail('Se esperaba AppointmentException');
    } catch (AppointmentException $e) {
        expect($e->getMessage())
            ->toContain($person->name)
            ->toContain($doctor->name)
            ->toContain('09:00');
        expect($e->getDetails())->toMatchArray([
            'code'                 => 'patient_already_booked',
            'existing_activity_id' => $existing,
        ]);
    }

    // No quedó nada a medias: ni cita ni lead nuevos para ese paciente.
    expect(DB::table('activity_participants')->where('person_id', $person->id)->count())->toBe(1);
});

it('rechaza antes de tocar SMD: no consulta disponibilidad ni crea el evento', function () {
    config(['smd.validate_availability' => true]);

    $doctor = samePatientDoctor();
    $person = samePatientPerson();
    samePatientExistingAppointment($doctor->id, $person->id, $this->morning, $this->openStage);

    $smd = \Mockery::mock(ShareMeDataService::class);
    $smd->shouldNotReceive('checkAvailability');
    $smd->shouldNotReceive('createEvent');
    app()->bind(ShareMeDataService::class, fn () => $smd);

    expect(fn () => samePatientBook([$doctor->id], $person->id, $this->afternoon))
        ->toThrow(AppointmentException::class);
});

it('rechaza si el paciente ya tiene cita ese día con un doctor adicional de la cita nueva', function () {
    $main = samePatientDoctor();
    $other = samePatientDoctor();
    $person = samePatientPerson();
    samePatientExistingAppointment($other->id, $person->id, $this->morning, $this->openStage);

    expect(fn () => samePatientBook([$main->id, $other->id], $person->id, $this->afternoon))
        ->toThrow(AppointmentException::class, $other->name);
});

it('permite la cita si la anterior del mismo día está cancelada (por etapa o por status)', function (string $how) {
    $doctor = samePatientDoctor();
    $person = samePatientPerson();

    match ($how) {
        'etapa cancelada' => samePatientExistingAppointment($doctor->id, $person->id, $this->morning, $this->cancelledStage),
        'cita perdida'    => samePatientExistingAppointment($doctor->id, $person->id, $this->morning, $this->noShowStage),
        'status 0'        => samePatientExistingAppointment($doctor->id, $person->id, $this->morning, $this->openStage, 0),
    };

    expect(samePatientBook([$doctor->id], $person->id, $this->afternoon)['activity_id'])->not->toBeNull();
})->with(['etapa cancelada', 'cita perdida', 'status 0']);

it('permite el mismo paciente con OTRO doctor el mismo día', function () {
    $doctor = samePatientDoctor();
    $other = samePatientDoctor();
    $person = samePatientPerson();
    samePatientExistingAppointment($other->id, $person->id, $this->morning, $this->openStage);

    expect(samePatientBook([$doctor->id], $person->id, $this->afternoon)['activity_id'])->not->toBeNull();
});

it('permite el mismo paciente con el mismo doctor OTRO día', function () {
    $doctor = samePatientDoctor();
    $person = samePatientPerson();
    samePatientExistingAppointment($doctor->id, $person->id, $this->morning->copy()->addDay(), $this->openStage);

    expect(samePatientBook([$doctor->id], $person->id, $this->afternoon)['activity_id'])->not->toBeNull();
});

it('permite OTRO paciente con el mismo doctor el mismo día', function () {
    $doctor = samePatientDoctor();
    samePatientExistingAppointment($doctor->id, samePatientPerson()->id, $this->morning, $this->openStage);

    expect(samePatientBook([$doctor->id], samePatientPerson()->id, $this->afternoon)['activity_id'])->not->toBeNull();
});
