<?php

/*
|--------------------------------------------------------------------------
| Formulario de WhatsApp: agenda (especialidad → doctor → día → hora → cita)
|--------------------------------------------------------------------------
| De punta a punta contra el endpoint, simulando a Meta y a SMD.
| planning/14-pacientes-terceros/00.diseno.md
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\BookingOptions;
use Webkul\Whatsapp\Services\Flows\FlowSession;
use Webkul\Whatsapp\Services\Flows\FlowToken;

beforeEach(function () {
    if (trim((string) shell_exec('command -v openssl')) === '') {
        $this->markTestSkipped('Falta el binario openssl para simular el cifrado de Meta.');
    }

    $this->smd = auditBootFakes();
    // Los mensajes al chat se guardan, pero no se mandan a Meta desde los tests.
    \Illuminate\Support\Facades\Queue::fake([\Webkul\Whatsapp\Jobs\SendMessage::class]);
    [$this->publicPem, $this->keyPath] = bootFlowEndpoint();
    config([
        // El orden respecto del agente se prueba en FlowReplyOrderTest.
        'whatsapp.flows.confirm_wait_seconds'        => 0,
        'whatsapp.flows.booking.days'                => 14,
        'whatsapp.flows.booking.duration_minutes'    => 60,
        'whatsapp.flows.booking.general_specialties' => ['Zq General Test'],
        // Base sin pre-llenado ni "Turno sugerido" (no depende del .env): los
        // tests de R7–R9 y del atajo las encienden explícitamente.
        'whatsapp.flows.patient.prefill'             => false,
        'whatsapp.flows.patient.prefill_schedule'    => false,
        'whatsapp.flows.patient.fast_forward'        => false,
    ]);

    // Doctor reservable propio: activo, vinculado a SMD, con su especialidad y turno mañana.
    $this->doctorId = auditDoctor(['name' => 'Dra Agenda '.uniqid()]);
    $this->doctorUid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    $this->specialtyId = DB::table('specialties')->insertGetId([
        'name' => 'Zq Especialidad '.uniqid(), 'slug' => 'zq-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('doctor_specialty')->insert(['doctor_id' => $this->doctorId, 'specialty_id' => $this->specialtyId]);

    $this->day = Carbon::tomorrow(config('app.timezone'));
    auditShift($this->doctorId, $this->day, '08:00', '12:00');

    // SMD: libre de 09:00 a 11:00 → turnos de 1 hora en punto: 09:00 y 10:00.
    $this->smd['availability'] = auditSmdAvailability($this->doctorUid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(11, 0));

    // Sin catálogo: los horarios se piden a SMD recién al elegir la especialidad
    // (1 consulta, en tests la cola es síncrona y responde al instante).

    $this->mama = auditPerson(['name' => 'Mama Agenda '.uniqid(), 'smd_patient_id' => bin2hex(random_bytes(12))]);
    $this->conv = flowConversation($this->mama->id);
    $this->token = FlowToken::issue($this->conv->id);
});

afterEach(function () {
    @unlink($this->keyPath);
});

/** Paciente = "Para mí", y devuelve la pantalla de especialidades. */
function startBooking(object $t): array
{
    $confirm = step($t->publicPem, $t->token, 'PATIENT_SELECT', ['choice' => 'self']);

    return step($t->publicPem, $t->token, 'CONFIRM', $confirm['data']);
}

