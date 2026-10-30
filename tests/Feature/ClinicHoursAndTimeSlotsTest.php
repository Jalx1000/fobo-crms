<?php

/*
|--------------------------------------------------------------------------
| Horario de atención, franjas y validación del prefill (pedido del agente, 2026-10-29)
|--------------------------------------------------------------------------
| 1. "Turno sugerido" sin hora: el más próximo de mañana, mediodía y tarde.
| 2. time_slot (manana / mediodia / tarde): esa franja primero y marcada.
| 3. Hora fuera del horario de atención: se ignora.
| 4. Fecha pasada, domingo o feriado: se ignora.
| 5. `clinica.ahora` en hora de Bolivia, con el día de la semana.
| 7. `fuera_de_horario` lo calcula el CRM, no el modelo.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\ClinicHours;
use Webkul\Whatsapp\Services\Flows\FlowPrefill;
use Webkul\Whatsapp\Services\Flows\FlowSession;
use Webkul\Whatsapp\Services\Flows\PatientFlow;

beforeEach(function () {
    // Jueves 29/10/2026 10:53 en Bolivia.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:53', 'America/La_Paz'));
    config(['whatsapp.clinic.holidays' => '2026-11-02']);
});

function slotScreen(array $slots, string $from, ?string $wanted, string $token): ?array
{
    return (function () use ($slots, $from, $wanted, $token) {
        return $this->slotOptionsScreen($token, 0, $slots, $from, $wanted);
    })->call(app(PatientFlow::class));
}

/*
| Horario
*/

it('el horario de atención sigue el reloj de Bolivia: semana, sábado, domingo y feriado', function () {
    expect(ClinicHours::isClosed())->toBeFalse()
        ->and(ClinicHours::isClosed(CarbonImmutable::parse('2026-10-29 19:00', 'America/La_Paz')))->toBeTrue()
        ->and(ClinicHours::isClosed(CarbonImmutable::parse('2026-10-31 11:00', 'America/La_Paz')))->toBeFalse()
        ->and(ClinicHours::isClosed(CarbonImmutable::parse('2026-10-31 15:00', 'America/La_Paz')))->toBeTrue()
        ->and(ClinicHours::isClosed(CarbonImmutable::parse('2026-11-01 10:00', 'America/La_Paz')))->toBeTrue()
        ->and(ClinicHours::isClosed(CarbonImmutable::parse('2026-11-02 10:00', 'America/La_Paz')))->toBeTrue();
});

it('las franjas: mañana hasta 11:59, mediodía hasta 13:59, tarde hasta 18:30', function () {
    expect(ClinicHours::slotOf('07:30'))->toBe('manana')
        ->and(ClinicHours::slotOf('11:59'))->toBe('manana')
        ->and(ClinicHours::slotOf('12:00'))->toBe('mediodia')
        ->and(ClinicHours::slotOf('14:00'))->toBe('tarde')
        ->and(ClinicHours::slotOf('18:45'))->toBeNull();
});

/*
| 5 y 7 — contexto del agente y derivación
*/

it('el evento del agente trae la hora de Bolivia con el día y si está fuera de horario', function () {
    $conv = flowConversation(auditPerson()->id);
    $message = Message::create(['conversation_id' => $conv->id, 'direction' => 'inbound', 'type' => 'text', 'body' => 'hola', 'status' => 'delivered']);

    $clinica = app(AgentPayload::class)->event($message)['clinica'];

    expect($clinica)->toMatchArray([
        'ahora'            => 'jueves 29/10/2026 10:53',
        'zona_horaria'     => 'America/La_Paz',
        'fuera_de_horario' => false,
        'horario_hoy'      => '07:30 a 18:30',
    ])->and($clinica['ahora_iso'])->toStartWith('2026-10-29T10:53:00-04:00');
});

it('la derivación usa el fuera_de_horario del CRM, no el que manda el agente', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 23:00', 'America/La_Paz'));
    $conv = flowConversation(auditPerson()->id);
    actingAsSanctumAuthenticatedAdmin();

    $this->postJson("/api/v1/whatsapp/conversations/{$conv->id}/handoff", ['motivo' => 'precio', 'fuera_de_horario' => false])
        ->assertOk()
        ->assertJsonPath('handoff.fuera_de_horario', true);
});

/*
| 2, 3 y 4 — prefill
*/

it('una hora fuera del horario de atención se ignora (23:00, 05:00, 15:00 un sábado)', function () {
    expect(FlowPrefill::sanitize(['time' => '23:00']))->not->toHaveKey('time')
        ->and(FlowPrefill::sanitize(['time' => '05:00']))->not->toHaveKey('time')
        ->and(FlowPrefill::sanitize(['date' => '2026-10-31', 'time' => '15:00']))->toBe(['date' => '2026-10-31'])
        ->and(FlowPrefill::sanitize(['date' => '2026-10-30', 'time' => '15:00']))->toBe(['date' => '2026-10-30', 'time' => '15:00']);
});

