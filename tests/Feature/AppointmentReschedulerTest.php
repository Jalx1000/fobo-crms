<?php

/*
|--------------------------------------------------------------------------
| Reprogramar = cancelar la cita y crear una nueva (planning/17)
|--------------------------------------------------------------------------
| AppointmentRescheduler es el único punto de verdad para el formulario de
| WhatsApp y para el calendario del CRM. Orden: validar → crear la nueva →
| cancelar la vieja; si se superponen, cancelar primero y restaurar si falla.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Admin\Services\AppointmentRescheduler;

uses(DatabaseTransactions::class);

beforeEach(function () {
    test()->actingAs(getDefaultAdmin());
    $this->smd = auditBootFakes();

    $this->day = Carbon::now(config('app.timezone'))->addDays(30)->startOfDay();
    $this->doctorId = auditDoctor(['name' => 'Dra Reprograma '.uniqid()]);
    $this->doctorUid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    auditShift($this->doctorId, $this->day, '08:00', '18:00');
    $this->smd['availability'] = auditSmdAvailability($this->doctorUid, $this->day->copy()->setTime(8, 0), $this->day->copy()->setTime(18, 0));

    $this->patient = auditPerson(['name' => 'Paciente Reprograma '.uniqid(), 'smd_patient_id' => bin2hex(random_bytes(12))]);
    $this->oldEventId = 'evt-vieja-'.uniqid();
    $this->old = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(9, 0), $this->oldEventId);

    $this->newEventId = 'evt-nueva-'.uniqid();
    $this->smd['createEvent'] = auditCreateEventResponse($this->newEventId);
});

function rescheduler(): AppointmentRescheduler
{
    return app(AppointmentRescheduler::class);
}

function leadOf(int $activityId): object
{
    return DB::table('leads')->whereIn('id', DB::table('lead_activities')->where('activity_id', $activityId)->pluck('lead_id'))->first();
}

function additionalOf(int $activityId): array
{
    return json_decode((string) DB::table('activities')->where('id', $activityId)->value('additional'), true) ?: [];
}

/** Lo que se mandó por PATCH al evento de SMD dado, en orden. */
function patchesTo(string $eventId): array
{
    return array_map(fn ($r) => $r->data(), auditSent('/events/'.$eventId));
}

it('cancela la vieja y crea una nueva cita, con lead y evento de SMD propios', function () {
    $from = $this->day->copy()->setTime(15, 0);

    $result = rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30));

    $newId = $result['new_activity_id'];
    $new = DB::table('activities')->find($newId);
    $oldLead = leadOf($this->old['activity_id']);
    $newLead = leadOf($newId);

    expect($newId)->not->toBe($this->old['activity_id'])
        ->and($result['old_activity_id'])->toBe($this->old['activity_id'])
        // La nueva: horario nuevo, mismo paciente y doctor, lead nuevo, evento nuevo.
        ->and(Carbon::parse($new->schedule_from)->format('Y-m-d H:i'))->toBe($from->format('Y-m-d H:i'))
        ->and(DB::table('activity_participants')->where('activity_id', $newId)->value('person_id'))->toBe($this->patient->id)
        ->and(DB::table('doctor_activities')->where('activity_id', $newId)->pluck('doctor_id')->all())->toBe([$this->doctorId])
        ->and($newLead->id)->not->toBe($oldLead->id)
        ->and((int) $newLead->lead_pipeline_stage_id)->toBe((int) config('smd.stage_map.confirmed'))
        ->and(DB::table('smd_synced_events')->where('activity_id', $newId)->value('external_id'))->toBe($this->newEventId)
        // La vieja: cancelada como cualquier cancelada, y SMD lo sabe.
        ->and((int) $oldLead->lead_pipeline_stage_id)->toBe((int) config('smd.stage_map.cancelled'))
        ->and((int) $oldLead->status)->toBe(0)
        ->and($oldLead->lost_reason)->toBe('Reprogramada')
        ->and(collect(patchesTo($this->oldEventId))->last()['status'])->toBe(config('smd.smd_status.cancelled'))
        // La vieja conserva su horario: es historia.
        ->and(Carbon::parse(DB::table('activities')->find($this->old['activity_id'])->schedule_from)->format('H:i'))->toBe('09:00');
});

it('deja las dos citas vinculadas', function () {
    $from = $this->day->copy()->setTime(15, 0);

    $newId = rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30))['new_activity_id'];

    expect(additionalOf($newId)['rescheduled_from'] ?? null)->toBe($this->old['activity_id'])
        ->and(additionalOf($this->old['activity_id'])['rescheduled_to'] ?? null)->toBe($newId);
});

it('mismo doctor y mismo día no choca con "ya tiene una cita ese día"', function () {
    $from = $this->day->copy()->setTime(11, 0);

    expect(rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30))['new_activity_id'])
        ->toBeInt();
});

