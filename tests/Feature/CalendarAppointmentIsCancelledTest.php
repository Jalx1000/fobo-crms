<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $stages = DB::table('lead_pipeline_stages')->orderBy('id')->pluck('id')->values();
    $this->openStage = (int) $stages[0];
    $this->cancelledStage = (int) ($stages->filter(fn ($id) => $id !== $this->openStage)->first() ?? $this->openStage);
    $this->completedStage = (int) ($stages->filter(fn ($id) => ! in_array($id, [$this->openStage, $this->cancelledStage], true))->first() ?? $this->cancelledStage);

    config([
        'smd.stage_map.cancelled' => $this->cancelledStage,
        'smd.stage_map.no_show'   => $this->cancelledStage,
        'smd.stage_map.completed' => $this->completedStage,
    ]);
});

function makeCalendarActivity(int $doctorId, Carbon $from, Carbon $to): int
{
    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita Test Calendario '.uniqid(),
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $to->format('Y-m-d H:i:s'),
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('doctor_activities')->insert(['doctor_id' => $doctorId, 'activity_id' => $activityId]);

    return $activityId;
}

it('marca is_cancelled=true cuando el lead esta en el stage cancelado, aunque status sea NULL', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Calendario Cancelado '.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $from = Carbon::tomorrow()->setTime(9, 0);
    $to = $from->copy()->addHour();

    $activityId = makeCalendarActivity($doctorId, $from, $to);

    $lead = Lead::create(['title' => 'Lead Cancelado Calendario', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update([
        'status'                 => null,
        'lead_pipeline_stage_id' => $this->cancelledStage,
    ]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $from->toDateString(),
    ]));

    $response->assertStatus(200);
    $appointment = collect($response->json('appointments'))->firstWhere('id', $activityId);

    expect($appointment)->not->toBeNull();
    expect($appointment['is_cancelled'])->toBeTrue();
});

it('marca is_cancelled=true cuando el lead tiene status=0, sin importar el stage', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Calendario Status0 '.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $from = Carbon::tomorrow()->setTime(10, 0);
    $to = $from->copy()->addHour();

    $activityId = makeCalendarActivity($doctorId, $from, $to);

    $lead = Lead::create(['title' => 'Lead Status0 Calendario', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update([
        'status'                 => 0,
        'lead_pipeline_stage_id' => $this->openStage,
    ]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $from->toDateString(),
    ]));

    $appointment = collect($response->json('appointments'))->firstWhere('id', $activityId);

    expect($appointment['is_cancelled'])->toBeTrue();
});

it('marca is_cancelled=false cuando el lead esta abierto (status NULL, stage no cancelado)', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Calendario Abierto '.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $from = Carbon::tomorrow()->setTime(11, 0);
    $to = $from->copy()->addHour();

    $activityId = makeCalendarActivity($doctorId, $from, $to);

    $lead = Lead::create(['title' => 'Lead Abierto Calendario', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update([
        'status'                 => null,
        'lead_pipeline_stage_id' => $this->openStage,
    ]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $from->toDateString(),
    ]));

    $appointment = collect($response->json('appointments'))->firstWhere('id', $activityId);

    expect($appointment['is_cancelled'])->toBeFalse();
});

it('marca is_cancelled=false cuando la activity no tiene ningun lead vinculado', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Calendario SinLead '.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $from = Carbon::tomorrow()->setTime(12, 0);
    $to = $from->copy()->addHour();

    $activityId = makeCalendarActivity($doctorId, $from, $to);

    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $from->toDateString(),
    ]));

    $appointment = collect($response->json('appointments'))->firstWhere('id', $activityId);

    expect($appointment)->not->toBeNull();
    expect($appointment['is_cancelled'])->toBeFalse();
});

it('marca is_completed=true cuando el lead esta en el stage Paciente (concretado)', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Calendario Concretado '.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $from = Carbon::tomorrow()->setTime(13, 0);
    $to = $from->copy()->addHour();

    $activityId = makeCalendarActivity($doctorId, $from, $to);

    $lead = Lead::create(['title' => 'Lead Concretado Calendario', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update([
        'status'                 => null,
        'lead_pipeline_stage_id' => $this->completedStage,
    ]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $from->toDateString(),
    ]));

    $appointment = collect($response->json('appointments'))->firstWhere('id', $activityId);

    expect($appointment['is_completed'])->toBeTrue();
    expect($appointment['is_cancelled'])->toBeFalse();
});

it('is_cancelled tiene prioridad sobre is_completed si ambos stages coincidieran', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    // Caso borde: si por config ambos stage_map apuntaran al mismo id
    config(['smd.stage_map.completed' => $this->cancelledStage]);

    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Calendario Prioridad '.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $from = Carbon::tomorrow()->setTime(14, 0);
    $to = $from->copy()->addHour();

    $activityId = makeCalendarActivity($doctorId, $from, $to);

    $lead = Lead::create(['title' => 'Lead Prioridad Calendario', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update([
        'status'                 => null,
        'lead_pipeline_stage_id' => $this->cancelledStage,
    ]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $from->toDateString(),
    ]));

    $appointment = collect($response->json('appointments'))->firstWhere('id', $activityId);

    expect($appointment['is_cancelled'])->toBeTrue();
    expect($appointment['is_completed'])->toBeFalse();
});