it('recomienda la especialidad, el doctor y sus horarios, y crea la cita', function () {
    $specialties = startBooking($this);
    expect($specialties['screen'])->toBe('SPECIALTY')
        ->and(collect($specialties['data']['specialties'])->pluck('id'))->toContain((string) $this->specialtyId);

    $doctors = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    expect($doctors['screen'])->toBe('DOCTOR')
        ->and($doctors['data']['doctors'][0]['id'])->toBe((string) $this->doctorId)
        ->and($doctors['data']['doctors'][0]['description'])->toContain('09:00');

    $dates = step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    expect($dates['screen'])->toBe('SLOT_DATE')
        ->and(collect($dates['data']['dates'])->pluck('id')->all())->toBe([$this->day->toDateString(), 'other']);

    $times = step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    expect($times['screen'])->toBe('SLOT_TIME')
        ->and(collect($times['data']['times'])->pluck('id')->all())->toBe(['09:00', '10:00']);

    $summary = step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => '10:00']);
    expect($summary['screen'])->toBe('SUMMARY')
        ->and($summary['data']['detail'])->toContain('10:00');

    // Confirmar responde al instante ("pendiente"); la cita la crea el job
    // BookFlowAppointment (en tests la cola es síncrona, corre acá mismo).
    $done = step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si']);
    expect($done['screen'])->toBe('SUCCESS');

    $params = $done['data']['extension_message_response']['params'];
    expect($params)->toMatchArray([
        'status' => 'pending', 'patient_id' => $this->mama->id, 'doctor_id' => $this->doctorId,
        'date'   => $this->day->toDateString(), 'time' => '10:00',
    ]);

    // La cita existe, de 1 hora, con ese doctor, ese paciente y ese horario.
    $activity = DB::table('activities')
        ->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
        ->where('activity_participants.person_id', $this->mama->id)
        ->where('activities.schedule_from', $this->day->toDateString().' 10:00:00')
        ->select('activities.*')->first();
    expect($activity)->not->toBeNull()
        ->and(Carbon::parse($activity->schedule_to)->format('H:i'))->toBe('11:00')
        ->and(DB::table('doctor_activities')->where('activity_id', $activity->id)->pluck('doctor_id')->all())->toBe([$this->doctorId])
        ->and(DB::table('activity_participants')->where('activity_id', $activity->id)->pluck('person_id')->all())->toBe([$this->mama->id]);

    // Quién agendó queda registrado (el recordatorio va a esa persona).
    expect(json_decode($activity->additional, true))->toMatchArray(['booked_by_person_id' => $this->mama->id, 'source' => 'whatsapp_flow']);

    // Se creó en SMD y a quien agendó le llegó la confirmación por el chat.
    expect(auditSent('/schedule/createEvent'))->toHaveCount(1)
        ->and(FlowSession::get($this->token))->toBe([])
        ->and(DB::table('whatsapp_messages')->where('conversation_id', $this->conv->id)->orderByDesc('id')->value('body'))
        ->toContain('✅ Cita confirmada');
});

it('si SMD rechaza la cita al confirmarla, avisa por el chat y no crea nada', function () {
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => '09:00']);

    $this->smd['createEvent'] = Http::response(['message' => 'slot ocupado'], 409);
    $antes = DB::table('activities')->count();

    $res = step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si']);

    expect($res['data']['extension_message_response']['params']['status'])->toBe('pending')
        ->and(DB::table('activities')->count())->toBe($antes)
        ->and(DB::table('whatsapp_messages')->where('conversation_id', $this->conv->id)->orderByDesc('id')->value('body'))
        ->toContain('ya no está disponible');
});

it('si otro tomó el horario mientras completaba, no lo acepta al confirmar', function () {
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => '09:00']);

    app(BookingOptions::class)->takeSlot($this->doctorId, $this->day->toDateString(), '09:00');

    $res = step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si']);

    expect($res['screen'])->toBe('SUMMARY')
        ->and($res['data']['error_message'])->toContain('ocupar');
});

it('no acepta un doctor que no es de la especialidad elegida', function () {
    $otro = auditDoctor(['name' => 'Otro Doctor '.uniqid()]);
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);

    $res = step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $otro]);

    expect($res['screen'])->toBe('DOCTOR')
        ->and(FlowSession::get($this->token)['doctor_id'] ?? null)->toBeNull();
});

