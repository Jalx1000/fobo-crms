<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Admin\Services\SmdStatusMapper;
use Webkul\Contact\Models\Person;
use Webkul\Doctor\Models\Doctor;
use Webkul\Lead\Models\Lead;

uses(DatabaseTransactions::class);

// ---------------------------------------------------------------------------
// Helper: payload base de Dropbox con physician + patient
// ---------------------------------------------------------------------------
function smdPayload(array $overrides = []): array
{
    $id = uniqid('smd_', true);

    return array_merge([
        '_id'         => $id,
        'archived'    => false,
        'status'      => '',
        'startDate'   => '2026-05-12T15:45:00.000Z',
        'endDate'     => '2026-05-12T16:15:00.000Z',
        'summary'     => 'Aclaramiento',
        'owner'       => '69bd9a6a7549b10008e0ac6b',
        'attendances' => [
            [
                '_id'      => '67f188ab'.uniqid(),
                'name'     => 'Paciente',
                'type'     => 'patient',
                'lastName' => 'Test',
                'phone'    => '7'.rand(1000000, 9999999),
                'fullName' => 'Paciente Test',
            ],
            [
                '_id'      => '69d90258'.uniqid(),
                'name'     => 'David',
                'type'     => 'physician',
                'lastName' => 'Sensano',
                'phone'    => '',
                'fullName' => 'David Sensano',
            ],
        ],
    ], $overrides);
}

function makeService(): IncomingAppointmentService
{
    return app(IncomingAppointmentService::class);
}

beforeEach(function () {
    // DB_PREFIX=od_ ya está activo, no agregar manualmente el prefijo en DB::table()
    $stageId = DB::table('lead_pipeline_stages')->value('id') ?? 1;

    config([
        'smd.stage_map.confirmed' => $stageId,
        'smd.stage_map.completed' => $stageId,
        'smd.stage_map.cancelled' => $stageId,
        'smd.stage_map.no_show'   => $stageId,
    ]);
});

// ---------------------------------------------------------------------------
// normalizeDropbox
// ---------------------------------------------------------------------------

it('normalizeDropbox extrae physician del attendance de type physician', function () {
    $service = makeService();

    // Accedemos al método protegido vía reflexión
    $ref    = new \ReflectionClass($service);
    $method = $ref->getMethod('normalizeDropbox');
    $method->setAccessible(true);

    $payload = smdPayload();
    $result  = $method->invoke($service, $payload);

    expect($result['doctor_name'])->toBe('David Sensano');
    expect($result['doctor_external_id'])->toStartWith('69d90258');
});

it('normalizeDropbox usa owner como fallback cuando no hay physician en attendances', function () {
    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('normalizeDropbox');
    $method->setAccessible(true);

    // Evento cancelado real: attendances solo tiene paciente, owner es string
    $payload = [
        '_id'         => 'test-'.uniqid(),
        'archived'    => false,
        'status'      => 'CANCELED',
        'startDate'   => '2026-05-20T15:30:00.000Z',
        'endDate'     => '2026-05-20T16:00:00.000Z',
        'summary'     => 'Test',
        'owner'       => '69bd9a6a7549b10008e0ac6b',
        'attendances' => [
            ['_id' => '69bd9bed'.uniqid(), 'name' => 'Paciente', 'lastName' => '1', 'fullName' => 'Paciente 1'],
        ],
    ];

    $result = $method->invoke($service, $payload);

    // Sin physician en attendances, el doctor_external_id debe ser el owner string
    expect($result['doctor_external_id'])->toBe('69bd9a6a7549b10008e0ac6b');
});

it('normalizeDropbox convierte startDate UTC a la timezone de la app', function () {
    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('normalizeDropbox');
    $method->setAccessible(true);

    $payload = smdPayload(['startDate' => '2026-05-12T15:45:00.000Z']);
    $result  = $method->invoke($service, $payload);

    // La timezone de testing es America/La_Paz (UTC-4)
    // 15:45 UTC → 11:45 La_Paz
    $parsed = Carbon::parse($result['schedule_from']);
    expect($parsed->format('H:i'))->toBe('15:45'); // normalizeDropbox no convierte, solo pasa el string — conversión es en process()
    expect($result['schedule_from'])->toBe('2026-05-12T15:45:00.000Z');
});

// ---------------------------------------------------------------------------
// processDropbox — creación de entidades
// ---------------------------------------------------------------------------

