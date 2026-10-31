<?php

/*
|--------------------------------------------------------------------------
| Reprogramar desde el calendario del CRM (planning/17, D7–D9)
|--------------------------------------------------------------------------
| GET  …/appointments/{id}/reschedule-slots → horarios libres reales del doctor
| POST …/appointments/{id}/reschedule       → cancela la cita y crea una nueva,
|                                              y avisa al paciente por WhatsApp
| Aviso: texto si la ventana de 24 h está abierta; fuera de ella, plantilla
| solo con WHATSAPP_RESCHEDULE_TEMPLATE_ENABLED.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;

uses(DatabaseTransactions::class);

beforeEach(function () {
    test()->actingAs(getDefaultAdmin());
    $this->smd = auditBootFakes();
    Queue::fake([SendMessage::class]);
    config([
        'whatsapp.reschedule.template_enabled' => false,
        'whatsapp.outbound_gateway'            => 'cloud_api',
    ]);

    $this->day = Carbon::now(config('app.timezone'))->addDays(30)->startOfDay();
    $this->doctorId = auditDoctor(['name' => 'Dra Calendario '.uniqid()]);
    $this->doctorUid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    auditShift($this->doctorId, $this->day, '07:30', '12:00');
    $this->smd['availability'] = auditSmdAvailability($this->doctorUid, $this->day->copy()->setTime(7, 30), $this->day->copy()->setTime(12, 0));

    $this->patient = auditPerson([
        'name'            => 'Maria Reprogramada '.uniqid(),
        'smd_patient_id'  => bin2hex(random_bytes(12)),
        'contact_numbers' => [['value' => '7'.rand(1000000, 9999999), 'label' => 'work']],
    ]);
    $this->oldEventId = 'evt-crm-'.uniqid();
    $this->old = auditAppointment($this->doctorId, $this->patient->id, $this->day->copy()->setTime(9, 0), $this->oldEventId);
    $this->smd['createEvent'] = auditCreateEventResponse('evt-crm-nueva-'.uniqid());
});

function slotsFor(int $activityId, Carbon $from): array
{
    return test()->getJson(route('admin.activities.appointments.reschedule_slots', ['id' => $activityId, 'from' => $from->toDateString()]))
        ->assertOk()->json();
}

function rescheduleTo(int $activityId, Carbon $day, string $time)
{
    return test()->postJson(route('admin.activities.appointments.reschedule', ['id' => $activityId]), [
        'date' => $day->toDateString(),
        'time' => $time,
    ]);
}

/** Conversación de WhatsApp de la persona con su último mensaje entrante. */
function rescheduleConversationOf(int $personId, ?Carbon $lastInbound): Conversation
{
    return Conversation::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '5917'.rand(1000000, 9999999),
        'status'          => 'open',
        'person_id'       => $personId,
        'last_inbound_at' => $lastInbound,
    ]);
}

function rescheduleOutboundOf(int $conversationId)
{
    return DB::table('whatsapp_messages')->where('conversation_id', $conversationId)->where('direction', 'outbound')->get();
}

/*
| Horarios libres (D7)
*/

it('ofrece los horarios libres del doctor desde el inicio del turno, sin el horario actual', function () {
    $res = slotsFor($this->old['activity_id'], $this->day);
    $day = collect($res['days'])->firstWhere('date', $this->day->toDateString());

    // Turno 07:30-12:00, cita actual 09:00-09:30 (30 min): se ofrece en pasos de
    // la duración de la cita, contados desde el inicio de cada hueco.
    expect($res['failed'])->toBeFalse()
        ->and($res['duration'])->toBe(30)
        ->and($day['times'])->toBe(['07:30', '08:00', '08:30', '09:30', '10:00', '10:30', '11:00', '11:30'])
        ->and($res['days'])->toHaveCount(7)
        ->and(collect($res['days'])->where('date', '!=', $this->day->toDateString())->pluck('times')->flatten()->all())->toBe([]);
});

it('con SMD caído no ofrece horarios sin validar', function () {
    $this->smd['availability'] = Http::response(['message' => 'down'], 503);

    $res = slotsFor($this->old['activity_id'], $this->day);

    expect($res['failed'])->toBeTrue()
        ->and(collect($res['days'])->pluck('times')->flatten()->all())->toBe([]);
});

it('dice de antemano cómo se avisará al paciente', function (?int $hoursAgo, bool $flag, $expected) {
    config(['whatsapp.reschedule.template_enabled' => $flag]);
    rescheduleConversationOf($this->patient->id, $hoursAgo === null ? null : now()->subHours($hoursAgo));

    expect(slotsFor($this->old['activity_id'], $this->day)['notify'])->toBe($expected);
})->with([
    'escribió hace 2 h'               => [2, false, 'text'],
    'hace 30 h, bandera apagada'      => [30, false, false],
    'hace 30 h, bandera encendida'    => [30, true, 'template'],
    'nunca escribió, bandera apagada' => [null, false, false],
]);