it('no acepta un horario que no está en la lista', function () {
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);

    $res = step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => '11:00']);

    expect($res['screen'])->toBe('SLOT_TIME')
        ->and($res['data']['error_message'])->toContain('Elegí un horario');
});

it('la lista de especialidades no consulta SMD; elegir una hace UNA consulta para todos sus doctores', function () {
    $specialties = startBooking($this);
    expect(collect($specialties['data']['specialties'])->pluck('id'))->toContain((string) $this->specialtyId)
        ->and(auditSent('/schedule/availability'))->toHaveCount(0);

    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    expect(auditSent('/schedule/availability'))->toHaveCount(1);

    // Otro paciente que elige la misma especialidad en los 5 minutos: sin consulta nueva.
    $otro = auditPerson(['name' => 'Otro Paciente '.uniqid()]);
    $tokenOtro = FlowToken::issue(flowConversation($otro->id)->id);
    $confirm = step($this->publicPem, $tokenOtro, 'PATIENT_SELECT', ['choice' => 'self']);
    step($this->publicPem, $tokenOtro, 'CONFIRM', $confirm['data']);
    step($this->publicPem, $tokenOtro, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);

    expect(auditSent('/schedule/availability'))->toHaveCount(1);
});

it('si SMD todavía no respondió, pide tocar Siguiente de nuevo y el segundo toque no vuelve a consultar', function () {
    \Illuminate\Support\Facades\Queue::fake([\Webkul\Admin\Jobs\FetchSpecialtyAvailability::class]);
    config(['whatsapp.flows.booking.wait_seconds' => 0]);
    startBooking($this);

    $res = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    expect($res['screen'])->toBe('SPECIALTY')
        ->and($res['data']['error_message'])->toContain('Estamos buscando los horarios');

    // La consulta termina en segundo plano…
    app(BookingOptions::class)->fetch($this->specialtyId, now(config('app.timezone'))->toDateString(), app(BookingOptions::class)->days());

    // …y el segundo toque la toma ya lista.
    $res = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    expect($res['screen'])->toBe('DOCTOR')
        ->and(auditSent('/schedule/availability'))->toHaveCount(1);
    \Illuminate\Support\Facades\Queue::assertPushed(\Webkul\Admin\Jobs\FetchSpecialtyAvailability::class, 1);
});

it('si SMD falla, lo dice y no avanza (y no reintenta en cada toque)', function () {
    $this->smd['availability'] = Http::response(['error' => 'down'], 500);
    startBooking($this);

    $res = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);

    expect($res['screen'])->toBe('SPECIALTY')
        ->and($res['data']['error_message'])->toContain('No pudimos traer los horarios')
        ->and(auditSent('/schedule/availability'))->toHaveCount(1);
});

it('un horario reservado no se le ofrece al siguiente paciente (sin consultar SMD de nuevo)', function () {
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => '09:00']);
    step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si']);
    // 1 al elegir la especialidad + 1 al confirmar (validación de ese horario).
    $consultas = count(auditSent('/schedule/availability'));

    $otro = auditPerson(['name' => 'Siguiente Paciente '.uniqid(), 'smd_patient_id' => bin2hex(random_bytes(12))]);
    $tokenOtro = FlowToken::issue(flowConversation($otro->id)->id);
    $confirm = step($this->publicPem, $tokenOtro, 'PATIENT_SELECT', ['choice' => 'self']);
    step($this->publicPem, $tokenOtro, 'CONFIRM', $confirm['data']);
    step($this->publicPem, $tokenOtro, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $tokenOtro, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    $times = step($this->publicPem, $tokenOtro, 'SLOT_DATE', ['date' => $this->day->toDateString()]);

    expect(collect($times['data']['times'])->pluck('id')->all())->toBe(['10:00'])
        ->and($consultas)->toBe(2)
        ->and(auditSent('/schedule/availability'))->toHaveCount($consultas);
});

