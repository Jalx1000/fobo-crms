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

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->from = Carbon::tomorrow()->setTime(9, 0);
    $this->to = $this->from->copy()->addMinutes(30);
});

function makeDoctorWithoutSmdLink(): Doctor
{
    // unique_id y email vacíos a propósito: si la bandera SMD estuviera activa,
    // esto forzaría el paso 3 (auto-descubrir doctor) y fallaría sin un mock.
    return Doctor::create([
        'name'      => 'Doctor Sin SMD '.uniqid(),
        'is_active' => true,
    ]);
}

function makePersonForFlag(): Person
{
    $phone = '7'.rand(1000000, 9999999);

    return Person::create([
        'name'            => 'Paciente Flag '.uniqid(),
        'contact_numbers' => [['value' => $phone, 'label' => 'work']],
        'entity_type'     => 'persons',
    ]);
}

it('crea la cita 100% local sin tocar ShareMeData cuando smd.validate_availability es false', function () {
    config(['smd.validate_availability' => false]);

    $doctor = makeDoctorWithoutSmdLink();
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctor->id,
        'date'       => $this->from->toDateString(),
        'start_time' => '08:00',
        'end_time'   => '18:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Mock que NO espera ninguna llamada: si el código llega a tocar SMD, el test falla.
    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldNotReceive('checkAvailability');
    $mock->shouldNotReceive('searchPatient');
    $mock->shouldNotReceive('createPatient');
    $mock->shouldNotReceive('createEvent');
    app()->bind(ShareMeDataService::class, fn () => $mock);

    $person = makePersonForFlag();
    $service = app(AppointmentService::class);

    $result = $service->process([
        'doctor_id'     => $doctor->id,
        'person'        => ['id' => $person->id],
        'schedule_from' => $this->from->format('Y-m-d H:i:s'),
        'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
        'reason'        => 'Cita sin validar SMD',
    ]);

    expect($result['activity_id'])->not->toBeNull();
    expect($result['lead_id'])->not->toBeNull();
    expect($result['message'])->toContain('No se validó disponibilidad en ShareMeData');
});

it('sigue validando con ShareMeData cuando smd.validate_availability es true (default)', function () {
    config(['smd.validate_availability' => true]);

    $doctor = Doctor::create([
        'name'      => 'Doctor Con SMD '.uniqid(),
        'email'     => 'doc'.uniqid().'@example.com',
        'unique_id' => 'smd-doc-'.uniqid(),
        'is_active' => true,
    ]);
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctor->id,
        'date'       => $this->from->toDateString(),
        'start_time' => '08:00',
        'end_time'   => '18:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Sin disponibilidad devuelta por SMD → debe rechazar (prueba que el paso 4 sigue activo)
    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('checkAvailability')->andReturn([]);
    $mock->shouldReceive('getLastResponse')->andReturn(['status' => 200, 'body' => ['message' => 'sin slots']]);
    $mock->shouldNotReceive('createEvent');
    app()->bind(ShareMeDataService::class, fn () => $mock);

    $person = makePersonForFlag();
    $service = app(AppointmentService::class);

    expect(fn () => $service->process([
        'doctor_id'     => $doctor->id,
        'person'        => ['id' => $person->id],
        'schedule_from' => $this->from->format('Y-m-d H:i:s'),
        'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
    ]))->toThrow(AppointmentException::class, 'no tiene disponibilidad en SHAREMEDATA');
});

it('con la bandera en false, un lead abierto en el mismo horario sigue bloqueando (el conflicto local no depende de SMD)', function () {
    config(['smd.validate_availability' => false]);

    $doctor = makeDoctorWithoutSmdLink();
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctor->id,
        'date'       => $this->from->toDateString(),
        'start_time' => '08:00',
        'end_time'   => '18:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita previa abierta',
        'schedule_from' => $this->from->format('Y-m-d H:i:s'),
        'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    DB::table('doctor_activities')->insert(['doctor_id' => $doctor->id, 'activity_id' => $activityId]);

    $lead = \Webkul\Lead\Models\Lead::create(['title' => 'Lead abierto', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldNotReceive('checkAvailability');
    $mock->shouldNotReceive('createEvent');
    app()->bind(ShareMeDataService::class, fn () => $mock);

    $person = makePersonForFlag();
    $service = app(AppointmentService::class);

    expect(fn () => $service->process([
        'doctor_id'     => $doctor->id,
        'person'        => ['id' => $person->id],
        'schedule_from' => $this->from->format('Y-m-d H:i:s'),
        'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
    ]))->toThrow(AppointmentException::class, 'ya tiene una cita programada en este horario en el sistema local');
});
