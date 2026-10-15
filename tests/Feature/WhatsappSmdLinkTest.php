<?php

/*
|--------------------------------------------------------------------------
| Contacto nuevo de WhatsApp → se busca primero en SMD y se registra con sus datos
|--------------------------------------------------------------------------
|
| Requisito (2026-10-12): cuando escribe un número desconocido, el CRM lo busca
| en ShareMeData por el número (con o sin código de país) y lo registra con los
| datos de SMD: nombre, cédula, fecha de nacimiento y smd_patient_id.
|
| SMD simulado con el router de la auditoría (tests/Support/SmdAuditHelpers.php).
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Services\ConversationResolver;

beforeEach(function () {
    $this->smd = auditBootFakes();

    config([
        'whatsapp.smd_link.enabled'   => true,
        'whatsapp.auto_create_person' => true,
    ]);

    // Un número de 8 dígitos que ningún contacto real tenga (la BD es la de producción).
    do {
        $this->local = '6'.rand(1000000, 9999999);
    } while (DB::table('persons')->where('contact_numbers', 'like', '%'.$this->local.'%')->exists());

    $this->wa = '591'.$this->local;
    $this->resolver = app(ConversationResolver::class);
});

/** Paciente con la forma que devuelve la búsqueda de SMD. */
function smdPatientRow(string $fullName, string $phone, array $extra = []): array
{
    return array_merge([
        '_id'      => bin2hex(random_bytes(12)),
        'fullName' => $fullName,
        'phone'    => $phone,
        'personID' => (string) rand(1000000, 9999999),
        'birthday' => '1990-04-15T04:00:00.000Z',
    ], $extra);
}

/** SMD devuelve `$patients` cuando se busca por los últimos 8 dígitos del número. */
function smdPhoneSearchReturns(ArrayObject $smd, string $local, array $patients): void
{
    $smd['search'] = fn (string $where) => $where === 'phone=/'.$local.'/' ? $patients : [];
}

function ciOf(int $personId): ?string
{
    $attr = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');

    return DB::table('attribute_values')->where('attribute_id', $attr)->where('entity_id', $personId)->value('text_value');
}

it('registra el contacto nuevo con los datos de SMD (nombre, cédula, smd_patient_id)', function () {
    $patient = smdPatientRow('ROSA MARIA QUISPE', $this->local, ['personID' => '7654321']);
    smdPhoneSearchReturns($this->smd, $this->local, [$patient]);

    $person = $this->resolver->resolveOrCreatePerson(new ContactIdentity(phone: $this->wa, name: 'Rosi'));

    expect($person->smd_patient_id)->toBe($patient['_id'])
        ->and($person->name)->toBe('ROSA MARIA QUISPE')
        ->and(ciOf($person->id))->toBe('7654321');
});

it('encuentra al paciente esté guardado en SMD con o sin código de país', function (string $comoEnSmd) {
    $phone = str_replace('LOCAL', $this->local, $comoEnSmd);
    $patient = smdPatientRow('PEDRO FORMATO TEST', $phone);
    smdPhoneSearchReturns($this->smd, $this->local, [$patient]);

    $person = $this->resolver->resolveOrCreatePerson(new ContactIdentity(phone: '+'.$this->wa, name: 'Pedro'));

    expect($person->smd_patient_id)->toBe($patient['_id']);
})->with([
    'sin código de país'          => ['LOCAL'],
    'con código de país'          => ['591LOCAL'],
    'con + y espacio'             => ['+591 LOCAL'],
]);

it('con varios pacientes en el número, desempata por el nombre del perfil (caso Indira)', function () {
    $jorge = smdPatientRow('JORGE AUZA PANTOJA (SISTEMA 2023)', $this->local);
    $indira = smdPatientRow('LIDIA INDIRA PANTOJA SOLARES (SISTEMA 2024)', $this->local);
    smdPhoneSearchReturns($this->smd, $this->local, [$jorge, $indira]);

    $person = $this->resolver->resolveOrCreatePerson(new ContactIdentity(phone: $this->wa, name: 'Indira'));

    expect($person->smd_patient_id)->toBe($indira['_id']);
});

it('con varios pacientes y un perfil que no alcanza para elegir, no vincula a ninguno', function (?string $perfil) {
    smdPhoneSearchReturns($this->smd, $this->local, [
        smdPatientRow('ANA PANTOJA RIOS', $this->local),
        smdPatientRow('ANA PANTOJA VACA', $this->local),
    ]);

    $person = $this->resolver->resolveOrCreatePerson(new ContactIdentity(phone: $this->wa, name: $perfil));

    expect($person)->not->toBeNull()
        ->and($person->smd_patient_id)->toBeNull();
})->with([
    'coincide con los dos'  => ['Ana Pantoja'],
    'no coincide con nadie' => ['Mamá ❤️'],
    'sin nombre de perfil'  => [null],
]);

it('si SMD no responde, crea el contacto sin vincular, lo registra en el log y lo vincula en el próximo mensaje', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message;
    });

    $this->smd['search'] = fn (string $where) => throw new RuntimeException('no usar');
    // SMD caído: la búsqueda de pacientes responde 503.
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
    Http::preventStrayRequests();
    Http::fake(['smd.test/*' => Http::response(['message' => 'down'], 503)]);

    $person = $this->resolver->resolveOrCreatePerson(new ContactIdentity(phone: $this->wa, name: 'Carla'));

    expect($person->smd_patient_id)->toBeNull()
        ->and(collect($logged)->contains(fn ($m) => str_contains($m, 'SMD no respondió')))->toBeTrue();

    // SMD vuelve: el siguiente mensaje del mismo número completa el vínculo.
    $patient = smdPatientRow('CARLA VUELVE TEST', $this->local);
    $this->smd = auditBootFakes();
    smdPhoneSearchReturns($this->smd, $this->local, [$patient]);

    $again = $this->resolver->resolveOrCreatePerson(new ContactIdentity(phone: $this->wa, name: 'Carla'));

    expect($again->id)->toBe($person->id)
        ->and($again->fresh()->smd_patient_id)->toBe($patient['_id']);
});

it('si el número no está en SMD, lo registra con el número y deja constancia en el log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message;
    });

    smdPhoneSearchReturns($this->smd, $this->local, []);

    $person = $this->resolver->resolveOrCreatePerson(new ContactIdentity(phone: $this->wa, name: null));

    expect($person->smd_patient_id)->toBeNull()
        ->and($person->name)->toBe($this->wa)
        ->and(collect($logged)->contains(fn ($m) => str_contains($m, 'no está en SMD')))->toBeTrue();
});
