<?php

/*
|--------------------------------------------------------------------------
| Indicador de recordatorio en el calendario (planning/17, D10)
|--------------------------------------------------------------------------
| Cada cita del calendario dice si se le envió el recordatorio de WhatsApp y
| en qué quedó: en cola, enviado, entregado, leído, falló, o qué respondió el
| paciente. Se deriva de whatsapp_appointment_reminders + el estado que Meta
| reporta en whatsapp_messages, sin columnas nuevas.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

uses(DatabaseTransactions::class);

beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->day = Carbon::now(config('app.timezone'))->addDays(40)->startOfDay();
    $this->doctorId = auditDoctor(['name' => 'Dra Indicador '.uniqid()]);
    $this->patient = auditPerson(['name' => 'Paciente Indicador '.uniqid()]);
    $this->conversation = Conversation::create([
        'gateway'  => 'cloud_api',
        'wa_phone' => '5917'.rand(1000000, 9999999),
        'status'   => 'open',
    ]);
});

/** Recordatorio enviado para la cita, con el estado de su mensaje. */
function indicatorReminder(int $activityId, string $status, array $extra = []): int
{
    $message = Message::create([
        'conversation_id' => test()->conversation->id,
        'direction'       => 'outbound',
        'type'            => 'template',
        'body'            => 'Recordatorio',
        'status'          => $status,
        'error'           => $extra['error'] ?? null,
        'sender'          => 'system',
    ]);

    return DB::table('whatsapp_appointment_reminders')->insertGetId([
        'activity_id'     => $activityId,
        'external_id'     => $extra['external_id'] ?? null,
        'conversation_id' => test()->conversation->id,
        'message_id'      => $message->id,
        'sent_at'         => $extra['sent_at'] ?? now(),
        'response'        => $extra['response'] ?? null,
        'responded_at'    => isset($extra['response']) ? now() : null,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

/** La cita tal como la devuelve el endpoint del calendario. */
function calendarAppointment(int $activityId, Carbon $day): array
{
    $json = test()->getJson(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $day->toDateString(),
    ]))->assertOk()->json('appointments');

    return collect($json)->firstWhere('id', $activityId) ?? [];
}

it('una cita sin recordatorio dice "none"', function () {
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));

    expect(calendarAppointment($cita['activity_id'], $this->day))
        ->toMatchArray(['reminder_status' => 'none', 'reminder_sent_at' => null]);
});

it('refleja el estado del mensaje que reporta Meta', function (string $messageStatus) {
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));
    indicatorReminder($cita['activity_id'], $messageStatus, ['sent_at' => $this->day->copy()->subDay()->setTime(8, 0)]);

    $appt = calendarAppointment($cita['activity_id'], $this->day);

    expect($appt['reminder_status'])->toBe($messageStatus)
        ->and($appt['reminder_sent_at'])->toStartWith($this->day->copy()->subDay()->toDateString());
})->with(['queued', 'sent', 'delivered', 'read']);

it('un recordatorio que falló trae el motivo', function () {
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));
    indicatorReminder($cita['activity_id'], 'failed', ['error' => '(#132018) Param text cannot have new-line']);

    expect(calendarAppointment($cita['activity_id'], $this->day))->toMatchArray([
        'reminder_status' => 'failed',
        'reminder_error'  => '(#132018) Param text cannot have new-line',
    ]);
});

it('la respuesta del paciente gana sobre el estado de entrega', function (string $response, string $expected) {
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));
    indicatorReminder($cita['activity_id'], 'read', ['response' => $response]);

    expect(calendarAppointment($cita['activity_id'], $this->day)['reminder_status'])->toBe($expected);
})->with([
    'confirmó'  => ['confirma', 'confirmed'],
    'no asiste' => ['no_asiste', 'declined'],
]);

it('una cita re-sincronizada por SMD (otro activity_id, mismo evento) muestra el recordatorio de la original', function () {
    $externalId = 'evt-indicador-'.uniqid();
    $original = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(9, 0));
    indicatorReminder($original['activity_id'], 'delivered', ['external_id' => $externalId]);

    // La sync recreó la cita: otra actividad, mismo evento de SMD.
    $recreada = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0), $externalId);

    expect(calendarAppointment($recreada['activity_id'], $this->day)['reminder_status'])->toBe('delivered');
});

