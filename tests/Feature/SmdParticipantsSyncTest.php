<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Activity\Models\Activity;
use Webkul\Admin\Services\SmdAppointmentSyncService;
use Webkul\Contact\Models\Person;
use Webkul\Doctor\Models\Doctor;
use Webkul\Lead\Models\Lead;

uses(DatabaseTransactions::class);

/**
 * Payload de Dropbox deshidratado (como los UPDATED reales de SMD: sin `type`
 * ni `phone` en attendances). El médico solo se puede identificar cruzando el
 * `_id` del asistente contra doctors.unique_id.
 */
function smdSyncEvent(array $overrides = []): array
{
    return array_merge([
        '_id'         => 'evt-'.uniqid(),
        'eventStatus' => 'UPDATED',
        'archived'    => false,
        'status'      => '',
        'startDate'   => '2026-07-20T13:00:00.000Z',
        'endDate'     => '2026-07-20T14:00:00.000Z',
        'summary'     => 'Cita test',
        'updated_at'  => '2026-07-14T10:00:00.000Z',
        'owner'       => ['_id' => 'owner-recepcion', 'name' => 'Recepcionista', 'lastName' => 'Odontoking', 'type' => 'entity:staff'],
        'attendances' => [],
    ], $overrides);
}

function smdSyncService(): SmdAppointmentSyncService
{
    return app(SmdAppointmentSyncService::class);
}

function smdSyncDoctor(): Doctor
{
    return Doctor::create([
        'name'      => 'Doc '.uniqid(),
        'unique_id' => 'uid-'.uniqid(),
        'is_active' => true,
    ]);
}

/**
 * Requisito: si llega un UPDATED de una cita que nunca se creó, hay que crearla.
 * La decisión se toma por presencia del `_id` en smd_synced_events, no por
 * `eventStatus`.
 */
it('un UPDATED de una cita inexistente la crea', function () {
    $doctor = smdSyncDoctor();

    $payload = smdSyncEvent([
        'eventStatus' => 'UPDATED',
        'attendances' => [
            ['_id' => 'pac-'.uniqid(), 'name' => 'Nuevo', 'lastName' => 'Paciente'],
            ['_id' => $doctor->unique_id, 'name' => 'Doc', 'lastName' => 'Test'],
        ],
    ]);

    $result = smdSyncService()->processPayload($payload, '/fake/file.json');

    expect($result)->toBe('creados');

    $row = DB::table('smd_synced_events')->where('external_id', $payload['_id'])->first();

    expect($row)->not->toBeNull();
    expect($row->activity_id)->not->toBeNull();
    expect($row->lead_id)->not->toBeNull();

    // La cita quedó colgada del doctor identificado por id, no del owner.
    $activity = Activity::find($row->activity_id);
    expect($activity->doctors->pluck('id')->all())->toContain($doctor->id);
});

/**
 * Requisito: si en SMD le cambian el paciente a una cita y ese paciente no está
 * registrado, hay que registrarlo y que la cita y el lead lo sigan.
 */
it('un cambio de paciente lo registra y reapunta la cita y el lead', function () {
    $doctor = smdSyncDoctor();
    $pacViejoId = 'pac-'.uniqid();
    $pacNuevoId = 'pac-'.uniqid();

    $payload = smdSyncEvent([
        'attendances' => [
            ['_id' => $pacViejoId, 'name' => 'Paciente', 'lastName' => 'Viejo'],
            ['_id' => $doctor->unique_id, 'name' => 'Doc', 'lastName' => 'Test'],
        ],
    ]);

    smdSyncService()->processPayload($payload, '/fake/1.json');

    $row = DB::table('smd_synced_events')->where('external_id', $payload['_id'])->first();
    $personViejo = Person::where('smd_patient_id', $pacViejoId)->first();
    expect($personViejo)->not->toBeNull();

    // Ahora SMD manda el mismo evento con OTRO paciente, nunca visto.
    $payload2 = $payload;
    $payload2['updated_at'] = '2026-07-14T11:00:00.000Z';
    $payload2['attendances'] = [
        ['_id' => $pacNuevoId, 'name' => 'Paciente', 'lastName' => 'Nuevo'],
        ['_id' => $doctor->unique_id, 'name' => 'Doc', 'lastName' => 'Test'],
    ];

    expect(smdSyncService()->processPayload($payload2, '/fake/2.json'))->toBe('actualizados');

    // Se registró el paciente nuevo...
    $personNuevo = Person::where('smd_patient_id', $pacNuevoId)->first();
    expect($personNuevo)->not->toBeNull();
    expect($personNuevo->name)->toBe('Paciente Nuevo');

    // ...la cita apunta a él...
    $activity = Activity::find($row->activity_id);
    expect($activity->participants->pluck('person_id')->all())->toContain($personNuevo->id);
    expect($activity->participants->pluck('person_id')->all())->not->toContain($personViejo->id);

    // ...y el lead también.
    expect(Lead::find($row->lead_id)->person_id)->toBe($personNuevo->id);
});