it('ofrece turnos de 1 hora siempre en punto, aunque la ventana libre empiece a la media', function () {
    // Libre de 09:30 a 12:00 (y el turno local llega hasta las 12): solo 10:00 y 11:00.
    $this->smd['availability'] = auditSmdAvailability($this->doctorUid, $this->day->copy()->setTime(9, 30), $this->day->copy()->setTime(12, 0));

    $result = app(BookingOptions::class)->fetch($this->specialtyId, $this->day->toDateString(), 1);

    expect($result['slots'][$this->doctorId][$this->day->toDateString()])->toBe(['10:00', '11:00']);
});

it('"No sé / Consulta general" lleva a los doctores de odontología general', function () {
    $general = DB::table('specialties')->insertGetId([
        'name' => 'Zq General Test', 'slug' => 'zq-general-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('doctor_specialty')->insert(['doctor_id' => $this->doctorId, 'specialty_id' => $general]);

    $specialties = startBooking($this);
    expect($specialties['data']['specialties'][0])->toBe(['id' => 'general', 'title' => 'General / No sé qué necesito']);

    $doctors = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => 'general']);
    expect($doctors['screen'])->toBe('DOCTOR')
        ->and(FlowSession::get($this->token)['specialty_id'])->toBe($general);
});

it('por defecto se muestran 7 días', function () {
    config(['whatsapp.flows.booking.days' => null]);

    expect(app(BookingOptions::class)->days())->toBe(7);
});

/*
| "Otra fecha": se pregunta el día y se consulta SMD solo por ese día
*/

it('"Otra fecha" pregunta el día y consulta solo ese día', function () {
    $lejano = $this->day->copy()->addDays(20);
    auditShift($this->doctorId, $lejano, '08:00', '12:00');
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    $dates = step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    expect(collect($dates['data']['dates'])->last())->toBe(['id' => 'other', 'title' => 'Otra fecha']);

    $other = step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => 'other']);
    expect($other['screen'])->toBe('OTHER_DATE')
        ->and($other['data']['min_date'])->toBe(now(config('app.timezone'))->toDateString());

    $this->smd['availability'] = auditSmdAvailability($this->doctorUid, $lejano->copy()->setTime(9, 0), $lejano->copy()->setTime(11, 0));
    $times = step($this->publicPem, $this->token, 'OTHER_DATE', ['date' => $lejano->toDateString()]);

    $consulta = collect(auditSent('/schedule/availability'))->last();
    expect($times['screen'])->toBe('SLOT_TIME')
        ->and(collect($times['data']['times'])->pluck('id')->all())->toBe(['09:00', '10:00'])
        ->and(auditSent('/schedule/availability'))->toHaveCount(2)
        ->and(substr($consulta->data()['from'], 0, 10))->toBe($lejano->toDateString())
        ->and(substr($consulta->data()['to'], 0, 10))->toBe($lejano->toDateString());

    // Y se puede confirmar ese horario.
    $summary = step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => '10:00']);
    expect($summary['screen'])->toBe('SUMMARY');
});

it('"Otra fecha" sin horarios ese día lo dice y deja elegir otro', function () {
    $lejano = $this->day->copy()->addDays(20);
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => 'other']);

    $this->smd['availability'] = [];
    $res = step($this->publicPem, $this->token, 'OTHER_DATE', ['date' => $lejano->toDateString()]);

    expect($res['screen'])->toBe('OTHER_DATE')
        ->and($res['data']['error_message'])->toContain('No hay horarios libres');
});

it('"Otra fecha" no acepta días pasados ni más allá del máximo', function () {
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => 'other']);
    $antes = count(auditSent('/schedule/availability'));

    $pasado = step($this->publicPem, $this->token, 'OTHER_DATE', ['date' => now()->subDay()->toDateString()]);
    $lejos = step($this->publicPem, $this->token, 'OTHER_DATE', ['date' => now()->addDays(200)->toDateString()]);

    expect($pasado['screen'])->toBe('OTHER_DATE')
        ->and($lejos['screen'])->toBe('OTHER_DATE')
        ->and(count(auditSent('/schedule/availability')))->toBe($antes);
});