it('la nueva copia motivo, servicio, recepcionista asignada y quién agendó', function () {
    $receptionist = DB::table('users')->where('id', '!=', 1)->value('id') ?? 1;
    DB::table('leads')->where('id', $this->old['lead_id'])->update(['user_id' => $receptionist]);
    DB::table('activities')->where('id', $this->old['activity_id'])->update([
        'comment'    => 'Dolor de muela',
        'additional' => json_encode(['booked_by_person_id' => 4242, 'source' => 'whatsapp_flow']),
    ]);
    $productId = DB::table('products')->value('id');
    if ($productId) {
        DB::table('product_activities')->insert(['activity_id' => $this->old['activity_id'], 'product_id' => $productId]);
    }

    $from = $this->day->copy()->setTime(15, 0);
    $newId = rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30))['new_activity_id'];

    expect(DB::table('activities')->where('id', $newId)->value('comment'))->toBe('Dolor de muela')
        ->and((int) leadOf($newId)->user_id)->toBe((int) $receptionist)
        ->and(additionalOf($newId))->toMatchArray(['booked_by_person_id' => 4242, 'source' => 'whatsapp_flow']);

    if ($productId) {
        expect(DB::table('product_activities')->where('activity_id', $newId)->value('product_id'))->toBe($productId);
    }
});

it('aplica lo editado (overrides) sobre la nueva', function () {
    $from = $this->day->copy()->setTime(15, 0);

    $newId = rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30), ['reason' => 'Control de ortodoncia'])['new_activity_id'];

    expect(DB::table('activities')->where('id', $newId)->value('comment'))->toBe('Control de ortodoncia');
});

it('si SMD rechaza la nueva, la vieja queda intacta y no se crea nada', function () {
    $this->smd['createEvent'] = Http::response(['message' => 'boom'], 500);
    $antes = DB::table('activities')->where('type', 'meeting')->count();
    $from = $this->day->copy()->setTime(15, 0);

    expect(fn () => rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30)))
        ->toThrow(AppointmentException::class);

    $oldLead = leadOf($this->old['activity_id']);
    expect((int) $oldLead->lead_pipeline_stage_id)->toBe((int) config('smd.stage_map.confirmed'))
        ->and((string) $oldLead->status)->not->toBe('0')
        ->and(patchesTo($this->oldEventId))->toBe([])
        ->and(DB::table('activities')->where('type', 'meeting')->count())->toBe($antes)
        ->and(additionalOf($this->old['activity_id']))->not->toHaveKey('rescheduled_to');
});

it('superpuesta con la vieja: la cancela primero y crea la nueva', function () {
    // 09:15 pisa la vieja (09:00-09:30): sin cancelarla antes, el conflicto
    // local (y SMD) verían ocupado el horario.
    $from = $this->day->copy()->setTime(9, 15);

    $newId = rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30))['new_activity_id'];

    expect(Carbon::parse(DB::table('activities')->find($newId)->schedule_from)->format('H:i'))->toBe('09:15')
        ->and((int) leadOf($this->old['activity_id'])->lead_pipeline_stage_id)->toBe((int) config('smd.stage_map.cancelled'));
});

it('superpuesta y SMD rechaza la nueva: la vieja vuelve a como estaba, también en SMD', function () {
    $this->smd['createEvent'] = Http::response(['message' => 'boom'], 500);
    $from = $this->day->copy()->setTime(9, 15);

    expect(fn () => rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30)))
        ->toThrow(AppointmentException::class);

    $oldLead = leadOf($this->old['activity_id']);
    $patches = patchesTo($this->oldEventId);

    expect((int) $oldLead->lead_pipeline_stage_id)->toBe((int) config('smd.stage_map.confirmed'))
        ->and((string) $oldLead->status)->not->toBe('0')
        ->and($oldLead->lost_reason)->toBeNull()
        ->and($oldLead->closed_at)->toBeNull()
        ->and(collect($patches)->pluck('status')->all())
        ->toBe([config('smd.smd_status.cancelled'), config('smd.smd_status.confirmed')]);
});

it('no reprograma una cita cancelada', function () {
    DB::table('leads')->where('id', $this->old['lead_id'])->update(['lead_pipeline_stage_id' => (int) config('smd.stage_map.cancelled'), 'status' => 0]);
    $from = $this->day->copy()->setTime(15, 0);

    expect(fn () => rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30)))
        ->toThrow(AppointmentException::class, 'cancelada');
});

/*
| Recordatorios (D5): la nueva recibe el suyo, la vieja no.
*/

it('el recordatorio automático va a la nueva y no a la vieja cancelada', function () {
    $this->patient->update(['contact_numbers' => [['value' => '59171234567', 'label' => 'work']]]);
    $from = $this->day->copy()->setTime(15, 0);
    rescheduler()->reschedule($this->old['activity_id'], $from, $from->copy()->addMinutes(30));

    test()->artisan('whatsapp:appointment-reminders', [
        '--date' => $this->day->toDateString(), '--person' => $this->patient->id, '--dry-run' => true,
    ])->expectsOutputToContain('Citas para '.$this->day->toDateString().': 1')->assertSuccessful();
});

it('una cita cancelada (por cualquier vía) no recibe recordatorio', function () {
    $this->patient->update(['contact_numbers' => [['value' => '59171234567', 'label' => 'work']]]);
    DB::table('leads')->where('id', $this->old['lead_id'])->update(['lead_pipeline_stage_id' => (int) config('smd.stage_map.cancelled'), 'status' => 0]);

    test()->artisan('whatsapp:appointment-reminders', [
        '--date' => $this->day->toDateString(), '--person' => $this->patient->id, '--dry-run' => true,
    ])->expectsOutputToContain('Sin citas pendientes de recordatorio')->assertSuccessful();
});