/*
| Reprogramar (POST)
*/

it('reprograma: cancela la vieja, crea la nueva y responde con las dos', function () {
    $res = rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json();

    $newId = $res['new_activity_id'];
    expect(Carbon::parse(DB::table('activities')->find($newId)->schedule_from)->format('H:i'))->toBe('10:30')
        ->and(Carbon::parse(DB::table('activities')->find($newId)->schedule_to)->format('H:i'))->toBe('11:00')
        ->and((int) DB::table('leads')->where('id', $this->old['lead_id'])->value('lead_pipeline_stage_id'))->toBe((int) config('smd.stage_map.cancelled'))
        ->and($res['message'])->toContain('cancelada')->toContain('10:30');
});

it('un horario que ya no está libre da 422 y la vieja queda intacta', function () {
    $this->smd['availability'] = [];

    rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertStatus(422);

    expect((int) DB::table('leads')->where('id', $this->old['lead_id'])->value('lead_pipeline_stage_id'))->toBe((int) config('smd.stage_map.confirmed'))
        ->and(rescheduleAdditionalOf($this->old['activity_id']))->not->toHaveKey('rescheduled_to');
});

it('valida fecha y hora', function () {
    test()->postJson(route('admin.activities.appointments.reschedule', ['id' => $this->old['activity_id']]), ['date' => 'mañana', 'time' => '25:00'])
        ->assertStatus(422)->assertJsonValidationErrors(['date', 'time']);
});

function rescheduleAdditionalOf(int $activityId): array
{
    return json_decode((string) DB::table('activities')->where('id', $activityId)->value('additional'), true) ?: [];
}

/*
| Aviso al paciente (D8 / D9)
*/

it('dentro de la ventana de 24 h avisa con un texto con la fecha nueva', function () {
    $conv = rescheduleConversationOf($this->patient->id, now()->subHours(2));

    $res = rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json();
    $sent = rescheduleOutboundOf($conv->id);

    expect($res['notified'])->toBe('text')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->type)->toBe('text')
        ->and($sent[0]->sender)->toBe('system')
        ->and($sent[0]->body)->toContain('reprogramada')->toContain('10:30')->toContain('Dra Calendario');
    Queue::assertPushed(SendMessage::class, 1);
});

it('fuera de la ventana y con la bandera apagada no avisa', function (?int $hoursAgo) {
    $conv = rescheduleConversationOf($this->patient->id, $hoursAgo === null ? null : now()->subHours($hoursAgo));

    $res = rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json();

    expect($res['notified'])->toBeFalse()
        ->and(rescheduleOutboundOf($conv->id))->toHaveCount(0)
        ->and($res['message'])->toContain('No se avisó');
    Queue::assertNotPushed(SendMessage::class);
})->with(['hace 30 h' => [30], 'nunca escribió' => [null]]);

it('sin conversación y con la bandera apagada no avisa ni crea conversación', function () {
    $antes = Conversation::count();

    expect(rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json('notified'))->toBeFalse()
        ->and(Conversation::count())->toBe($antes);
});

it('con la bandera encendida, fuera de la ventana avisa con la plantilla por la Cloud API', function () {
    config(['whatsapp.reschedule.template_enabled' => true]);
    $conv = rescheduleConversationOf($this->patient->id, now()->subHours(30));

    $res = rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json();
    $sent = rescheduleOutboundOf($conv->id);
    $payload = json_decode($sent[0]->payload, true);

    expect($res['notified'])->toBe('template')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->type)->toBe('template')
        ->and($payload['template'])->toBe('appointment_rescheduled')
        ->and($payload['variables'])->toHaveCount(3)
        ->and($payload['variables'][0])->toBe(trim($this->patient->name))
        ->and($payload['variables'][1])->toEndWith('a horas 10:30')
        ->and($payload['variables'][2])->toStartWith('Dra Calendario');
    Queue::assertPushed(SendMessage::class, fn ($job) => $job->gatewayOverride === 'cloud_api');
});

it('con la bandera encendida y la ventana abierta sigue saliendo texto', function () {
    config(['whatsapp.reschedule.template_enabled' => true]);
    $conv = rescheduleConversationOf($this->patient->id, now()->subHours(1));

    expect(rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json('notified'))->toBe('text')
        ->and(rescheduleOutboundOf($conv->id)[0]->type)->toBe('text');
});

it('con la bandera encendida y sin conversación, la crea y manda la plantilla', function () {
    config(['whatsapp.reschedule.template_enabled' => true]);

    expect(rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json('notified'))->toBe('template');
    Queue::assertPushed(SendMessage::class, 1);
});