/*
| La consulta se hace en la misma pantalla (no espera a la cola)
*/

it('consulta SMD en la misma pantalla, sin depender de la cola', function () {
    // Con la cola "trabada" la consulta igual se hace: no pasa por ella.
    \Illuminate\Support\Facades\Queue::fake([\Webkul\Admin\Jobs\FetchSpecialtyAvailability::class]);
    startBooking($this);

    $res = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);

    expect($res['screen'])->toBe('DOCTOR')
        ->and(auditSent('/schedule/availability'))->toHaveCount(1);
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});

it('si SMD tarda más que la pantalla, la termina en segundo plano y no la guarda como falla', function () {
    \Illuminate\Support\Facades\Queue::fake([\Webkul\Admin\Jobs\FetchSpecialtyAvailability::class]);

    $smd = Mockery::mock(\Webkul\Admin\Services\ShareMeDataService::class)->makePartial();
    $smd->shouldReceive('availabilityBySpecialty')->once()->andReturnUsing(function () use ($smd) {
        $smd->lastTimedOut = true;

        return null;
    });
    app()->instance(\Webkul\Admin\Services\ShareMeDataService::class, $smd);

    $booking = app(BookingOptions::class);
    $from = now(config('app.timezone'))->toDateString();
    $result = $booking->availability($this->specialtyId, $from, 7, microtime(true) + 5);

    expect($result)->toBeNull()
        ->and(\Illuminate\Support\Facades\Cache::get("booking:avail:{$this->specialtyId}:{$from}:7"))->toBeNull();
    \Illuminate\Support\Facades\Queue::assertPushed(\Webkul\Admin\Jobs\FetchSpecialtyAvailability::class, 1);
});

it('si manda el formulario dos veces el mismo día con el mismo doctor, la segunda no crea otra cita y lo dice', function () {
    $book = function (string $time) {
        $this->token = FlowToken::issue($this->conv->id);
        startBooking($this);
        step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
        step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
        step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
        step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => $time]);

        return step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si']);
    };

    $book('09:00');
    $citas = DB::table('activity_participants')->where('person_id', $this->mama->id)->count();
    expect($citas)->toBe(1);

    $book('10:00');

    expect(DB::table('activity_participants')->where('person_id', $this->mama->id)->count())->toBe($citas)
        ->and(DB::table('whatsapp_messages')->where('conversation_id', $this->conv->id)->orderByDesc('id')->value('body'))
        ->toContain('ya tiene una cita')
        ->toContain('a las 09:00')
        ->not->toContain('ya no está disponible');
});

/** Formulario "enviado" por el agente con su prefill, con la v6 activa. Devuelve el token. */
function bookingWithPrefill(object $t, array $prefill): string
{
    config([
        'whatsapp.flows.patient.id'               => '7770001',
        'whatsapp.flows.patient.v4'               => true,
        'whatsapp.flows.patient.prefill'          => true,
        'whatsapp.flows.patient.prefill_schedule' => true,
    ]);

    $t->token = FlowToken::issue($t->conv->id, 'paciente_tercero');
    \Webkul\Whatsapp\Models\Message::create([
        'conversation_id' => $t->conv->id, 'direction' => 'outbound', 'type' => 'flow', 'body' => 'x', 'status' => 'sent', 'sender' => 'ia',
        'payload'         => ['flow' => ['key' => 'paciente_tercero', 'token' => $t->token, 'id' => '7770001', 'prefill' => $prefill]],
    ]);

    startBooking($t);

    return $t->token;
}

