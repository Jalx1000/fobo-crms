<?php

/*
|--------------------------------------------------------------------------
| Pre-llenado del formulario de agendar (prefill del agente)
|--------------------------------------------------------------------------
| El agente manda lo que el paciente ya dijo en el chat (para quién y qué
| necesita) al pedir el formulario. El CRM lo guarda con el mensaje y lo usa
| como valor INICIAL de las pantallas que arma (data_exchange), solo cuando
| coincide con una opción real. Requerimiento del 2026-10-26 (R1–R6).
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\Flows\FlowPrefill;
use Webkul\Whatsapp\Services\Flows\PatientFlow;

beforeEach(function () {
    if (trim((string) shell_exec('command -v openssl')) === '') {
        $this->markTestSkipped('Falta el binario openssl para simular el cifrado de Meta.');
    }

    auditBootFakes();
    Queue::fake();
    [$this->publicPem, $this->keyPath] = bootFlowEndpoint();

    config([
        'whatsapp.outbound_gateway'                   => 'cloud_api',
        'whatsapp.gateways.cloud_api.token'           => 'test-token',
        'whatsapp.gateways.cloud_api.phone_number_id' => '111222333',
        'whatsapp.flows.patient.id'                   => '5550001',
        'whatsapp.flows.patient.v4'                   => true,
        'whatsapp.flows.patient.prefill'              => true,
    ]);

    $this->mama = auditPerson(['name' => 'Mama Prefill '.uniqid()]);
    $this->conv = flowConversation($this->mama->id);
    $this->conv->forceFill(['last_inbound_at' => now()->subMinutes(5)])->save();
});

afterEach(function () {
    @unlink($this->keyPath);
});

/** Pide el formulario como el agente y devuelve [respuesta JSON, token]. */
function prefillSend(object $t, array $body): array
{
    actingAsSanctumAuthenticatedAdmin();

    $res = $t->postJson("/api/v1/whatsapp/conversations/{$t->conv->id}/flows", $body)->assertOk();

    return [$res->json(), $res->json('flow_token')];
}

function prefillInit(object $t, string $token): array
{
    return flowCall($t->publicPem, ['version' => '3.0', 'action' => 'INIT', 'flow_token' => $token])[1];
}

/*
| R1 / R5 / R6 — endpoint
*/

it('R1: acepta prefill, lo guarda limpio con el formulario y devuelve lo que tomó', function () {
    [$json] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => [
        'relation' => 'tercero', 'patient_name' => '  María Pérez ', 'specialty' => 'Ortodoncia', 'relationship' => 'hijo',
    ]]);

    $expected = ['relation' => 'tercero', 'relationship' => 'hijo', 'patient_name' => 'María Pérez', 'specialty' => 'Ortodoncia'];

    // toEqual: la base reordena las claves del JSON al guardarlo.
    expect($json['prefill'])->toEqual($expected)
        ->and(Message::find($json['message']['id'])->payload['flow']['prefill'])->toEqual($expected);
});

it('R5: claves desconocidas, prefill mal formado o valores raros no dan error y el formulario se manda igual', function (mixed $prefill) {
    [$json] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => $prefill, 'otra_clave' => 'x']);

    expect($json['message']['type'])->toBe('flow')
        ->and(Message::find($json['message']['id'])->payload['flow'])->not->toHaveKey('prefill');
})->with([
    'texto en vez de objeto' => ['hola'],
    'lista vacía'            => [[]],
    'valores inválidos'      => [['relation' => 'vecino', 'relationship' => 'jefe', 'patient_name' => '   ', 'specialty' => ['x']]],
    'sin prefill'            => [null],
]);

it('R6: el prefill solo aplica a agendar; cancelar y reprogramar lo ignoran', function (string $flow) {
    [$json] = prefillSend($this, ['flow' => $flow, 'prefill' => ['relation' => 'self']]);

    expect($json['prefill'])->toBe([])
        ->and(Message::find($json['message']['id'])->payload['flow'])->not->toHaveKey('prefill');
})->with(['cancelar_cita', 'reprogramar_cita']);