/**
 * Requisito: al médico se lo busca por su id de SMD (unique_id) y, si no,
 * por nombre. Un cambio de médico en SMD debe reflejarse en la cita.
 */
it('un cambio de medico reapunta la cita al doctor correcto', function () {
    $doctorA = smdSyncDoctor();
    $doctorB = smdSyncDoctor();

    $payload = smdSyncEvent([
        'attendances' => [
            ['_id' => 'pac-'.uniqid(), 'name' => 'Juan', 'lastName' => 'Perez'],
            ['_id' => $doctorA->unique_id, 'name' => 'Doc', 'lastName' => 'A'],
        ],
    ]);

    smdSyncService()->processPayload($payload, '/fake/1.json');
    $row = DB::table('smd_synced_events')->where('external_id', $payload['_id'])->first();

    expect(Activity::find($row->activity_id)->doctors->pluck('id')->all())->toContain($doctorA->id);

    // Reasignan la cita al doctor B (payload deshidratado, sin `type`).
    $payload2 = $payload;
    $payload2['updated_at'] = '2026-07-14T12:00:00.000Z';
    $payload2['attendances'] = [
        ['_id' => 'pac-'.uniqid(), 'name' => 'Juan', 'lastName' => 'Perez'],
        ['_id' => $doctorB->unique_id, 'name' => 'Doc', 'lastName' => 'B'],
    ];

    smdSyncService()->processPayload($payload2, '/fake/2.json');

    $doctors = Activity::find($row->activity_id)->fresh()->doctors->pluck('id')->all();
    expect($doctors)->toContain($doctorB->id);
    expect($doctors)->not->toContain($doctorA->id);
});

/**
 * Guardarraíl: un payload sin médico identificable NO debe pisar al que la cita
 * ya tiene (antes caía al `owner` y la colgaba de "Recepcionista Odontoking").
 */
it('un payload sin medico identificable conserva el doctor actual', function () {
    $doctor = smdSyncDoctor();

    $payload = smdSyncEvent([
        'attendances' => [
            ['_id' => 'pac-'.uniqid(), 'name' => 'Juan', 'lastName' => 'Perez'],
            ['_id' => $doctor->unique_id, 'name' => 'Doc', 'lastName' => 'Test'],
        ],
    ]);

    smdSyncService()->processPayload($payload, '/fake/1.json');
    $row = DB::table('smd_synced_events')->where('external_id', $payload['_id'])->first();

    // Llega una edición sin ningún asistente reconocible como médico.
    $payload2 = $payload;
    $payload2['updated_at'] = '2026-07-14T13:00:00.000Z';
    $payload2['summary'] = 'Editado sin medico';
    $payload2['attendances'] = [
        ['_id' => 'pac-'.uniqid(), 'name' => 'Juan', 'lastName' => 'Perez'],
    ];

    smdSyncService()->processPayload($payload2, '/fake/2.json');

    $activity = Activity::find($row->activity_id)->fresh();

    expect($activity->comment)->toBe('Editado sin medico');

    // El doctor original sigue ahí, y solo él: la cita no se colgó del owner.
    // (No se afirma sobre el estado global de `doctors` porque el doctor basura
    // legacy "Recepcionista Odontoking" ya existe en la BD desde antes del fix.)
    expect($activity->doctors->pluck('id')->all())->toBe([$doctor->id]);
    expect($activity->doctors->pluck('name')->implode(','))->not->toContain('Recepcionista');
});
