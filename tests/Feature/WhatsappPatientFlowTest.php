<?php

/*
|--------------------------------------------------------------------------
| Endpoint del Flow "¿Para quién es la cita?" de punta a punta
|--------------------------------------------------------------------------
| Simula a Meta: firma el body, cifra el pedido, llama al endpoint y descifra
| la respuesta. Recorre las pantallas como un usuario.
| planning/14-pacientes-terceros/00.diseno.md
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\Flows\FlowSession;
use Webkul\Whatsapp\Services\Flows\FlowToken;

beforeEach(function () {
    if (trim((string) shell_exec('command -v openssl')) === '') {
        $this->markTestSkipped('Falta el binario openssl para simular el cifrado de Meta.');
    }

    $this->smd = auditBootFakes();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $this->publicPem = openssl_pkey_get_details($key)['key'];
    $this->keyPath = tempnam(sys_get_temp_dir(), 'flowkey');
    file_put_contents($this->keyPath, $privatePem);

    config([
        'whatsapp.flows.private_key_path'       => $this->keyPath,
        'whatsapp.flows.private_key_passphrase' => null,
        'whatsapp.cloud.app_secret'             => 'flow-app-secret',
    ]);

    $this->ciAttr = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');

    do {
        $this->ci = (string) rand(100000000, 999999999);
    } while (DB::table('attribute_values')->where('attribute_id', $this->ciAttr)->where('text_value', 'like', '%'.$this->ci.'%')->exists());

    $this->mama = auditPerson(['name' => 'Mama Flow '.uniqid()]);
    $this->convMama = flowConversation($this->mama->id);
});

afterEach(function () {
    @unlink($this->keyPath);
});

/**
 * Recorre "Otra persona" → datos → confirmar. Después de confirmar el Flow sigue
 * a la agenda: la identificación del paciente queda en la sesión del servidor.
 */
function completeForOther(string $publicPem, Conversation $conversation, string $ci, string $name, string $relation): array
{
    $token = FlowToken::issue($conversation->id);

    $data = step($publicPem, $token, 'PATIENT_SELECT', ['choice' => 'other']);
    expect($data['screen'])->toBe('PATIENT_DATA');

    $confirm = step($publicPem, $token, 'PATIENT_DATA', ['full_name' => $name, 'ci' => $ci, 'birthdate' => '2019-05-03', 'relation' => $relation]);
    expect($confirm['screen'])->toBe('CONFIRM');

    $next = step($publicPem, $token, 'CONFIRM', $confirm['data']);
    expect($next['screen'])->toBeIn(['SPECIALTY', 'SUMMARY']);

    $session = FlowSession::get($token);
    $patientId = $session['patient_id'] ?? null;

    // Paciente nuevo: el formulario solo lo deja pendiente y lo da de alta el
    // job que crea la cita (BookFlowAppointment). Acá se hace lo mismo que él.
    if (! $patientId && ! empty($session['pending_patient'])) {
        $patientId = app(\Webkul\Admin\Services\PatientIdentityResolver::class)->commit(
            $conversation->person_id, $session['pending_patient']['input'], 'whatsapp_flow', $session['pending_patient']['found'],
        )['patient_id'];
    }

    return [
        'flow_token' => $token,
        'next'       => $next['screen'],
        'status'     => $session['patient_status'] ?? null,
        'patient_id' => $patientId,
        'relation'   => $session['relation'] ?? null,
    ];
}

it('rechaza pedidos sin firma o con firma inválida (432)', function () {
    expect(flowCall($this->publicPem, ['action' => 'ping'], null)[0])->toBe(432)
        ->and(flowCall($this->publicPem, ['action' => 'ping'], 'sha256=falsa')[0])->toBe(432);
});

it('responde 421 si el pedido está cifrado para otra clave', function () {
    $otra = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    expect(flowCall($this->publicPem, ['action' => 'ping'], 'auto', openssl_pkey_get_details($otra)['key'])[0])->toBe(421);
});