it('una fecha pasada, un domingo o un feriado se ignoran', function () {
    expect(FlowPrefill::sanitize(['date' => '2026-10-28']))->toBe([])
        ->and(FlowPrefill::sanitize(['date' => '2026-11-01']))->toBe([])
        ->and(FlowPrefill::sanitize(['date' => '2026-11-02']))->toBe([])
        ->and(FlowPrefill::sanitize(['date' => '2026-10-29']))->toBe(['date' => '2026-10-29']);
});

it('time_slot acepta manana, mediodia y tarde (con tildes o mayúsculas) y descarta el resto', function () {
    expect(FlowPrefill::sanitize(['time_slot' => 'Tarde']))->toBe(['time_slot' => 'tarde'])
        ->and(FlowPrefill::sanitize(['time_slot' => 'mañana']))->toBe(['time_slot' => 'manana'])
        ->and(FlowPrefill::sanitize(['time_slot' => 'Mediodía']))->toBe(['time_slot' => 'mediodia'])
        ->and(FlowPrefill::sanitize(['time_slot' => 'noche']))->toBe([]);
});

it('en la lista de horas, una franja pedida marca su primer horario', function () {
    expect(FlowPrefill::time(['time_slot' => 'tarde'], ['09:00', '12:30', '15:00', '16:00']))->toBe('15:00')
        ->and(FlowPrefill::time(['time_slot' => 'tarde'], ['09:00', '10:00']))->toBe('09:00');
});

/*
| 1 y 2 — "Turno sugerido" por franja
*/

it('sin hora: el más próximo de cada franja, buscando en los días siguientes si un día no tiene', function () {
    $slots = ['2026-10-30' => ['08:00', '09:00', '15:00'], '2026-10-31' => ['09:00'], '2026-11-03' => ['12:30']];

    $screen = slotScreen($slots, '2026-10-30', null, 'tok-franjas-1');

    expect($screen['screen'])->toBe('NEXT_SLOT')
        ->and(collect($screen['data']['options'])->pluck('id')->all())->toBe(['slot:manana', 'slot:mediodia', 'slot:tarde', 'other'])
        ->and(collect($screen['data']['options'])->pluck('title')->take(3)->all())->toBe([
            'Mañana · Vie 30/10 08:00', 'Mediodía · Mar 03/11 12:30', 'Tarde · Vie 30/10 15:00',
        ])
        ->and($screen['data']['init_choice'])->toBe('slot:manana')
        ->and($screen['data']['heading'])->toStartWith('Los turnos más próximos con')
        ->and(FlowSession::get('tok-franjas-1')['suggested_options']['slot:mediodia'])->toBe(['date' => '2026-11-03', 'time' => '12:30']);
});

it('con time_slot, esa franja va primero y marcada', function () {
    $slots = ['2026-10-30' => ['08:00', '15:00'], '2026-11-03' => ['12:30']];

    $screen = slotScreen($slots, '2026-10-30', 'tarde', 'tok-franjas-2');

    expect($screen['data']['options'][0]['id'])->toBe('slot:tarde')
        ->and($screen['data']['init_choice'])->toBe('slot:tarde')
        ->and($screen['data']['heading'])->toStartWith('El turno más próximo a la tarde con');
});

it('si la franja pedida no tiene turnos, se ofrecen las otras y se marca la más próxima', function () {
    $screen = slotScreen(['2026-10-31' => ['09:00', '10:00']], '2026-10-30', 'tarde', 'tok-franjas-3');

    expect(collect($screen['data']['options'])->pluck('id')->all())->toBe(['slot:manana', 'other'])
        ->and($screen['data']['init_choice'])->toBe('slot:manana')
        ->and($screen['data']['heading'])->toStartWith('Los turnos más próximos con');
});

it('elegir una franja guarda su día y hora y sigue al resumen', function () {
    $slots = ['2026-10-30' => ['08:00', '15:00']];
    $token = 'tok-franjas-4';
    slotScreen($slots, '2026-10-30', null, $token);
    FlowSession::merge($token, ['doctor_slots' => $slots]);

    $flow = app(PatientFlow::class);
    (function () use ($token) {
        try {
            $this->afterNextSlot($token, ['slot_choice' => 'slot:tarde']);
        } catch (\Throwable) {
            // El resumen necesita paciente y doctor reales: acá solo importa lo elegido.
        }
    })->call($flow);

    expect(FlowSession::get($token))->toMatchArray(['date' => '2026-10-30', 'time' => '15:00']);
});
