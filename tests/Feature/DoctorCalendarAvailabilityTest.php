<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una
 * transacción, así lo que el test crea se deshace al terminar.
 *
 * Creaba médicos y turnos.
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

    /*
     * El endpoint devuelve la hora como viene de la columna TIME: '09:00:00'.
     * El test esperaba '09:00' y nunca se notó porque el archivo moría antes.
     *
     * Se ajusta el test y no el endpoint: el calendario parsea con
     * split(':') y timeToMinutes(), así que los segundos no le molestan, y
     * recortarlos en la API sería un cambio de contrato sin motivo.
     */
    expect($first['start_time'] ?? null)->toBe('09:00:00');
    expect($first['end_time'] ?? null)->toBe('12:00:00');
});
