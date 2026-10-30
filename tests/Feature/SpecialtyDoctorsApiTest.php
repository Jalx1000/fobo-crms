<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

/**
 * Crea una especialidad y devuelve [id, slug, name].
 */
function makeSpecialty(): array
{
    $name = 'Spec Doctors '.uniqid();
    $slug = Str::slug($name);

    $id = DB::table('specialties')->insertGetId([
        'name'       => $name,
        'slug'       => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$id, $slug, $name];
}

/**
 * Crea un doctor activo/inactivo y lo asocia a la especialidad. Devuelve el id.
 */
function makeDoctor(int $specialtyId, bool $active = true): int
{
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Spec '.uniqid(),
        'email'      => 'spec.'.uniqid().'@example.com',
        'unique_id'  => 'UID-'.uniqid(),
        'is_active'  => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('doctor_specialty')->insert([
        'doctor_id'    => $doctorId,
        'specialty_id' => $specialtyId,
    ]);

    return $doctorId;
}

function attributeIdByCode(string $code): int
{
    return (int) DB::table('attributes')->where('entity_type', 'doctors')->where('code', $code)->value('id');
}

function optionIdByName(int $attributeId, string $name): int
{
    return (int) DB::table('attribute_options')->where('attribute_id', $attributeId)->where('name', $name)->value('id');
}

function setDoctorAttr(int $doctorId, int $attributeId, array $columns): void
{
    DB::table('attribute_values')->insert(array_merge([
        'entity_type'  => 'doctors',
        'entity_id'    => $doctorId,
        'attribute_id' => $attributeId,
        'unique_id'    => $doctorId.'-'.$attributeId,
    ], $columns));
}

function addShift(int $doctorId, string $date): void
{
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $date,
        'start_time' => '09:00:00',
        'end_time'   => '11:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('returns active doctors when searching specialty by ID', function () {
    [$specialtyId] = makeSpecialty();
    $d1 = makeDoctor($specialtyId, true);
    $d2 = makeDoctor($specialtyId, true);

    $response = get(route('api.specialties.doctors', $specialtyId));

    $response->assertStatus(200);
    expect($response->json('specialty.id'))->toBe($specialtyId);
    expect($response->json('meta.total'))->toBe(2);

    $returnedIds = array_column($response->json('data'), 'id');
    expect($returnedIds)->toEqualCanonicalizing([$d1, $d2]);
});

it('resolves specialty by slug and by name', function () {
    [$specialtyId, $slug, $name] = makeSpecialty();
    $doctorId = makeDoctor($specialtyId, true);

    expect(get(route('api.specialties.doctors', $slug))->json('data.0.id'))->toBe($doctorId);
    expect(get(route('api.specialties.doctors', substr($name, 0, 15)))->json('data.0.id'))->toBe($doctorId);
});

it('excludes inactive doctors', function () {
    [$specialtyId] = makeSpecialty();
    $active = makeDoctor($specialtyId, true);
    $inactive = makeDoctor($specialtyId, false);

    $response = get(route('api.specialties.doctors', $specialtyId));

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    $ids = array_column($response->json('data'), 'id');
    expect($ids)->toContain($active);
    expect($ids)->not->toContain($inactive);
});

it('returns the expected summarized fields with attribute labels', function () {
    [$specialtyId] = makeSpecialty();
    $doctorId = makeDoctor($specialtyId, true);

    $ageMinAttr = attributeIdByCode('age_range_min');
    $ageMaxAttr = attributeIdByCode('age_range_max');
    $serviceAttr = attributeIdByCode('type_service_doctor');
    $patientAttr = attributeIdByCode('attendsPatientType');

    setDoctorAttr($doctorId, $ageMinAttr, ['text_value' => '5']);
    setDoctorAttr($doctorId, $ageMaxAttr, ['text_value' => '90']);

    $svc1 = optionIdByName($serviceAttr, 'PROTESIS FIJA');
    $svc2 = optionIdByName($serviceAttr, 'ORTODONCIA FIJA');
    setDoctorAttr($doctorId, $serviceAttr, ['text_value' => $svc1.','.$svc2]);

    $patientOpt = optionIdByName($patientAttr, 'Pacientes nuevos');
    setDoctorAttr($doctorId, $patientAttr, ['integer_value' => $patientOpt]);

    $response = get(route('api.specialties.doctors', $specialtyId));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id', 'name', 'unique_id', 'age_range_min', 'age_range_max',
                'type_service_doctor', 'attendsPatientType',
                'available_7d', 'available_14d', 'available_30d',
            ],
        ],
    ]);

    $doctor = $response->json('data.0');
    expect($doctor['age_range_min'])->toBe('5');
    expect($doctor['age_range_max'])->toBe('90');
    expect($doctor['type_service_doctor'])->toEqualCanonicalizing(['PROTESIS FIJA', 'ORTODONCIA FIJA']);
    expect($doctor['attendsPatientType'])->toBe('Pacientes nuevos');
});

it('returns null for doctors without attribute values', function () {
    [$specialtyId] = makeSpecialty();
    makeDoctor($specialtyId, true);

    $doctor = get(route('api.specialties.doctors', $specialtyId))->json('data.0');

    expect($doctor['age_range_min'])->toBeNull();
    expect($doctor['age_range_max'])->toBeNull();
    expect($doctor['type_service_doctor'])->toBeNull();
    expect($doctor['attendsPatientType'])->toBeNull();
});

it('computes availability booleans for 7, 14 and 30 day windows', function () {
    [$specialtyId] = makeSpecialty();

    // Doctor con turno hoy → disponible en las tres ventanas.
    $soon = makeDoctor($specialtyId, true);
    addShift($soon, Carbon::now()->toDateString());

    // Doctor con turno dentro de 20 días → solo disponible a 30 días.
    $later = makeDoctor($specialtyId, true);
    addShift($later, Carbon::now()->addDays(20)->toDateString());

    // Doctor sin turnos → no disponible en ninguna ventana.
    $none = makeDoctor($specialtyId, true);

    $data = collect(get(route('api.specialties.doctors', $specialtyId))->json('data'))->keyBy('id');

    expect($data[$soon]['available_7d'])->toBeTrue();
    expect($data[$soon]['available_14d'])->toBeTrue();
    expect($data[$soon]['available_30d'])->toBeTrue();

    expect($data[$later]['available_7d'])->toBeFalse();
    expect($data[$later]['available_14d'])->toBeFalse();
    expect($data[$later]['available_30d'])->toBeTrue();

    expect($data[$none]['available_7d'])->toBeFalse();
    expect($data[$none]['available_30d'])->toBeFalse();
});

it('returns 404 for a non-existent specialty', function () {
    $response = get(route('api.specialties.doctors', 'inexistente-'.uniqid()));

    $response->assertStatus(404);
    expect($response->json('message'))->toBe('Specialty not found');
});

it('returns an empty list for a specialty without doctors', function () {
    [$specialtyId] = makeSpecialty();

    $response = get(route('api.specialties.doctors', $specialtyId));

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(0);
    expect($response->json('data'))->toBe([]);
});