it('responde el chequeo de salud de Meta', function () {
    expect(flowCall($this->publicPem, ['version' => '3.0', 'action' => 'ping'])[1])
        ->toBe(['data' => ['status' => 'active']]);
});

it('acusa recibo de las notificaciones de error de Meta', function () {
    $res = flowCall($this->publicPem, [
        'version' => '3.0', 'action' => 'data_exchange', 'flow_token' => FlowToken::issue($this->convMama->id),
        'screen'  => 'CONFIRM', 'data' => ['error' => 'INVALID_SCREEN', 'error_message' => 'x'],
    ])[1];

    expect($res)->toBe(['data' => ['acknowledged' => true]]);
});

it('un token falsificado no da acceso a la conversación', function () {
    $falso = $this->convMama->id.'.abc123.'.str_repeat('0', 24);

    $res = flowCall($this->publicPem, ['version' => '3.0', 'action' => 'INIT', 'flow_token' => $falso])[1];

    expect($res['screen'])->toBe('PATIENT_SELECT')
        ->and($res['data']['options'])->toBe([['id' => 'other', 'title' => 'Otra persona']])
        ->and($res['data']['error_message'])->toContain('No pudimos validar');
});

it('INIT ofrece "Para mí", los pacientes a cargo y "Otra persona"', function () {
    $hijo = auditPerson(['name' => 'Hijo A Cargo (SISTEMA 2024)', 'contact_numbers' => []]);
    DB::table('person_relations')->insert([
        'patient_person_id' => $hijo->id, 'responsible_person_id' => $this->mama->id,
        'relation'          => 'hijo', 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = flowCall($this->publicPem, ['version' => '3.0', 'action' => 'INIT', 'flow_token' => FlowToken::issue($this->convMama->id)])[1];

    expect($res['screen'])->toBe('PATIENT_SELECT')
        ->and($res['data']['options'])->toBe([
            ['id' => 'self', 'title' => 'Para mí'],
            ['id' => 'p:'.$hijo->id, 'title' => 'Hijo A Cargo'],
            ['id' => 'other', 'title' => 'Otra persona'],
        ]);
});

it('flujo completo para otra persona: crea al paciente con la cédula y lo vincula', function () {
    $params = completeForOther($this->publicPem, $this->convMama, $this->ci, 'Juan Perez Flow', 'hijo');

    expect($params)->toMatchArray(['status' => 'created', 'relation' => 'hijo'])
        ->and(FlowToken::conversationId($params['flow_token']))->toBe($this->convMama->id)
        ->and(DB::table('persons')->where('id', $params['patient_id'])->value('name'))->toBe('Juan Perez Flow')
        ->and(DB::table('person_relations')->where('patient_person_id', $params['patient_id'])->pluck('responsible_person_id')->all())
        ->toBe([$this->mama->id]);
});

it('dos padres desde conversaciones distintas completan el Flow y llegan al MISMO paciente', function () {
    $papa = auditPerson(['name' => 'Papa Flow '.uniqid()]);
    $convPapa = flowConversation($papa->id);

    $lunes = completeForOther($this->publicPem, $this->convMama, $this->ci, 'Sofia Rios Flow', 'hijo');
    $jueves = completeForOther($this->publicPem, $convPapa, $this->ci, 'SOFIA RIOS FLOW', 'hijo');

    expect($lunes['status'])->toBe('created')
        ->and($jueves['status'])->toBe('found')
        ->and($jueves['patient_id'])->toBe($lunes['patient_id'])
        ->and(DB::table('person_relations')->where('patient_person_id', $lunes['patient_id'])->orderBy('id')->pluck('responsible_person_id')->all())
        ->toBe([$this->mama->id, $papa->id]);
});

it('si la cédula está con otro nombre, vuelve a los datos con error y sin revelar el nombre', function () {
    $otro = auditPerson(['name' => 'Carlos Ajeno', 'contact_numbers' => []]);
    DB::table('attribute_values')->insert(['attribute_id' => $this->ciAttr, 'entity_id' => $otro->id, 'entity_type' => 'persons', 'text_value' => $this->ci]);

    $res = step($this->publicPem, FlowToken::issue($this->convMama->id), 'PATIENT_DATA', [
        'full_name' => 'Juan Perez', 'ci' => $this->ci, 'birthdate' => '', 'relation' => 'hijo',
    ]);

    expect($res['screen'])->toBe('PATIENT_DATA')
        ->and($res['data']['error_message'])->toContain('otro nombre')
        ->and(json_encode($res))->not->toContain('Carlos');
});

it('no deja elegir un paciente que no está a cargo de quien escribe', function () {
    $ajeno = auditPerson(['name' => 'Paciente Ajeno', 'contact_numbers' => []]);

    $res = step($this->publicPem, FlowToken::issue($this->convMama->id), 'PATIENT_SELECT', ['choice' => 'p:'.$ajeno->id]);

    expect($res['screen'])->toBe('PATIENT_SELECT')
        ->and($res['data']['error_message'])->toContain('Elegí una opción');
});

it('"Para mí" toma al propio contacto como paciente y sigue a la agenda', function () {
    $token = FlowToken::issue($this->convMama->id);

    $confirm = step($this->publicPem, $token, 'PATIENT_SELECT', ['choice' => 'self']);
    $next = step($this->publicPem, $token, 'CONFIRM', $confirm['data']);

    expect($next['screen'])->toBeIn(['SPECIALTY', 'SUMMARY'])
        ->and(FlowSession::get($token))->toMatchArray(['patient_id' => $this->mama->id, 'patient_status' => 'self']);
});

it('confirmar un paciente nuevo no lo da de alta ni vuelve a consultar SMD (Meta corta a los ~10 s)', function () {
    $token = FlowToken::issue($this->convMama->id);
    step($this->publicPem, $token, 'PATIENT_SELECT', ['choice' => 'other']);
    $confirm = step($this->publicPem, $token, 'PATIENT_DATA', ['full_name' => 'Nuevo Rapido', 'ci' => $this->ci, 'birthdate' => '', 'relation' => 'hijo']);
    $searches = count(auditSent('/patients'));

    step($this->publicPem, $token, 'CONFIRM', $confirm['data']);

    expect($searches)->toBeGreaterThan(0)
        ->and(count(auditSent('/patients')))->toBe($searches)
        ->and(DB::table('persons')->where('name', 'Nuevo Rapido')->exists())->toBeFalse()
        ->and(FlowSession::get($token)['pending_patient']['input'])->toMatchArray(['name' => 'Nuevo Rapido', 'ci' => $this->ci]);
});

it('al confirmar, una cédula distinta de la ya buscada se vuelve a buscar (no se confía en el cliente)', function () {
    $otro = auditPerson(['name' => 'Carlos Ajeno', 'contact_numbers' => []]);
    $otroCi = (string) ((int) $this->ci + 1);
    DB::table('attribute_values')->insert(['attribute_id' => $this->ciAttr, 'entity_id' => $otro->id, 'entity_type' => 'persons', 'text_value' => $otroCi]);

    $token = FlowToken::issue($this->convMama->id);
    step($this->publicPem, $token, 'PATIENT_SELECT', ['choice' => 'other']);
    $confirm = step($this->publicPem, $token, 'PATIENT_DATA', ['full_name' => 'Juan Perez', 'ci' => $this->ci, 'birthdate' => '', 'relation' => 'hijo']);

    // El cliente cambia la cédula en el CONFIRM: es de otra persona → no pasa.
    $res = step($this->publicPem, $token, 'CONFIRM', array_merge($confirm['data'], ['ci' => $otroCi]));

    expect($res['screen'])->toBe('CONFIRM')
        ->and(FlowSession::get($token)['pending_patient'] ?? null)->toBeNull();
});