/*
| R2 — valores iniciales en las pantallas
*/

it('"Para mí" queda preseleccionado en la primera pantalla', function () {
    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['relation' => 'self']]);

    expect(prefillInit($this, $token)['data']['init_choice'])->toBe('self');
});

it('un tercero que ya es paciente a cargo se preselecciona por su nombre', function () {
    $hijo = auditPerson(['name' => 'Sofía Prefill (SISTEMA 2024)', 'contact_numbers' => []]);
    DB::table('person_relations')->insert([
        'patient_person_id' => $hijo->id, 'responsible_person_id' => $this->mama->id,
        'relation'          => 'hijo', 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['relation' => 'tercero', 'patient_name' => 'sofia prefill']]);

    expect(prefillInit($this, $token)['data']['init_choice'])->toBe('p:'.$hijo->id);
});

it('un tercero nuevo preselecciona "Otra persona" y llega con nombre y parentesco escritos', function () {
    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => [
        'relation' => 'tercero', 'patient_name' => 'María Pérez', 'relationship' => 'hijo',
    ]]);

    expect(prefillInit($this, $token)['data']['init_choice'])->toBe('other');

    $data = step($this->publicPem, $token, 'PATIENT_SELECT', ['choice' => 'other']);

    expect($data['screen'])->toBe('PATIENT_DATA')
        ->and($data['data']['init_full_name'])->toBe('María Pérez')
        ->and($data['data']['init_relation'])->toBe('hijo');
});

it('la especialidad se preselecciona por id, por nombre sin tildes o como "General"', function (string $sent, string $expected) {
    // Las opciones tal como las arma la pantalla SPECIALTY.
    $options = [
        ['id' => 'general', 'title' => 'General / No sé qué necesito'],
        ['id' => '3', 'title' => 'Odontopediatria'],
        ['id' => '6', 'title' => 'Ortodoncia'],
    ];

    expect(FlowPrefill::specialty(['specialty' => $sent], $options))->toBe($expected);
})->with([
    'id exacto'        => ['6', '6'],
    'nombre con tilde' => ['Odontopediatría', '3'],
    'no sabe'          => ['No sé', 'general'],
    'no coincide (R4)' => ['limpieza', ''],
]);

it('la pantalla SPECIALTY real llega con la especialidad preseleccionada', function () {
    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['specialty' => 'ortodóncia zq']]);

    // Especialidades como las devuelve el catálogo de agenda (id, name).
    $screen = (function () use ($token) {
        return $this->specialtyScreen([['id' => 987654, 'name' => 'Ortodoncia ZQ']], null, $token);
    })->call(app(PatientFlow::class));

    expect($screen['screen'])->toBe('SPECIALTY')
        ->and($screen['data']['init_specialty'])->toBe('987654');
});

/*
| R4 — degradación segura
*/

it('sin prefill las pantallas abren igual y SIN valor inicial (un "" muestra "Select an option" en rojo)', function () {
    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero']);
    expect(prefillInit($this, $token)['data'])->not->toHaveKey('init_choice');

    $data = step($this->publicPem, $token, 'PATIENT_SELECT', ['choice' => 'other']);
    expect($data['screen'])->toBe('PATIENT_DATA')
        ->and($data['data'])->not->toHaveKey('init_full_name')
        ->and($data['data'])->not->toHaveKey('init_relation');
});

it('una especialidad que no coincide no manda valor inicial (R4)', function () {
    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['specialty' => 'limpieza']]);

    $screen = (function () use ($token) {
        return $this->specialtyScreen([['id' => 987654, 'name' => 'Ortodoncia ZQ']], null, $token);
    })->call(app(PatientFlow::class));

    expect($screen['data'])->not->toHaveKey('init_specialty');
});

it('con la opción apagada no se agregan datos que el formulario publicado no declara', function () {
    config(['whatsapp.flows.patient.prefill' => false]);
    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['relation' => 'self']]);

    expect(prefillInit($this, $token)['data'])->not->toHaveKey('init_choice');
});

