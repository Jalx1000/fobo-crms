<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/*
 * Dentro de una transacción: la suite corre contra la base REAL.
 *
 * Sin esto, cada corrida dejaba filas permanentes. Medido: 17 personas "Test
 * Person", 7 "Export City Prospect" y 3 pipelines "Bogotá Export" acumulados en
 * producción, que además aparecían en los selectores del Punto de Distribuidor y
 * en el ruteo de derivaciones de WhatsApp.
 */
uses(DatabaseTransactions::class);

it('retrieves doctor details with weekly schedule', function () {
    // Clear tables
    DB::table('doctor_specialty')->truncate();
    DB::table('doctors')->truncate();
    DB::table('doctor_shifts')->truncate();
    DB::table('activities')->truncate();
    DB::table('doctor_activities')->truncate();

    // Create Doctor
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Test',
        'email'      => 'test@example.com',
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create Shift for today
    $today = Carbon::now()->toDateString();
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $today,
        'start_time' => '09:00:00',
        'end_time'   => '10:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create Booking (Activity) for first 30 mins
    $activityId = DB::table('activities')->insertGetId([
        'title'         => 'Test Appointment',
        'type'          => 'appointment',
        'schedule_from' => Carbon::now()->setTime(9, 0)->format('Y-m-d H:i:s'),
        'schedule_to'   => Carbon::now()->setTime(9, 30)->format('Y-m-d H:i:s'),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('doctor_activities')->insert([
        'doctor_id'   => $doctorId,
        'activity_id' => $activityId,
    ]);

    $response = get(route('api.doctors.show', $doctorId));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'id', 'name', 'schedule' => [
            '*' => ['date', 'slots' => [
                '*' => ['start_time', 'end_time', 'status'],
            ]],
        ],
    ]);

    $schedule = $response->json('schedule');
    expect($schedule)->toHaveCount(7);

    // Verify today's slots
    $todaySchedule = collect($schedule)->firstWhere('date', $today);
    expect($todaySchedule)->not->toBeNull();

    $slots = $todaySchedule['slots'];
    expect($slots)->toHaveCount(2); // 09:00-09:30, 09:30-10:00

    expect($slots[0]['start_time'])->toBe('09:00');
    expect($slots[0]['status'])->toBe('booked'); // First slot booked

    expect($slots[1]['start_time'])->toBe('09:30');
    expect($slots[1]['status'])->toBe('available'); // Second slot available
});

it('returns 404 for non-existent doctor', function () {
    $response = get(route('api.doctors.show', 99999));
    $response->assertStatus(404);
});

it('returns 400 for invalid doctor id format', function () {
    $response = get(route('api.doctors.show', 'invalid-id'));
    $response->assertStatus(400);
});
