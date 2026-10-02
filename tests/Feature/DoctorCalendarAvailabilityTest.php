<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
 * Dentro de una transacción: la suite corre contra la base REAL.
 *
 * Sin esto, cada corrida dejaba filas permanentes. Medido: 17 personas "Test
 * Person", 7 "Export City Prospect" y 3 pipelines "Bogotá Export" acumulados en
 * producción, que además aparecían en los selectores del Punto de Distribuidor y
 * en el ruteo de derivaciones de WhatsApp.
 */
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

    // Verificar que existen los dos bloques de disponibilidad creados
    $availForDoctorDay = array_values(array_filter($json['availability'], fn ($s) => (int) ($s['doctor_id'] ?? 0) === (int) $doctorId && ($s['date'] ?? '') === $date
    ));

    expect(count($availForDoctorDay))->toBeGreaterThanOrEqual(2);
    $first = $availForDoctorDay[0];
    expect($first['start_time'] ?? null)->toBe('09:00');
    expect($first['end_time'] ?? null)->toBe('12:00');
});
