<?php

/*
|--------------------------------------------------------------------------
| Pacientes agendados por terceros: identidad por cédula + responsables
|--------------------------------------------------------------------------
| planning/14-pacientes-terceros/00.diseno.md
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\PatientIdentityResolver;

beforeEach(function () {
    $this->smd = auditBootFakes();
    $this->resolver = app(PatientIdentityResolver::class);
    $this->ciAttr = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');

    // Una cédula que nadie tenga en la BD (es la de producción).
    do {
        $this->ci = (string) rand(100000000, 999999999);
    } while (DB::table('attribute_values')->where('attribute_id', $this->ciAttr)->where('text_value', 'like', '%'.$this->ci.'%')->exists());

    $this->mama = auditPerson(['name' => 'Mama Responsable '.uniqid()]);
    $this->papa = auditPerson(['name' => 'Papa Responsable '.uniqid()]);
});

function personWithCi(string $name, string $ci, array $attrs = []): int
{
    $person = auditPerson(array_merge(['name' => $name, 'contact_numbers' => []], $attrs));

    DB::table('attribute_values')->insert([
        'attribute_id' => DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id'),
        'entity_id'    => $person->id,
        'entity_type'  => 'persons',
        'text_value'   => $ci,
    ]);

    return $person->id;
}

function relationsOf(int $patientId): array
{
    return DB::table('person_relations')->where('patient_person_id', $patientId)->orderBy('id')->pluck('responsible_person_id')->all();
}

it('normaliza la cédula: puntos, espacios, expedido y complemento', function (string $raw, string $esperado) {
    expect($this->resolver->normalizeCi($raw))->toBe($esperado);
})->with([
    ['1.234.567 LP', '1234567'],
    ['1234567 sc', '1234567'],
    ['1234567-1a', '1234567-1A'],
    [' 7654321 ', '7654321'],
]);

it('compara nombres en cualquier sentido, sin acentos ni sufijo de SMD', function (string $a, string $b, bool $igual) {
    expect($this->resolver->sameName($a, $b))->toBe($igual);
})->with([
    ['Juan Pérez', 'JUAN PEREZ LOPEZ (SISTEMA 2024)', true],
    ['JUAN PEREZ LOPEZ', 'juan perez', true],
    ['Juan Pérez', 'Maria Pérez', false],
    ['Juan', '', false],
]);

it('dos padres desde números distintos llegan a la MISMA ficha del hijo (caso del requisito)', function () {
    $hijo = personWithCi('Juan Perez Lopez', $this->ci);
    $personasAntes = DB::table('persons')->count();

    $lunes = $this->resolver->commit($this->mama->id, ['ci' => $this->ci, 'name' => 'Juan Pérez', 'relation' => 'hijo']);
    $jueves = $this->resolver->commit($this->papa->id, ['ci' => $this->ci.' LP', 'name' => 'JUAN PEREZ LOPEZ', 'relation' => 'hijo']);

    expect($lunes)->toMatchArray(['status' => 'found', 'patient_id' => $hijo])
        ->and($jueves)->toMatchArray(['status' => 'found', 'patient_id' => $hijo])
        ->and(DB::table('persons')->count())->toBe($personasAntes)
        ->and(relationsOf($hijo))->toBe([$this->mama->id, $this->papa->id]);
});

it('la misma persona agendando dos veces no duplica el vínculo', function () {
    $hijo = personWithCi('Ana Rios', $this->ci);

    $this->resolver->commit($this->mama->id, ['ci' => $this->ci, 'name' => 'Ana Rios', 'relation' => 'hijo']);
    $this->resolver->commit($this->mama->id, ['ci' => $this->ci, 'name' => 'Ana Rios', 'relation' => 'hijo']);

    expect(relationsOf($hijo))->toBe([$this->mama->id]);
});

it('si la cédula está con otro nombre, no vincula, no crea y no revela el nombre', function () {
    personWithCi('Carlos Mendez', $this->ci);
    $personasAntes = DB::table('persons')->count();

    $lookup = $this->resolver->lookup($this->ci, 'Juan Perez');
    $commit = $this->resolver->commit($this->mama->id, ['ci' => $this->ci, 'name' => 'Juan Perez']);

    expect($lookup)->toBe(['status' => 'mismatch', 'ci' => $this->ci])
        ->and($commit)->toBe(['status' => 'mismatch'])
        ->and(DB::table('persons')->count())->toBe($personasAntes)
        ->and(DB::table('person_relations')->where('responsible_person_id', $this->mama->id)->exists())->toBeFalse();
});

it('si no está en el CRM pero sí en SMD, lo crea con los datos de SMD y sin teléfono', function () {
    $smdId = bin2hex(random_bytes(12));
    $this->smd['search'] = fn (string $where) => $where === 'personID=/'.$this->ci.'/'
        ? [['_id' => $smdId, 'fullName' => 'LUCIA FERNANDEZ ROCA (SISTEMA 2024)', 'personID' => $this->ci, 'birthday' => '2015-03-02T04:00:00.000Z']]
        : [];

    $result = $this->resolver->commit($this->mama->id, ['ci' => $this->ci, 'name' => 'Lucia Fernandez', 'relation' => 'hijo']);

    $hija = DB::table('persons')->find($result['patient_id']);

    expect($result['status'])->toBe('created')
        ->and($hija->smd_patient_id)->toBe($smdId)
        ->and($hija->name)->toBe('LUCIA FERNANDEZ ROCA (SISTEMA 2024)')
        ->and(json_decode($hija->contact_numbers, true))->toBe([])
        ->and(relationsOf($hija->id))->toBe([$this->mama->id]);
});

it('si SMD lo tiene y ya está en el CRM por smd_patient_id sin cédula, usa esa ficha y le carga la cédula', function () {
    $smdId = bin2hex(random_bytes(12));
    $existente = auditPerson(['name' => 'Pedro Gomez Vaca', 'smd_patient_id' => $smdId, 'contact_numbers' => []]);
    $this->smd['search'] = fn (string $where) => $where === 'personID=/'.$this->ci.'/'
        ? [['_id' => $smdId, 'fullName' => 'PEDRO GOMEZ VACA', 'personID' => $this->ci]]
        : [];

    $result = $this->resolver->commit($this->papa->id, ['ci' => $this->ci, 'name' => 'Pedro Gomez', 'relation' => 'hijo']);

    expect($result)->toMatchArray(['status' => 'found', 'patient_id' => $existente->id])
        ->and(DB::table('attribute_values')->where('attribute_id', $this->ciAttr)->where('entity_id', $existente->id)->value('text_value'))->toBe($this->ci);
});

it('si no está en ningún lado, lo crea con el nombre, la cédula y la fecha de nacimiento dados', function () {
    $result = $this->resolver->commit($this->mama->id, [
        'ci' => $this->ci, 'name' => 'Sofia Nueva Paciente', 'birthdate' => '2018-07-20', 'relation' => 'hijo',
    ]);

    expect($result['status'])->toBe('created')
        ->and(DB::table('persons')->where('id', $result['patient_id'])->value('name'))->toBe('Sofia Nueva Paciente')
        ->and(DB::table('attribute_values')->where('attribute_id', $this->ciAttr)->where('entity_id', $result['patient_id'])->value('text_value'))->toBe($this->ci);
});

it('rechaza cédulas inválidas o sin nombre', function (string $ci, string $name) {
    expect($this->resolver->commit($this->mama->id, ['ci' => $ci, 'name' => $name]))->toBe(['status' => 'invalid']);
})->with([
    ['12', 'Juan Perez'],
    ['abc', 'Juan Perez'],
    ['1234567', '   '],
]);

it('si el responsable pone su propia cédula, no se vincula consigo mismo', function () {
    DB::table('attribute_values')->insert([
        'attribute_id' => $this->ciAttr,
        'entity_id'    => $this->mama->id,
        'entity_type'  => 'persons',
        'text_value'   => $this->ci,
    ]);

    $result = $this->resolver->commit($this->mama->id, ['ci' => $this->ci, 'name' => $this->mama->name]);

    expect($result['status'])->toBe('self')
        ->and(DB::table('person_relations')->where('responsible_person_id', $this->mama->id)->exists())->toBeFalse();
});

it('lista los pacientes a cargo de un responsable', function () {
    $a = personWithCi('Hijo Uno Test', $this->ci);
    $b = personWithCi('Hijo Dos Test', $this->ci.'-1A');
    $this->resolver->link($a, $this->mama->id, 'hijo', 'test');
    $this->resolver->link($b, $this->mama->id, 'hijo', 'test');

    expect(collect($this->resolver->patientsOf($this->mama->id))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a, $b])->sort()->values()->all());
});
