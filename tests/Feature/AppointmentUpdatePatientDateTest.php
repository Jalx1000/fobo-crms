<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Contact\Models\Person;
use Webkul\Doctor\Models\Doctor;
use Webkul\Lead\Models\Lead;

uses(DatabaseTransactions::class);

/**
 * Cubre la edición de citas desde el calendario vía `updateAppointment`
 * (admin.activities.appointments.update), en particular los dos campos nuevos
 * del formulario de edición: Paciente y Fecha, más Servicio y Estado. El foco
 * es que el cambio se persista en local Y se empuje a ShareMeData (SMD) con el
 * paciente correcto en `attendances`, garantizando el round-trip.
 */
beforeEach(function () {
    $this->admin = getDefaultAdmin();
    test()->actingAs($this->admin);

    $this->from = Carbon::tomorrow()->setTime(9, 0);
    $this->to = $this->from->copy()->addMinutes(30);

    $this->doctor = Doctor::create([
        'name'      => 'Dr. Edit '.uniqid(),
        'unique_id' => 'smd-doc-'.uniqid(),
        'is_active' => true,
    ]);

    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $this->doctor->id,
        'date'       => $this->from->toDateString(),
        'start_time' => '08:00',
        'end_time'   => '18:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

/**
 * Arma una cita (Activity meeting) vinculada a un paciente, un doctor y un lead.
 * `smdExternalId` no nulo crea también el vínculo en smd_synced_events para que
 * la edición dispare el push a SMD.
 */
function makeEditableAppointment(int $doctorId, int $personId, Carbon $from, Carbon $to, int $stageId, ?string $smdExternalId = null): array
{
    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita Editable '.uniqid(),
        'comment'       => 'motivo original',
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $to->format('Y-m-d H:i:s'),
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('doctor_activities')->insert(['doctor_id' => $doctorId, 'activity_id' => $activityId]);
    DB::table('activity_participants')->insert(['person_id' => $personId, 'activity_id' => $activityId]);

    $lead = Lead::create(['title' => 'Lead Editable', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update([
        'person_id'              => $personId,
        'lead_pipeline_stage_id' => $stageId,
    ]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    if ($smdExternalId) {
        DB::table('smd_synced_events')->insert([
            'external_id' => $smdExternalId,
            'activity_id' => $activityId,
            'lead_id'     => $lead->id,
            'raw_payload' => json_encode(['_id' => $smdExternalId, 'eventId' => 'evt-1']),
            'status'      => 'confirmed',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    return ['activity_id' => $activityId, 'lead_id' => $lead->id];
}

function makePatientWithSmd(string $label): Person
{
    return Person::create([
        'name'            => $label.' '.uniqid(),
        'contact_numbers' => [['value' => '7'.rand(1000000, 9999999), 'label' => 'work']],
        'entity_type'     => 'persons',
        'smd_patient_id'  => 'smd-pat-'.uniqid(),
    ]);
}

it('reasigna el paciente y cambia la fecha, y empuja a SMD el paciente nuevo en attendances', function () {
    $patientA = makePatientWithSmd('Paciente A');
    $patientB = makePatientWithSmd('Paciente B');

    $stageId = (int) DB::table('lead_pipeline_stages')->orderBy('id')->value('id');
    $ids = makeEditableAppointment($this->doctor->id, $patientA->id, $this->from, $this->to, $stageId, 'smd-evt-'.uniqid());

    $newDate = $this->from->copy()->addDay()->toDateString();

    // El push a SMD debe recibir al paciente B (su smd_patient_id) en attendances.
    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('updateEvent')
        ->once()
        ->withArgs(function ($externalId, $body) use ($patientB) {
            $patientAttendances = collect($body['attendances'] ?? [])
                ->where('type', 'patient')
                ->pluck('_id')
                ->all();

            return in_array($patientB->smd_patient_id, $patientAttendances, true)
                && ! empty($body['startDate']);
        })
        ->andReturn(['success' => true, 'message' => 'ok']);
    app()->bind(ShareMeDataService::class, fn () => $mock);

    $response = test()->postJson(route('admin.activities.appointments.update', $ids['activity_id']), [
        'date'             => $newDate,
        'start_time'       => '11:00',
        'duration_minutes' => 30,
        'person_id'        => $patientB->id,
        'reason'           => 'motivo editado',
    ]);

    $response->assertStatus(200);
    expect($response->json('smd.synced'))->toBeTrue();

    // Participants (paciente) reasignados a B.
    expect(DB::table('activity_participants')->where('activity_id', $ids['activity_id'])->pluck('person_id')->all())
        ->toEqual([$patientB->id]);

    // El lead también apunta al paciente nuevo.
    expect((int) DB::table('leads')->where('id', $ids['lead_id'])->value('person_id'))->toBe($patientB->id);

    // La fecha/hora local se movió al día siguiente 11:00.
    $activity = DB::table('activities')->find($ids['activity_id']);
    expect(Carbon::parse($activity->schedule_from)->format('Y-m-d H:i'))->toBe($newDate.' 11:00');
    expect($activity->comment)->toBe('motivo editado');
});

it('sincroniza el servicio (product) de la cita', function () {
    $patient = makePatientWithSmd('Paciente P');
    $stageId = (int) DB::table('lead_pipeline_stages')->orderBy('id')->value('id');
    $ids = makeEditableAppointment($this->doctor->id, $patient->id, $this->from, $this->to, $stageId, 'smd-evt-'.uniqid());

    $productId = DB::table('products')->insertGetId([
        'sku'        => 'srv-'.uniqid(),
        'name'       => 'Limpieza dental',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('updateEvent')->andReturn(['success' => true, 'message' => 'ok']);
    app()->bind(ShareMeDataService::class, fn () => $mock);

    $response = test()->postJson(route('admin.activities.appointments.update', $ids['activity_id']), [
        'product_id' => $productId,
    ]);

    $response->assertStatus(200);
    expect(DB::table('product_activities')->where('activity_id', $ids['activity_id'])->pluck('product_id')->all())
        ->toEqual([$productId]);
});

it('mueve el lead a la etapa indicada por lead_pipeline_stage_id', function () {
    $patient = makePatientWithSmd('Paciente E');
    $stages = DB::table('lead_pipeline_stages')->orderBy('id')->pluck('id')->values();
    $stageA = (int) $stages[0];
    $stageB = (int) ($stages->filter(fn ($id) => $id !== $stageA)->first() ?? $stageA);

    $ids = makeEditableAppointment($this->doctor->id, $patient->id, $this->from, $this->to, $stageA, 'smd-evt-'.uniqid());

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('updateEvent')->andReturn(['success' => true, 'message' => 'ok']);
    app()->bind(ShareMeDataService::class, fn () => $mock);

    $response = test()->postJson(route('admin.activities.appointments.update', $ids['activity_id']), [
        'lead_pipeline_stage_id' => $stageB,
    ]);

    $response->assertStatus(200);
    expect((int) DB::table('leads')->where('id', $ids['lead_id'])->value('lead_pipeline_stage_id'))->toBe($stageB);
});

it('actualiza en local aunque la cita no esté vinculada a SMD (synced=false, sin fallar)', function () {
    $patient = makePatientWithSmd('Paciente Local');
    $stageId = (int) DB::table('lead_pipeline_stages')->orderBy('id')->value('id');
    // Sin external_id → sin fila en smd_synced_events → no hay evento que editar en SMD.
    $ids = makeEditableAppointment($this->doctor->id, $patient->id, $this->from, $this->to, $stageId, null);

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldNotReceive('updateEvent');
    app()->bind(ShareMeDataService::class, fn () => $mock);

    $newDate = $this->from->copy()->addDays(2)->toDateString();

    $response = test()->postJson(route('admin.activities.appointments.update', $ids['activity_id']), [
        'date'             => $newDate,
        'start_time'       => '14:00',
        'duration_minutes' => 30,
    ]);

    $response->assertStatus(200);
    expect($response->json('smd.synced'))->toBeFalse();

    $activity = DB::table('activities')->find($ids['activity_id']);
    expect(Carbon::parse($activity->schedule_from)->format('Y-m-d H:i'))->toBe($newDate.' 14:00');
});
