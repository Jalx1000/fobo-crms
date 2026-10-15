<?php

/*
|--------------------------------------------------------------------------
| Formulario de agendar, versión 4: paso a paso más claro
|--------------------------------------------------------------------------
| - Sin la pantalla "Confirmar" del inicio (la gente creía que ya confirmaba).
| - "General" es UNA opción que junta a todas las especialidades generales
|   (antes "No sé" elegía solo la primera, aunque no tuviera horarios).
| - Solo se ofrecen doctores con horarios.
| - Cada pantalla muestra lo elegido hasta ahí ("context").
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Services\Flows\FlowSession;
use Webkul\Whatsapp\Services\Flows\FlowToken;

beforeEach(function () {
    if (trim((string) shell_exec('command -v openssl')) === '') {
        $this->markTestSkipped('Falta el binario openssl para simular el cifrado de Meta.');
    }

    $this->smd = auditBootFakes();
    Queue::fake([\Webkul\Whatsapp\Jobs\SendMessage::class]);
    [$this->publicPem, $this->keyPath] = bootFlowEndpoint();
    config([
        'whatsapp.flows.patient.ask_ci'               => true,
        'whatsapp.flows.patient.v4'                   => true,
        'whatsapp.flows.booking.days'                 => 7,
        'whatsapp.flows.booking.duration_minutes'     => 60,
        'whatsapp.flows.insurance_wait_seconds'       => 0,
        'whatsapp.flows.booking.general_specialties'  => ['Zq General A', 'Zq General B'],
    ]);

    $this->day = Carbon::tomorrow(config('app.timezone'));
    $mkSpec = fn ($name) => DB::table('specialties')->insertGetId(['name' => $name, 'slug' => 'zq-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    $this->generalA = $mkSpec('Zq General A');
    $this->generalB = $mkSpec('Zq General B');
    $this->ortodoncia = $mkSpec('Zq Orto '.uniqid());

    // Doctor A: General A con horarios; Doctor B: General B con horarios;
    // Doctor C: General A SIN horarios en SMD (no se tiene que ofrecer).
    $this->docA = auditDoctor(['name' => 'Dra A '.uniqid()]);
    $this->docB = auditDoctor(['name' => 'Dr B '.uniqid()]);
    $this->docC = auditDoctor(['name' => 'Dr C '.uniqid()]);
    foreach ([[$this->docA, $this->generalA], [$this->docB, $this->generalB], [$this->docC, $this->generalA]] as [$doc, $spec]) {
        DB::table('doctor_specialty')->insert(['doctor_id' => $doc, 'specialty_id' => $spec]);
        auditShift($doc, $this->day, '08:00', '12:00');
    }

    $uid = fn ($id) => DB::table('doctors')->where('id', $id)->value('unique_id');
    $slotsA = auditSmdAvailability($uid($this->docA), $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(11, 0));
    $slotsB = auditSmdAvailability($uid($this->docB), $this->day->copy()->setTime(10, 0), $this->day->copy()->setTime(12, 0));
    $this->smd['availability'] = array_merge($slotsA, $slotsB);

    $this->yo = auditPerson(['name' => 'Paciente Vcuatro', 'smd_patient_id' => bin2hex(random_bytes(12))]);
    $ciAttr = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');
    DB::table('attribute_values')->insert(['attribute_id' => $ciAttr, 'entity_id' => $this->yo->id, 'entity_type' => 'persons', 'text_value' => (string) rand(100000000, 999999999)]);
    $this->token = FlowToken::issue(flowConversation($this->yo->id)->id);
});

afterEach(fn () => @unlink($this->keyPath));

it('"Para mí" con cédula va directo a la especialidad, sin la pantalla Confirmar', function () {
    $res = step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);

    expect($res['screen'])->toBe('SPECIALTY')
        ->and($res['data']['context'])->toBe('Cita para Paciente Vcuatro');
});

it('"General" es una sola opción y junta a los doctores de todas las especialidades generales', function () {
    $specialties = step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);
    $ids = collect($specialties['data']['specialties'])->pluck('id');

    expect($ids->filter(fn ($id) => $id === 'general'))->toHaveCount(1)
        ->and($ids)->not->toContain((string) $this->generalA)->not->toContain((string) $this->generalB);

    $doctors = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => 'general']);
    $offered = collect($doctors['data']['doctors'])->pluck('id')->all();

    expect($doctors['screen'])->toBe('DOCTOR')
        ->and($offered)->toContain((string) $this->docA)->toContain((string) $this->docB)
        // El que no tiene horarios no se ofrece.
        ->not->toContain((string) $this->docC)
        ->and($doctors['data']['context'])->toBe('Cita para Paciente Vcuatro · General');
});

it('un doctor sin horarios no se puede elegir aunque llegue su id', function () {
    step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => 'general']);

    $res = step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->docC]);

    expect($res['screen'])->toBe('DOCTOR');
});

it('si ningún doctor tiene horarios, lo dice en la especialidad (no muestra una lista vacía)', function () {
    $doc = auditDoctor(['name' => 'Dr Orto '.uniqid()]);
    DB::table('doctor_specialty')->insert(['doctor_id' => $doc, 'specialty_id' => $this->ortodoncia]);
    auditShift($doc, $this->day, '08:00', '12:00');
    step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);

    $res = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => (string) $this->ortodoncia]);

    expect($res['screen'])->toBe('SPECIALTY')
        ->and($res['data']['error_message'])->toContain('No hay horarios libres');
});

it('cada pantalla muestra lo elegido hasta ahí, y el resumen dice que falta confirmar', function () {
    step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);
    step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => 'general']);
    $dates = step($this->publicPem, $this->token, 'DOCTOR', ['doctor' => (string) $this->docB]);
    $doctorName = DB::table('doctors')->where('id', $this->docB)->value('name');

    expect($dates['data']['context'])->toBe('Cita para Paciente Vcuatro · General · '.$doctorName);

    $times = step($this->publicPem, $this->token, 'SLOT_DATE', ['date' => $this->day->toDateString()]);
    expect($times['data']['context'])->toContain($doctorName)->toContain($this->day->format('d/m'))
        ->and(collect($times['data']['times'])->pluck('id')->all())->toBe(['10:00', '11:00']);

    $summary = step($this->publicPem, $this->token, 'SLOT_TIME', ['time' => '10:00']);
    expect($summary['screen'])->toBe('SUMMARY')
        ->and($summary['data']['detail'])->toContain('Tocá «Confirmar cita» para agendarla.')
        ->and(FlowSession::get($this->token)['specialty_id'])->toBe($this->generalB);
});

it('"Otra persona" va directo a la especialidad después de cargar sus datos', function () {
    $ci = (string) rand(100000000, 999999999);
    step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'other']);

    $res = step($this->publicPem, $this->token, 'PATIENT_DATA', ['full_name' => 'Hijo Vcuatro', 'ci' => $ci, 'birthdate' => '', 'relation' => 'hijo']);

    expect($res['screen'])->toBe('SPECIALTY')
        ->and($res['data']['context'])->toBe('Cita para Hijo Vcuatro');
});

it('con la versión 4 apagada (formulario publicado anterior) sigue pasando por Confirmar', function () {
    config(['whatsapp.flows.patient.v4' => false]);

    $res = step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);

    expect($res['screen'])->toBe('CONFIRM')
        ->and($res['data'])->not->toHaveKey('context');
});

it('un mensaje enviado con el formulario anterior sigue con sus pantallas aunque la v4 esté activa', function () {
    config(['whatsapp.flows.patient.id' => 'flow-v4']);
    $sent = fn ($flowId) => \Webkul\Whatsapp\Models\Message::create([
        'conversation_id' => FlowToken::conversationId($this->token),
        'direction'       => 'outbound',
        'type'            => 'flow',
        'body'            => 'Agendá tu cita',
        'status'          => 'sent',
        'payload'         => ['flow' => ['key' => 'paciente_tercero', 'token' => $this->token, 'id' => $flowId]],
    ]);

    // Enviado antes de publicar la v4: no tiene la pantalla SPECIALTY después
    // de PATIENT_SELECT y Meta rechazaría el salto.
    $sent('flow-v3');
    $res = step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);

    expect($res['screen'])->toBe('CONFIRM');
});

it('un mensaje enviado con el formulario v4 usa las pantallas de la v4', function () {
    config(['whatsapp.flows.patient.id' => 'flow-v4']);
    \Webkul\Whatsapp\Models\Message::create([
        'conversation_id' => FlowToken::conversationId($this->token),
        'direction'       => 'outbound',
        'type'            => 'flow',
        'body'            => 'Agendá tu cita',
        'status'          => 'sent',
        'payload'         => ['flow' => ['key' => 'paciente_tercero', 'token' => $this->token, 'id' => 'flow-v4']],
    ]);

    $res = step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);

    expect($res['screen'])->toBe('SPECIALTY');
});

it('la lista de doctores dice desde qué día hay horarios, sin la hora', function () {
    step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);
    $doctors = step($this->publicPem, $this->token, 'SPECIALTY', ['specialty' => 'general']);

    expect($doctors['data']['doctors'])->not->toBeEmpty();

    foreach ($doctors['data']['doctors'] as $doctor) {
        expect($doctor['description'])->toStartWith('Tiene horarios desde el ')->not->toContain(':');
    }
});