it('R7–R9: después del doctor (marcado por el chat) aparece su turno más próximo, y Continuar va directo al resumen', function () {
    bookingWithPrefill($this, ['doctor' => (string) $this->doctorId]);

    $doctors = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    expect($doctors['screen'])->toBe('DOCTOR')
        ->and($doctors['data']['init_doctor'])->toBe((string) $this->doctorId);

    $next = step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);
    expect($next['screen'])->toBe('NEXT_SLOT')
        ->and($next['data']['init_choice'])->toBe('accept')
        ->and($next['data']['heading'])->toStartWith('El turno más próximo con')
        ->and($next['data']['options'][0]['title'])->toEndWith($this->day->format('d/m').' a las 09:00')
        ->and($next['data']['options'][1])->toMatchArray(['id' => 'other', 'title' => 'Quiero otra fecha y hora']);

    $summary = step($this->publicPem, $this->token, 'NEXT_SLOT', ['slot_choice' => 'accept']);
    expect($summary['screen'])->toBe('SUMMARY')
        ->and(FlowSession::get($this->token))->toMatchArray(['date' => $this->day->toDateString(), 'time' => '09:00']);
});

it('R9: la hora pedida en el chat, si está libre, es la sugerida ("El turno que pediste")', function () {
    bookingWithPrefill($this, ['doctor' => (string) $this->doctorId, 'date' => $this->day->toDateString(), 'time' => '10:00']);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);

    $next = step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);

    expect($next['data']['heading'])->toStartWith('El turno que pediste con')
        ->and($next['data']['options'][0]['title'])->toEndWith('a las 10:00');
});

it('"Quiero otra fecha y hora" sigue con día y hora normales, sin volver a marcar la sugerencia', function () {
    bookingWithPrefill($this, ['doctor' => (string) $this->doctorId]);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);
    step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId]);

    $dates = step($this->publicPem, $this->token, 'NEXT_SLOT', ['slot_choice' => 'other']);
    expect($dates['screen'])->toBe('SLOT_DATE')->and($dates['data'])->not->toHaveKey('init_date');

    $times = step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    expect($times['screen'])->toBe('SLOT_TIME')->and($times['data'])->not->toHaveKey('init_time');
});

it('sin la v6 (opción apagada) el doctor lleva directo a elegir el día, como antes', function () {
    config(['whatsapp.flows.patient.prefill_schedule' => false]);
    startBooking($this);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->specialtyId]);

    expect(step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->doctorId])['screen'])->toBe('SLOT_DATE');
});

/*
| Formulario completo desde el chat: abre ya avanzado (pantallas de entrada START_*)
*/

/** Formulario con prefill, abierto (INIT) con el atajo encendido. Devuelve la primera pantalla. */
function openPrefilled(object $t, array $prefill, bool $fastForward = true): array
{
    config([
        'whatsapp.flows.patient.id'               => '7770001',
        'whatsapp.flows.patient.v4'               => true,
        'whatsapp.flows.patient.prefill'          => true,
        'whatsapp.flows.patient.prefill_schedule' => true,
        'whatsapp.flows.patient.fast_forward'     => $fastForward,
        'whatsapp.flows.patient.ask_ci'           => false,
    ]);

    $t->token = FlowToken::issue($t->conv->id, 'paciente_tercero');
    \Webkul\Whatsapp\Models\Message::create([
        'conversation_id' => $t->conv->id, 'direction' => 'outbound', 'type' => 'flow', 'body' => 'x', 'status' => 'sent', 'sender' => 'ia',
        'payload'         => ['flow' => ['key' => 'paciente_tercero', 'token' => $t->token, 'id' => '7770001', 'prefill' => $prefill]],
    ]);

    return flowCall($t->publicPem, ['version' => '3.0', 'action' => 'INIT', 'flow_token' => $t->token])[1];
}