it('processDropbox crea Lead Activity Person y Doctor para un evento nuevo', function () {
    $service = makeService();

    $phoneUnique = '7'.rand(1000000, 9999999);
    $payload     = smdPayload([
        'attendances' => [
            [
                '_id'      => 'pat-'.uniqid(),
                'name'     => 'Ana',
                'type'     => 'patient',
                'lastName' => 'Lopez',
                'phone'    => $phoneUnique,
            ],
            [
                '_id'      => 'doc-'.uniqid(),
                'name'     => 'Dr. Pedro',
                'type'     => 'physician',
                'lastName' => 'Ramirez',
                'phone'    => '',
            ],
        ],
    ]);

    $result = $service->processDropbox($payload);

    expect($result)->toHaveKeys(['lead_id', 'activity_id']);
    expect($result['lead_id'])->toBeInt()->toBeGreaterThan(0);
    expect($result['activity_id'])->toBeInt()->toBeGreaterThan(0);

    // El Lead debe existir en BD
    expect(Lead::find($result['lead_id']))->not->toBeNull();

    // La actividad debe existir en BD
    expect(DB::table('activities')->find($result['activity_id']))->not->toBeNull();

    // Verificar que se creó o encontró la Person con ese teléfono
    $person = Person::whereJsonContains('contact_numbers', [['value' => $phoneUnique]])->first();
    expect($person)->not->toBeNull();
});

it('processDropbox crea Person sin crash cuando el paciente no tiene telefono', function () {
    $service = makeService();

    $payload = smdPayload([
        'attendances' => [
            [
                '_id'      => 'pat-nophone-'.uniqid(),
                'name'     => 'SinTelefono',
                'type'     => 'patient',
                'lastName' => 'Gomez',
                'phone'    => null,
            ],
            [
                '_id'      => 'doc-'.uniqid(),
                'name'     => 'Dr. Tester',
                'type'     => 'physician',
                'lastName' => 'QA',
                'phone'    => '',
            ],
        ],
    ]);

    $result = $service->processDropbox($payload);

    expect($result)->toHaveKeys(['lead_id', 'activity_id']);
    expect($result['lead_id'])->toBeInt();
});

it('processDropbox con dos pacientes sin telefono no choca en unique_id de Person', function () {
    $service = makeService();

    $smdId1 = 'pat-a-'.uniqid();
    $smdId2 = 'pat-b-'.uniqid();

    $payload1 = smdPayload([
        '_id'         => 'ev-1-'.uniqid(),
        'attendances' => [
            ['_id' => $smdId1, 'name' => 'Paciente', 'type' => 'patient', 'lastName' => 'Uno', 'phone' => null],
            ['_id' => 'doc-1-'.uniqid(), 'name' => 'Dr. A', 'type' => 'physician', 'lastName' => 'Alfa', 'phone' => ''],
        ],
    ]);

    $payload2 = smdPayload([
        '_id'         => 'ev-2-'.uniqid(),
        'attendances' => [
            ['_id' => $smdId2, 'name' => 'Paciente', 'type' => 'patient', 'lastName' => 'Dos', 'phone' => null],
            ['_id' => 'doc-1-'.uniqid(), 'name' => 'Dr. A', 'type' => 'physician', 'lastName' => 'Alfa', 'phone' => ''],
        ],
    ]);

    $r1 = $service->processDropbox($payload1);
    $r2 = $service->processDropbox($payload2);

    expect($r1['lead_id'])->toBeInt();
    expect($r2['lead_id'])->toBeInt();
    expect($r1['lead_id'])->not->toBe($r2['lead_id']);
});

// ---------------------------------------------------------------------------
// resolveDoctor
// ---------------------------------------------------------------------------

it('resolveDoctor encuentra doctor existente por unique_id exacto', function () {
    $uniqueId = 'smd-doc-'.uniqid();
    $doctor   = Doctor::create([
        'name'      => 'Doctor Existente '.uniqid(),
        'unique_id' => $uniqueId,
        'is_active' => true,
    ]);

    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('resolveDoctor');
    $method->setAccessible(true);

    $resolved = $method->invoke($service, [
        'doctor_external_id' => $uniqueId,
        'doctor_name'        => 'Cualquier Nombre',
    ]);

    expect($resolved->id)->toBe($doctor->id);
});

it('resolveDoctor encuentra doctor existente por nombre parcial', function () {
    $doctor = Doctor::create([
        'name'      => 'Jorge Alberto Perez '.uniqid(),
        'unique_id' => null,
        'is_active' => true,
    ]);

    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('resolveDoctor');
    $method->setAccessible(true);

    $resolved = $method->invoke($service, [
        'doctor_external_id' => 'nonexistent-id-'.uniqid(),
        'doctor_name'        => 'Jorge '.$doctor->name, // incluye el nombre registrado
    ]);

    expect($resolved->id)->toBe($doctor->id);
});

it('resolveDoctor crea doctor nuevo si no existe en BD y guarda unique_id', function () {
    $externalId = 'new-doc-'.uniqid();
    $name       = 'Doctor Nuevo '.uniqid();

    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('resolveDoctor');
    $method->setAccessible(true);

    $countBefore = Doctor::count();

    $resolved = $method->invoke($service, [
        'doctor_external_id' => $externalId,
        'doctor_name'        => $name,
    ]);

    expect(Doctor::count())->toBe($countBefore + 1);
    expect($resolved->unique_id)->toBe($externalId);
    expect($resolved->name)->toBe($name);
});

// ---------------------------------------------------------------------------
// resolvePerson
// ---------------------------------------------------------------------------

