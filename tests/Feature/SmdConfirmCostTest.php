<?php

/*
|--------------------------------------------------------------------------
| Confirmar una cita del formulario: 1 sola revalidación de disponibilidad
|--------------------------------------------------------------------------
| Antes se revalidaba en SMD una especialidad del doctor tras otra hasta dar
| con la que tenía el horario (el 13/10 una cita hizo 3 consultas). Ahora el
| formulario pasa la especialidad elegida y se valida esa primero.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\AppointmentService;

beforeEach(function () {
    $this->smd = auditBootFakes();
    $this->day = Carbon::tomorrow(config('app.timezone'));

    $mk = fn ($name) => DB::table('specialties')->insertGetId(['name' => $name, 'slug' => 'zc-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    $this->specA = $mk('Zc Uno '.uniqid());
    $this->specB = $mk('Zc Dos '.uniqid());
    $this->specC = $mk('Zc Tres '.uniqid());

    $this->doctorId = auditDoctor(['name' => 'Dra Costo '.uniqid()]);
    foreach ([$this->specA, $this->specB, $this->specC] as $spec) {
        DB::table('doctor_specialty')->insert(['doctor_id' => $this->doctorId, 'specialty_id' => $spec]);
    }
    auditShift($this->doctorId, $this->day, '08:00', '12:00');

    // SMD solo tiene el horario libre en la TERCERA especialidad del doctor.
    $uid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    $slots = auditSmdAvailability($uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));
    $tercera = DB::table('specialties')->where('id', $this->specC)->value('name');
    $this->smd['availability'] = fn ($request) => str_contains(urldecode(urldecode($request->url())), 'specialty='.$tercera) ? $slots : [];

    $this->booking = fn (array $extra = []) => app(AppointmentService::class)->process([
        'doctor_id'     => $this->doctorId,
        'person'        => ['id' => auditPerson(['smd_patient_id' => 'smd-'.uniqid()])->id],
        'schedule_from' => $this->day->copy()->setTime(10, 0)->format('Y-m-d H:i:s'),
        'schedule_to'   => $this->day->copy()->setTime(11, 0)->format('Y-m-d H:i:s'),
        'reason'        => 'Prueba de costo',
    ] + $extra);
});

it('con la especialidad elegida, revalida en SMD una sola vez', function () {
    ($this->booking)(['specialty_id' => $this->specC]);

    expect(auditSent('/schedule/availability'))->toHaveCount(1)
        ->and(auditSent('/schedule/createEvent'))->toHaveCount(1);
});

it('sin especialidad (agenda manual), prueba las del doctor hasta dar con la correcta', function () {
    ($this->booking)();

    expect(auditSent('/schedule/availability'))->toHaveCount(3);
});

it('una especialidad que el doctor no tiene no cambia nada', function () {
    ($this->booking)(['specialty_id' => 999999]);

    expect(auditSent('/schedule/availability'))->toHaveCount(3);
});