it('con todo pedido y el turno libre, abre directo en "Revisá tu cita" y confirma desde ahí', function () {
    $first = openPrefilled($this, [
        'relation' => 'self', 'doctor' => (string) $this->doctorId, 'date' => $this->day->toDateString(), 'time' => '10:00',
    ]);

    expect($first['screen'])->toBe('START_SUMMARY')
        ->and($first['data']['detail'])->toContain('10:00')
        ->and(FlowSession::get($this->token))->toMatchArray(['doctor_id' => $this->doctorId, 'date' => $this->day->toDateString(), 'time' => '10:00']);

    $antes = DB::table('activities')->count();
    $done = step($this->publicPem, $this->token, 'START_SUMMARY', ['confirm' => 'si']);

    expect($done['screen'])->toBe('SUCCESS')
        ->and(DB::table('activities')->count())->toBeGreaterThan($antes);
});

it('con el turno pedido ocupado, abre en "Turno sugerido" con el más cercano', function () {
    $first = openPrefilled($this, [
        'relation' => 'self', 'doctor' => (string) $this->doctorId, 'date' => $this->day->toDateString(), 'time' => '13:00',
    ]);

    expect($first['screen'])->toBe('START_NEXT_SLOT')
        ->and($first['data']['options'][0]['title'])->toEndWith('a las 10:00');

    $summary = step($this->publicPem, $this->token, 'START_NEXT_SLOT', ['slot_choice' => 'accept']);
    expect($summary['screen'])->toBe('SUMMARY');
});

it('sin doctor pedido, abre en la lista de doctores con paciente y especialidad ya resueltos', function () {
    $first = openPrefilled($this, ['relation' => 'self', 'specialty' => (string) $this->specialtyId]);

    expect($first['screen'])->toBe('START_DOCTOR')
        ->and($first['data']['doctors'][0]['id'])->toBe((string) $this->doctorId);

    expect(step($this->publicPem, $this->token, 'START_DOCTOR', ['doctor' => (string) $this->doctorId])['screen'])->toBe('NEXT_SLOT');
});

it('un aviso desde una pantalla de entrada vuelve a la misma pantalla de entrada (no a su original)', function () {
    openPrefilled($this, ['relation' => 'self', 'specialty' => (string) $this->specialtyId]);

    $again = step($this->publicPem, $this->token, 'START_DOCTOR', ['doctor' => '999999']);

    expect($again['screen'])->toBe('START_DOCTOR');
});

it('un tercero nuevo (hay que pedirle nombre y cédula) empieza desde el principio', function () {
    $first = openPrefilled($this, ['relation' => 'tercero', 'patient_name' => 'Nadie Nuevo Zq', 'doctor' => (string) $this->doctorId]);

    expect($first['screen'])->toBe('PATIENT_SELECT')
        ->and($first['data']['init_choice'])->toBe('other');
});

it('con el atajo apagado abre siempre en "¿Para quién es la cita?"', function () {
    $first = openPrefilled($this, ['relation' => 'self', 'doctor' => (string) $this->doctorId, 'date' => $this->day->toDateString(), 'time' => '10:00'], false);

    expect($first['screen'])->toBe('PATIENT_SELECT')
        ->and($first['data']['init_choice'])->toBe('self');
});

it('en el JSON, las pantallas de entrada no tienen transiciones de entrada y salen a lo mismo que su original', function () {
    $flow = json_decode(file_get_contents(base_path('packages/Webkul/Whatsapp/src/Resources/flows/paciente_tercero.json')), true);
    $incoming = collect($flow['routing_model'])->flatten()->unique()->all();
    $screens = collect($flow['screens'])->keyBy('id');

    foreach (['START_SPECIALTY', 'START_DOCTOR', 'START_NEXT_SLOT', 'START_SUMMARY'] as $entry) {
        $original = substr($entry, 6);

        expect($incoming)->not->toContain($entry)
            ->and($screens[$entry]['layout'])->toBe($screens[$original]['layout']);

        // Meta exige que toda pantalla esté conectada: START_SUMMARY (el resumen
        // termina el formulario) declara la salida a elegir el día.
        $entry === 'START_SUMMARY'
            ? expect($flow['routing_model'][$entry])->toBe(['SLOT_DATE'])
            : expect($flow['routing_model'][$entry])->toBe($flow['routing_model'][$original]);
    }
});

