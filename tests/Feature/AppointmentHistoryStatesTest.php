<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;

/*
| Historial de citas del modal de detalle. Antes solo conocía 3 estados:
| "cita perdida" salía como cancelada y "cirugía" como confirmada. Ahora usa los
| mismos estados que la barra y trae la fecha ISO para abrir la cita desde el
| historial.
*/

uses(DatabaseTransactions::class);

function historyAppointment(int $personId, Carbon $from, ?int $stageId): int
{
    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita historial '.uniqid(),
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $from->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('activity_participants')->insert(['activity_id' => $activityId, 'person_id' => $personId]);

    $lead = Lead::create(['title' => 'Lead historial '.uniqid(), 'description' => 'Test', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update(['lead_pipeline_stage_id' => $stageId]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    return $activityId;
}

it('cada etapa sale con su propio estado, igual que la barra de estado', function () {
    $person = Person::create(['name' => 'Paciente Historial '.uniqid(), 'entity_type' => 'persons']);
    $day = Carbon::parse('2026-03-02 09:00');

    $ids = [];
    foreach (['confirmed', 'completed', 'surgery', 'no_show', 'cancelled'] as $i => $state) {
        $ids[$state] = historyAppointment($person->id, $day->copy()->addDays($i), (int) config('smd.stage_map.'.$state));
    }
    $ids['scheduled'] = historyAppointment($person->id, $day->copy()->addDays(9), (int) config('smd.stage_map.default'));

    $this->actingAs(getDefaultAdmin());

    $rows = collect($this->getJson(route('admin.activities.persons.appointments', $person->id))
        ->assertOk()->json('appointments'))->keyBy('id');

    foreach ($ids as $state => $id) {
        expect($rows[$id]['state'])->toBe($state);
    }

    expect($rows[$ids['no_show']]['state'])->not->toBe('cancelled')
        ->and($rows[$ids['surgery']]['state'])->not->toBe('confirmed');
});

it('cada fila trae la fecha ISO y la etapa para abrirla desde el historial', function () {
    $person = Person::create(['name' => 'Paciente Historial '.uniqid(), 'entity_type' => 'persons']);
    $stage = (int) config('smd.stage_map.confirmed');
    $id = historyAppointment($person->id, Carbon::parse('2026-03-05 14:30'), $stage);

    $this->actingAs(getDefaultAdmin());

    $row = $this->getJson(route('admin.activities.persons.appointments', $person->id))
        ->assertOk()->json('appointments.0');

    expect($row)->toMatchArray([
        'id'       => $id,
        'date'     => '05/03/2026',
        'date_iso' => '2026-03-05',
        'time'     => '14:30',
        'stage_id' => $stage,
    ]);
});
