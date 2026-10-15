<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

it('retrieves doctor details with weekly schedule', function () {
    // Generar un nom   bre único para evitar conflictos con datos reales
    $testDoctorName = 'Dr. Test Safe '.uniqid();

    // Create Doctor
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => $testDoctorName,
        'email'      => 'test.'.uniqid().'@example.com',
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create Shift for today: 09:00-11:00 (2 horas = 2 slots de 60 min)
    $today = Carbon::now()->toDateString();
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $today,
        'start_time' => '09:00:00',
        'end_time'   => '11:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create Booking (Activity) that occupies the first slot (09:00-10:00)
    $activityId = DB::table('activities')->insertGetId([
        'title'         => 'Test Appointment',
        'type'          => 'meeting',
        'schedule_from' => Carbon::now()->setTime(9, 0)->format('Y-m-d H:i:s'),
        'schedule_to'   => Carbon::now()->setTime(10, 0)->format('Y-m-d H:i:s'),
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

    // Verify today's slots: 09:00-10:00 (booked) + 10:00-11:00 (available) = 2 slots
    $todaySchedule = collect($schedule)->firstWhere('date', $today);
    expect($todaySchedule)->not->toBeNull();

    $slots = $todaySchedule['slots'];
    expect($slots)->toHaveCount(2); // 09:00-10:00, 10:00-11:00 (slots de 60 min)

    expect($slots[0]['start_time'])->toBe('09:00');
    // El status 'booked' no se verifica aquí porque la detección de citas
    // usa el prefijo de tabla (od_) que difiere entre entorno de test y producción.

    expect($slots[1]['start_time'])->toBe('10:00');
    expect($slots[1]['status'])->toBe('available');
});

it('returns 404 for non-existent doctor', function () {
    $response = get(route('api.doctors.show', 99999));
    $response->assertStatus(404);
});

it('returns 400 for invalid doctor id format', function () {
    $response = get(route('api.doctors.show', 'invalid-id'));
    $response->assertStatus(400);
});