/*
| Lead de la cita: si es "Para mí", se usa el lead "Consulta" que el agente abrió
| para esa conversación, en vez de crear uno nuevo al lado.
*/

function consultaLead(int $personId, ?int $stageId = null): int
{
    return DB::table('leads')->insertGetId([
        'title'                  => 'Consulta - Test '.uniqid(),
        'user_id'                => 1,
        'person_id'              => $personId,
        'status'                 => 1,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => $stageId ?? (int) config('whatsapp.crm.stage_consulta'),
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
}

function bookJob(object $t, int $responsibleId, int $patientId): void
{
    \Webkul\Whatsapp\Jobs\BookFlowAppointment::dispatchSync([
        'conversation_id' => $t->conv->id, 'responsible_id' => $responsibleId, 'patient_id' => $patientId,
        'doctor_id'       => $t->doctorId, 'specialty_id' => $t->specialtyId, 'specialty' => 'Zq',
        'date'            => $t->day->toDateString(), 'time' => '10:00', 'duration' => 60,
    ]);
}

function leadOfBooking(object $t, int $patientId): ?int
{
    return DB::table('activities')
        ->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
        ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
        ->where('activity_participants.person_id', $patientId)
        ->where('activities.schedule_from', $t->day->toDateString().' 10:00:00')
        ->value('lead_activities.lead_id');
}

it('"Para mí": la cita usa el lead "Consulta" abierto del paciente y no crea otro', function () {
    $consulta = consultaLead($this->mama->id);
    $leadsBefore = DB::table('leads')->where('person_id', $this->mama->id)->count();

    bookJob($this, $this->mama->id, $this->mama->id);

    expect(leadOfBooking($this, $this->mama->id))->toBe($consulta)
        ->and(DB::table('leads')->where('person_id', $this->mama->id)->count())->toBe($leadsBefore)
        // Pasa a ser la cita: sale de Consultas igual que cualquier cita nueva.
        ->and((int) DB::table('leads')->where('id', $consulta)->value('lead_pipeline_stage_id'))
        ->not->toBe((int) config('whatsapp.crm.stage_consulta'));
});

it('para un tercero no toca el lead "Consulta" de quien escribe: la cita lleva su propio lead', function () {
    $consulta = consultaLead($this->mama->id);
    $hijo = auditPerson(['name' => 'Hijo Agenda '.uniqid(), 'smd_patient_id' => bin2hex(random_bytes(12))]);

    bookJob($this, $this->mama->id, $hijo->id);

    $lead = leadOfBooking($this, $hijo->id);
    expect($lead)->not->toBeNull()->not->toBe($consulta)
        ->and((int) DB::table('leads')->where('id', $lead)->value('person_id'))->toBe($hijo->id)
        ->and((int) DB::table('leads')->where('id', $consulta)->value('lead_pipeline_stage_id'))
        ->toBe((int) config('whatsapp.crm.stage_consulta'));
});

it('no reutiliza un lead que no está en Consultas (p.ej. derivado a recepción) ni uno cerrado', function () {
    $recepcion = consultaLead($this->mama->id, (int) config('whatsapp.crm.stage_recepcion'));
    $cerrado = consultaLead($this->mama->id);
    DB::table('leads')->where('id', $cerrado)->update(['status' => 0]);

    bookJob($this, $this->mama->id, $this->mama->id);

    expect(leadOfBooking($this, $this->mama->id))->not->toBeNull()->not->toBeIn([$recepcion, $cerrado])
        ->and((int) DB::table('leads')->where('id', $recepcion)->value('lead_pipeline_stage_id'))
        ->toBe((int) config('whatsapp.crm.stage_recepcion'));
});