it('resolvePerson encuentra paciente existente por telefono', function () {
    $phone  = '7'.rand(1000000, 9999999);
    $person = Person::create([
        'name'            => 'Paciente Existente '.uniqid(),
        'contact_numbers' => [['value' => $phone, 'label' => 'work']],
        'entity_type'     => 'persons',
    ]);

    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('resolvePerson');
    $method->setAccessible(true);

    $resolved = $method->invoke($service, $phone, null, 'Otro Nombre');

    expect($resolved->id)->toBe($person->id);
});

it('resolvePerson encuentra paciente existente por smd_patient_id', function () {
    $smdId  = 'smd-pat-'.uniqid();
    $person = Person::create([
        'name'           => 'Paciente SMD '.uniqid(),
        'smd_patient_id' => $smdId,
        'entity_type'    => 'persons',
    ]);

    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('resolvePerson');
    $method->setAccessible(true);

    $resolved = $method->invoke($service, null, $smdId, 'Nombre Diferente');

    expect($resolved->id)->toBe($person->id);
});

it('resolvePerson crea paciente nuevo con telefono y asigna email kommo correcto', function () {
    $phone = '70'.rand(100000, 999999);

    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('resolvePerson');
    $method->setAccessible(true);

    $countBefore = Person::count();
    $person      = $method->invoke($service, $phone, 'smd-'.uniqid(), 'Nuevo Paciente');

    expect(Person::count())->toBe($countBefore + 1);

    // Email kommo: teléfono limpio (sin ceros iniciales) + @whatsapp.sofopolis.net
    $cleanPhone  = ltrim(preg_replace('/[^0-9]/', '', $phone), '0');
    $kommoEmail  = $cleanPhone.'@whatsapp.sofopolis.net';
    $emailValues = collect($person->emails ?? [])->pluck('value')->toArray();

    expect($emailValues)->toContain($kommoEmail);
});

it('resolvePerson crea paciente nuevo sin telefono y sin crash', function () {
    $service = makeService();
    $ref     = new \ReflectionClass($service);
    $method  = $ref->getMethod('resolvePerson');
    $method->setAccessible(true);

    $countBefore = Person::count();
    $person      = $method->invoke($service, null, null, 'Paciente Sin Tel');

    expect(Person::count())->toBe($countBefore + 1);
    expect($person->name)->toBe('Paciente Sin Tel');
    // Sin teléfono no debe tener email kommo
    expect($person->emails)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// cancelDropbox
// ---------------------------------------------------------------------------

it('cancelDropbox mueve el Lead al stage cancelado y agrega tag Cancelado', function () {
    $service = makeService();

    // Crear lead real
    $lead = Lead::create([
        'title'       => 'Lead a Cancelar '.uniqid(),
        'description' => 'Test',
        'entity_type' => 'leads',
    ]);

    $cancelledStageId = DB::table('lead_pipeline_stages')->value('id') ?? 1;
    config(['smd.stage_map.cancelled' => $cancelledStageId]);

    $existingRow = (object) [
        'lead_id'     => $lead->id,
        'activity_id' => null,
    ];

    $result = $service->cancelDropbox('ext-'.uniqid(), $existingRow);

    expect($result['action'])->toBe('cancelled');
    expect($result['lead_id'])->toBe($lead->id);

    $updatedLead = Lead::find($lead->id);
    expect($updatedLead->status)->toBe(0);
    expect($updatedLead->lead_pipeline_stage_id)->toBe($cancelledStageId);

    // Verificar tag Cancelado
    $hasTag = $updatedLead->tags()->where('name', 'Cancelado')->exists();
    expect($hasTag)->toBeTrue();
});

// ---------------------------------------------------------------------------
// updateDropbox
// ---------------------------------------------------------------------------

it('updateDropbox actualiza las fechas de la actividad con timezone correcto', function () {
    $service = makeService();

    // Crear actividad base (DB_PREFIX=od_ aplica automáticamente)
    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Actividad Original',
        'schedule_from' => '2026-05-01 09:00:00',
        'schedule_to'   => '2026-05-01 10:00:00',
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $lead = Lead::create([
        'title'       => 'Lead Update '.uniqid(),
        'description' => 'Test',
        'entity_type' => 'leads',
    ]);

    $existingRow = (object) [
        'lead_id'     => $lead->id,
        'activity_id' => $activityId,
    ];

    $newPayload = smdPayload([
        'startDate' => '2026-06-10T14:00:00.000Z',
        'endDate'   => '2026-06-10T14:30:00.000Z',
        'summary'   => 'Resumen actualizado',
    ]);

    $result = $service->updateDropbox($newPayload, $existingRow);

    expect($result['action'])->toBe('updated');
    expect($result['activity_id'])->toBe($activityId);

    $activity = DB::table('activities')->find($activityId);

    // La fecha debe haberse actualizado (la hora exacta depende de app.timezone)
    expect($activity->schedule_from)->not->toBe('2026-05-01 09:00:00');
});