it('un formulario enviado con otro flow_id (versión anterior) no recibe valores iniciales', function () {
    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['relation' => 'self']]);
    config(['whatsapp.flows.patient.id' => '9990009']); // se publicó otra versión después

    expect(prefillInit($this, $token)['data'])->not->toHaveKey('init_choice');
});

/*
| JSON del formulario
*/

it('el JSON del formulario liga cada valor inicial a un dato declarado en su pantalla', function () {
    $flow = json_decode(file_get_contents(base_path('packages/Webkul/Whatsapp/src/Resources/flows/paciente_tercero.json')), true);
    $screens = collect($flow['screens'])->keyBy('id');

    foreach (['PATIENT_SELECT' => ['init_choice'], 'PATIENT_DATA' => ['init_full_name', 'init_relation'], 'SPECIALTY' => ['init_specialty']] as $id => $vars) {
        foreach ($vars as $var) {
            expect($screens[$id]['data'])->toHaveKey($var)
                ->and(json_encode($screens[$id]['layout']))->toContain('"init-value":"${data.'.$var.'}"');
        }
    }
});

it('FlowPrefill::sanitize acepta los alias de relation del agente', function () {
    expect(FlowPrefill::sanitize(['relation' => 'Para mí'])['relation'])->toBe('self')
        ->and(FlowPrefill::sanitize(['relation' => 'other'])['relation'])->toBe('tercero')
        ->and(FlowPrefill::sanitize(['relation' => 'TERCERO'])['relation'])->toBe('tercero');
});

/*
| R7–R9 — doctor, día y hora
*/

function prefillScreen(string $method, array $args): array
{
    return (function () use ($method, $args) {
        return $this->{$method}(...$args);
    })->call(app(PatientFlow::class));
}

it('sanitize: doctor como texto, date YYYY-MM-DD válida y time HH:MM (acepta H:MM)', function () {
    $clean = FlowPrefill::sanitize(['doctor' => ' Dr. Eloy ', 'date' => '2026-10-30', 'time' => '9:00']);
    expect($clean)->toMatchArray(['doctor' => 'Dr. Eloy', 'date' => '2026-10-30', 'time' => '09:00']);

    $bad = FlowPrefill::sanitize(['date' => '2026-02-30', 'time' => '25:00']);
    expect($bad)->not->toHaveKey('date')->not->toHaveKey('time');

    expect(FlowPrefill::sanitize(['date' => '30/10/2026', 'time' => '13h']))->toBe([]);
});

it('el doctor se identifica por id o por nombre, y con dos candidatos no se adivina', function (string $sent, ?int $expected) {
    $doctors = [21 => 'ELOY MONTERO', 4 => 'JESSICA MONTERO', 38 => 'ELIZABETH FLORES'];

    expect(FlowPrefill::doctorId(['doctor' => $sent], $doctors))->toBe($expected);
})->with([
    'id'                  => ['21', 21],
    '"Dr. Eloy"'          => ['Dr. Eloy', 21],
    'nombre sin tildes'   => ['Élizabeth Flores', 38],
    'ambiguo (Montero)'   => ['Montero', null],
    'no existe'           => ['Dr. House', null],
    'id que no se ofrece' => ['999', null],
]);

it('el día: el pedido si tiene horarios, si no el siguiente, "Otra fecha" más allá de la lista y el más próximo sin día pedido', function (array $prefill, string $expected) {
    $dates = ['2026-10-27', '2026-10-29', '2026-10-30'];

    expect(FlowPrefill::date($prefill, $dates, '2026-12-31'))->toBe($expected);
})->with([
    'pedido con horarios'          => [['date' => '2026-10-29'], '2026-10-29'],
    'pedido sin horarios'          => [['date' => '2026-10-28'], '2026-10-29'],
    'más allá de la lista'         => [['date' => '2026-11-10'], 'other'],
    'más allá del máximo'          => [['date' => '2027-03-01'], ''],
    'sin día, con doctor (R9)'     => [['doctor' => 'Eloy'], '2026-10-27'],
    'sin preferencia de agenda'    => [['specialty' => 'Ortodoncia'], ''],
]);

