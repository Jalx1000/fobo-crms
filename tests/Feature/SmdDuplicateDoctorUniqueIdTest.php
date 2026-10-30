<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Activity\Models\Activity;
use Webkul\Admin\Services\SmdAppointmentSyncService;
use Webkul\Doctor\Models\Doctor;

uses(DatabaseTransactions::class);

/**
 * Caso real (odontoking_dev, 2026-10-23): la cita de Adriana Soria creada en
 * SMD entró al CRM colgada de "ADRIANA SORIA AGUILAR" (id 12, inactivo) en vez
 * de "Adriana Soria" (id 44, activo), porque los dos tenían el mismo unique_id
 * y la búsqueda devolvía el primero. En la agenda de la doctora no aparecía.
 */
function duplicateDoctorEvent(string $physicianId): array
{
    return [
        '_id'         => 'evt-'.uniqid(),
        'eventStatus' => 'CREATED',
        'archived'    => false,
        'status'      => '',
        'startDate'   => '2026-07-20T13:00:00.000Z',
        'endDate'     => '2026-07-20T14:00:00.000Z',
        'summary'     => '',
        'updated_at'  => '2026-07-14T10:00:00.000Z',
        'attendances' => [
            ['_id' => 'pac-'.uniqid(), 'name' => 'Alejandro', 'lastName' => 'Externo', 'type' => 'patient', 'phone' => '59170000002'],
            ['_id' => $physicianId, 'name' => 'Adriana', 'lastName' => 'Soria', 'type' => 'physician', 'phone' => ''],
        ],
    ];
}

function syncedActivityDoctors(array $payload): array
{
    app(SmdAppointmentSyncService::class)->processPayload($payload, '/fake/file.json');

    $activityId = DB::table('smd_synced_events')->where('external_id', $payload['_id'])->value('activity_id');

    return Activity::find($activityId)->doctors->pluck('id')->all();
}

it('con dos doctores con el mismo unique_id asigna la cita al activo aunque el inactivo sea más viejo', function () {
    $uid = 'uid-'.uniqid();
    $inactive = Doctor::create(['name' => 'ADRIANA SORIA AGUILAR '.uniqid(), 'unique_id' => $uid, 'is_active' => false]);
    $active = Doctor::create(['name' => 'Adriana Soria '.uniqid(), 'unique_id' => $uid, 'is_active' => true]);

    expect(syncedActivityDoctors(duplicateDoctorEvent($uid)))
        ->toBe([$active->id])
        ->not->toContain($inactive->id);
});

it('si el único doctor con ese unique_id está inactivo, la cita igual se le asigna', function () {
    $uid = 'uid-'.uniqid();
    $inactive = Doctor::create(['name' => 'Ex doctora '.uniqid(), 'unique_id' => $uid, 'is_active' => false]);

    // Que entre al doctor dado de baja (y se vea) es mejor que crear uno nuevo duplicado.
    expect(syncedActivityDoctors(duplicateDoctorEvent($uid)))->toBe([$inactive->id]);
});
