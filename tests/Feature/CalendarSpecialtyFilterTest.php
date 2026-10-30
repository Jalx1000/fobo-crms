<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
| Filtro por especialidad del calendario: el endpoint devuelve las especialidades
| con algún doctor activo y, en cada doctor, sus specialty_ids. El front acota con
| eso los doctores que ofrece (doctorsInSpecialty).
*/

uses(DatabaseTransactions::class);

function calendarSpecialtyPayload(): array
{
    test()->actingAs(getDefaultAdmin());

    return test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'start'         => Carbon::now()->startOfWeek()->toDateString(),
    ]))->assertOk()->json();
}

function makeSpecialty(string $prefix): int
{
    return DB::table('specialties')->insertGetId([
        'name' => $prefix.' '.uniqid(), 'slug' => 'zf-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function makeCalendarDoctor(array $attrs = []): int
{
    return DB::table('doctors')->insertGetId(array_merge([
        'name' => 'Dr. Filtro '.uniqid(), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

it('cada doctor trae sus especialidades', function () {
    $orto = makeSpecialty('Zf Ortodoncia');
    $endo = makeSpecialty('Zf Endodoncia');
    $doctor = makeCalendarDoctor();
    $sinEspecialidad = makeCalendarDoctor();

    DB::table('doctor_specialty')->insert([
        ['doctor_id' => $doctor, 'specialty_id' => $orto],
        ['doctor_id' => $doctor, 'specialty_id' => $endo],
    ]);

    $doctors = collect(calendarSpecialtyPayload()['doctors'])->keyBy('id');

    expect($doctors[$doctor]['specialty_ids'])->toEqualCanonicalizing([$orto, $endo])
        ->and($doctors[$sinEspecialidad]['specialty_ids'])->toBe([]);
});

it('solo ofrece especialidades que tienen algún doctor activo', function () {
    $conDoctor = makeSpecialty('Zf Con doctor');
    $soloInactivo = makeSpecialty('Zf Solo inactivo');
    $sinDoctor = makeSpecialty('Zf Sin doctor');

    DB::table('doctor_specialty')->insert([
        ['doctor_id' => makeCalendarDoctor(), 'specialty_id' => $conDoctor],
        ['doctor_id' => makeCalendarDoctor(['is_active' => 0]), 'specialty_id' => $soloInactivo],
    ]);

    $ids = collect(calendarSpecialtyPayload()['specialties'])->pluck('id');

    expect($ids)->toContain($conDoctor)
        ->not->toContain($soloInactivo)
        ->not->toContain($sinDoctor);
});

it('las especialidades vienen ordenadas por nombre y solo con id y nombre', function () {
    $specialties = calendarSpecialtyPayload()['specialties'];

    $names = array_column($specialties, 'name');
    $sorted = $names;
    sort($sorted, SORT_STRING | SORT_FLAG_CASE);

    expect($names)->toBe($sorted);

    foreach ($specialties as $specialty) {
        expect(array_keys($specialty))->toBe(['id', 'name']);
    }
});