it('la hora: la pedida si está libre, si no la libre más cercana, y la primera sin hora pedida', function (array $prefill, string $expected) {
    expect(FlowPrefill::time($prefill, ['09:00', '10:00', '15:00']))->toBe($expected);
})->with([
    'pedida libre'              => [['time' => '10:00'], '10:00'],
    'pedida ocupada (13:00)'    => [['time' => '13:00'], '15:00'],
    'pedida ocupada (11:00)'    => [['time' => '11:00'], '10:00'],
    'sin hora, con fecha (R9)'  => [['date' => '2026-10-29'], '09:00'],
    'sin preferencia de agenda' => [['relation' => 'self'], ''],
]);

it('con R7–R9 encendido, las pantallas de día, otra fecha y hora llegan con su valor inicial', function () {
    config(['whatsapp.flows.patient.prefill_schedule' => true]);
    $tz = config('app.timezone');
    $d1 = now($tz)->addDays(1)->toDateString();
    $d2 = now($tz)->addDays(2)->toDateString();
    $far = now($tz)->addDays(20)->toDateString();

    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['date' => $d2, 'time' => '10:00']]);

    $date = prefillScreen('dateScreen', [[$d1 => ['09:00'], $d2 => ['09:00', '10:00']], null, $token]);
    $time = prefillScreen('timeScreen', [$d2, ['09:00', '10:00'], null, $token]);

    expect($date['data']['init_date'])->toBe($d2)
        ->and($time['data']['init_time'])->toBe('10:00');

    [, $token2] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['date' => $far]]);
    $other = prefillScreen('otherDateScreen', [['doctor_id' => 0], null, $token2]);

    expect(prefillScreen('dateScreen', [[$d1 => ['09:00']], null, $token2])['data']['init_date'])->toBe('other')
        ->and($other['data']['init_other_date'])->toBe($far);
});

it('con R7–R9 apagado (formulario v5 publicado) no se mandan init_date ni init_time', function () {
    config(['whatsapp.flows.patient.prefill_schedule' => false]);
    $d1 = now(config('app.timezone'))->addDay()->toDateString();

    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['date' => $d1, 'time' => '09:00']]);

    expect(prefillScreen('dateScreen', [[$d1 => ['09:00']], null, $token])['data'])->not->toHaveKey('init_date')
        ->and(prefillScreen('timeScreen', [$d1, ['09:00'], null, $token])['data'])->not->toHaveKey('init_time');
});

it('sin especialidad pero con doctor, se preselecciona la especialidad de ese doctor', function () {
    $doctorId = auditDoctor(['name' => 'Doctora Prefill Zq '.uniqid()]);
    $specialtyId = DB::table('specialties')->insertGetId(['name' => 'Zq Especialidad '.uniqid(), 'slug' => 'zq-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('doctor_specialty')->insert(['doctor_id' => $doctorId, 'specialty_id' => $specialtyId]);

    [, $token] = prefillSend($this, ['flow' => 'paciente_tercero', 'prefill' => ['doctor' => (string) $doctorId]]);

    $screen = prefillScreen('specialtyScreen', [[['id' => $specialtyId, 'name' => 'Zq Especialidad']], null, $token]);

    expect($screen['data']['init_specialty'])->toBe((string) $specialtyId);
});

it('el JSON declara y liga los valores iniciales de doctor, día, otra fecha y hora', function () {
    $flow = json_decode(file_get_contents(base_path('packages/Webkul/Whatsapp/src/Resources/flows/paciente_tercero.json')), true);
    $screens = collect($flow['screens'])->keyBy('id');

    foreach (['DOCTOR' => 'init_doctor', 'SLOT_DATE' => 'init_date', 'OTHER_DATE' => 'init_other_date', 'SLOT_TIME' => 'init_time'] as $id => $var) {
        expect($screens[$id]['data'])->toHaveKey($var)
            ->and(json_encode($screens[$id]['layout']))->toContain('"init-value":"${data.'.$var.'}"');
    }
});