it('no hace una consulta por cita (sin N+1)', function () {
    $queriesFor = function (int $count): int {
        $day = $this->day->copy()->addDays($count); // un día propio por corrida

        for ($i = 0; $i < $count; $i++) {
            $cita = auditAppointment($this->doctorId, $this->patient->id, $day->copy()->setTime(8, 0)->addMinutes(30 * $i));
            indicatorReminder($cita['activity_id'], 'sent');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        calendarAppointment(0, $day);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    // La primera llamada carga cosas una sola vez (no por cita): se descarta.
    calendarAppointment(0, $this->day->copy()->subDays(5));

    expect($queriesFor(12))->toBe($queriesFor(2));
});

it('el calendario pinta el indicador accesible en la tarjeta y la línea en el detalle', function () {
    $html = test()->get(route('admin.activities.index'))->assertOk()->getContent();

    expect($html)->toContain('reminderInfo(')
        ->and($html)->toContain('ddc-reminder-indicator')
        ->and($html)->toContain(':aria-label="reminderInfo(');
});

/*
| Recordatorio manual (botón "Enviar recordatorio por WhatsApp" del detalle)
*/

it('el recordatorio manual queda registrado para la cita y el indicador lo muestra', function () {
    Illuminate\Support\Facades\Queue::fake();
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));

    test()->postJson(route('admin.whatsapp.reminders.send'), [
        'phone'       => '59171234567',
        'person_id'   => $this->patient->id,
        'activity_id' => $cita['activity_id'],
        'text'        => 'Le recordamos su cita',
    ])->assertOk();

    $row = DB::table('whatsapp_appointment_reminders')->where('activity_id', $cita['activity_id'])->first();

    expect($row)->not->toBeNull()
        ->and($row->message_id)->not->toBeNull()
        ->and(calendarAppointment($cita['activity_id'], $this->day)['reminder_status'])->toBe('queued');
});

it('tras un recordatorio manual, el automático no se manda de nuevo a esa cita', function () {
    Illuminate\Support\Facades\Queue::fake();
    $this->patient->update(['contact_numbers' => [['value' => '59171234567', 'label' => 'work']]]);
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));

    test()->postJson(route('admin.whatsapp.reminders.send'), [
        'phone' => '59171234567', 'person_id' => $this->patient->id, 'activity_id' => $cita['activity_id'], 'text' => 'Recordatorio',
    ])->assertOk();

    test()->artisan('whatsapp:appointment-reminders', [
        '--date' => $this->day->toDateString(), '--person' => $this->patient->id, '--dry-run' => true,
    ])->expectsOutputToContain('Sin citas pendientes de recordatorio')->assertSuccessful();
});

it('si el recordatorio manual falló, el automático lo vuelve a intentar', function () {
    $this->patient->update(['contact_numbers' => [['value' => '59171234567', 'label' => 'work']]]);
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));
    indicatorReminder($cita['activity_id'], 'failed');

    test()->artisan('whatsapp:appointment-reminders', [
        '--date' => $this->day->toDateString(), '--person' => $this->patient->id, '--dry-run' => true,
    ])->expectsOutputToContain('(59171234567)')->assertSuccessful();
});

it('un envío manual sin cita (desde otro lado) no crea registro de recordatorio', function () {
    Illuminate\Support\Facades\Queue::fake();
    $antes = DB::table('whatsapp_appointment_reminders')->count();

    test()->postJson(route('admin.whatsapp.reminders.send'), [
        'phone' => '59171234567', 'text' => 'Hola',
    ])->assertOk();

    expect(DB::table('whatsapp_appointment_reminders')->count())->toBe($antes);
});

it('el calendario manda el id de la cita al enviar el recordatorio manual', function () {
    expect(test()->get(route('admin.activities.index'))->assertOk()->getContent())
        ->toContain('activity_id: ev.id');
});

/*
| El calendario se entera cuando Meta cambia el estado del recordatorio
*/

function calendarVersion(Carbon $day): string
{
    return test()->getJson(route('admin.activities.version', [
        'calendar_view' => 'day',
        'start'         => $day->toDateString(),
    ]))->assertOk()->json('version');
}

it('la firma del calendario cambia cuando Meta actualiza el estado del recordatorio', function () {
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));
    $reminderId = indicatorReminder($cita['activity_id'], 'sent');
    $antes = calendarVersion($this->day);

    // El webhook de Meta: el mismo mensaje pasa a "leído" un rato después.
    $this->travel(20)->seconds();
    $messageId = DB::table('whatsapp_appointment_reminders')->where('id', $reminderId)->value('message_id');
    Message::find($messageId)->update(['status' => 'read']);

    expect(calendarVersion($this->day))->not->toBe($antes);
});

it('la firma cambia cuando se manda un recordatorio a una cita del rango', function () {
    $cita = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(10, 0));
    $antes = calendarVersion($this->day);

    $this->travel(20)->seconds();
    indicatorReminder($cita['activity_id'], 'queued');

    expect(calendarVersion($this->day))->not->toBe($antes);
});

it('al recargar la grilla, el detalle abierto toma la cita actualizada (por id, no por objeto)', function () {
    $html = test()->get(route('admin.activities.index'))->assertOk()->getContent();

    expect($html)->toContain('refreshOpenAppointment()')
        ->and($html)->toContain('a.id === this.viewAppointment.data.id');
});
