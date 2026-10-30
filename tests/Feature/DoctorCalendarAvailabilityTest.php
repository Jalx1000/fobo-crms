<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

it('retorna disponibilidad de doctores en el calendario', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    // Crear doctor mínimo
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Prueba '.rand(100, 999),
        'number'     => null,
        'title'      => null,
        'unique_id'  => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $start = Carbon::now()->startOfWeek()->toDateString();
    $date = $start;

    // Crear disponibilidad (shifts) para el doctor
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $date,
        'start_time' => '09:00',
        'end_time'   => '12:00',
        'notes'      => 'Mañana',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $date,
        'start_time' => '15:00',
        'end_time'   => '18:00',
        'notes'      => 'Tarde',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Llamar endpoint del calendario
    $response = test()->get(route('admin.activities.get', [
        'view_type'      => 'calendar',
        'calendar_mode'  => 'doctor',
        'start'          => $start,
    ]));

    $response->assertStatus(200);
    $json = $response->json();

    expect($json)->toBeArray();
    expect($json)->toHaveKeys(['days', 'doctors', 'appointments', 'availability']);
    expect($json['availability'])->toBeArray();

    // Verificar que los bloques se transformaron en slots de 60 min
    // Bloque 1 (09:00-12:00) -> 3 slots
    // Bloque 2 (15:00-18:00) -> 3 slots
    $availForDoctorDay = array_values(array_filter($json['availability'], fn ($s) => (int) ($s['doctor_id'] ?? 0) === (int) $doctorId && ($s['date'] ?? '') === $date
    ));

    // Esperamos 6 slots en total para este día (3 mañana + 3 tarde)
    expect(count($availForDoctorDay))->toBe(6);

    // Verificar el primer slot de la mañana
    expect(substr($availForDoctorDay[0]['start_time'], 0, 5))->toBe('09:00');
    expect(substr($availForDoctorDay[0]['end_time'], 0, 5))->toBe('10:00');

    // Verificar el último slot de la mañana
    expect(substr($availForDoctorDay[2]['start_time'], 0, 5))->toBe('11:00');
    expect(substr($availForDoctorDay[2]['end_time'], 0, 5))->toBe('12:00');

    // Verificar el primer slot de la tarde
    expect(substr($availForDoctorDay[3]['start_time'], 0, 5))->toBe('15:00');
    expect(substr($availForDoctorDay[3]['end_time'], 0, 5))->toBe('16:00');
});