it('una cita agendada por un tercero avisa a quien agendó', function () {
    $mama = auditPerson(['name' => 'Mama Aviso '.uniqid()]);
    DB::table('activities')->where('id', $this->old['activity_id'])->update(['additional' => json_encode(['booked_by_person_id' => $mama->id])]);
    $convMama = rescheduleConversationOf($mama->id, now()->subHours(1));
    $convPaciente = rescheduleConversationOf($this->patient->id, now()->subHours(1));

    rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk();

    expect(rescheduleOutboundOf($convMama->id))->toHaveCount(1)
        ->and(rescheduleOutboundOf($convMama->id)[0]->body)->toContain(trim($this->patient->name))
        ->and(rescheduleOutboundOf($convPaciente->id))->toHaveCount(0);
});

it('la plantilla cita_reprogramada está definida como UTILITY con 3 variables', function () {
    $template = require base_path('packages/Webkul/Whatsapp/src/Config/templates.php');

    expect($template)->toHaveKey('cita_reprogramada')
        ->and($template['cita_reprogramada']['category'])->toBe('UTILITY')
        ->and($template['cita_reprogramada']['examples'])->toHaveCount(3)
        ->and($template['cita_reprogramada']['body'])->toContain('{{1}}')->toContain('{{2}}')->toContain('{{3}}')
        ->and(config('whatsapp.gateways.cloud_api.templates.appointment_rescheduled.name'))->toBe(env('WHATSAPP_RESCHEDULE_TEMPLATE_NAME', 'cita_reprogramada'));
});

/*
| Vínculo entre la vieja y la nueva (D6)
*/

it('el historial del paciente vincula la vieja y la nueva con su fecha y hora', function () {
    $newId = rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json('new_activity_id');

    $rows = collect(test()->getJson(route('admin.activities.persons.appointments', $this->patient->id))->assertOk()->json('appointments'))->keyBy('id');

    expect($rows[$this->old['activity_id']]['state'])->toBe('cancelled')
        ->and($rows[$this->old['activity_id']]['rescheduled_to'])->toBe(['id' => $newId, 'date' => $this->day->format('d/m/Y'), 'date_iso' => $this->day->toDateString(), 'time' => '10:30'])
        ->and($rows[$newId]['rescheduled_from'])->toBe(['id' => $this->old['activity_id'], 'date' => $this->day->format('d/m/Y'), 'date_iso' => $this->day->toDateString(), 'time' => '09:00'])
        ->and($rows[$newId]['rescheduled_to'])->toBeNull();
});

it('el calendario trae el vínculo en cada cita', function () {
    $newId = rescheduleTo($this->old['activity_id'], $this->day, '10:30')->assertOk()->json('new_activity_id');

    $appointments = collect(test()->getJson(route('admin.activities.get', [
        'view_type' => 'calendar', 'calendar_mode' => 'doctor', 'calendar_view' => 'day', 'start' => $this->day->toDateString(),
    ]))->assertOk()->json('appointments'))->keyBy('id');

    expect($appointments[$newId]['rescheduled_from']['time'] ?? null)->toBe('09:00')
        ->and($appointments[$this->old['activity_id']]['rescheduled_to']['time'] ?? null)->toBe('10:30');
});

/*
| UI del calendario (render del Blade, como el resto de la suite)
*/

function rescheduleCalendarHtml(): string
{
    return test()->get(route('admin.activities.index'))->assertOk()->getContent();
}

it('el detalle tiene el botón Reprogramar solo para citas futuras no canceladas, y la sección con sus estados', function () {
    $html = rescheduleCalendarHtml();

    expect($html)->toContain('v-if="canReschedule()"')
        ->and($html)->toContain('@click="openReschedule"')
        ->and($html)->toContain(route('admin.activities.appointments.reschedule_slots', 'replaceId'))
        ->and($html)->toContain(route('admin.activities.appointments.reschedule', 'replaceId'))
        // Estados: cargando, sin horarios, SMD sin respuesta.
        ->and($html)->toContain('aria-busy="true"')
        ->and($html)->toContain('Sin horarios libres con')
        ->and($html)->toContain('No pudimos consultar la agenda de SMD')
        // Horarios como radiogroup accesible; días sin horarios con texto, no solo gris.
        ->and($html)->toContain('role="radiogroup"')
        ->and($html)->toContain('sin horarios');
});

it('el detalle muestra el vínculo con la otra cita como botón', function () {
    $html = rescheduleCalendarHtml();

    expect($html)->toContain('Reprogramada desde')
        ->and($html)->toContain('Reprogramada al')
        ->and($html)->toContain('openHistoryAppointment(viewAppointment.data.rescheduled_from)')
        ->and($html)->toContain('openHistoryAppointment(viewAppointment.data.rescheduled_to)');
});

it('en Editar la fecha y la hora quedan bloqueadas y remiten a Reprogramar', function () {
    $html = rescheduleCalendarHtml();

    expect($html)->toContain('Para cambiar la fecha o la hora usá «Reprogramar»')
        // El campo de fecha queda solo en "Nueva cita", no en "Editar".
        ->and(substr_count($html, 'aria-label="Fecha de la cita"'))->toBe(1);
});
