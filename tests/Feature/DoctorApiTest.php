<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

it('retrieves doctor details with weekly schedule', function () {
    // Solo los datos de este test: la transacción los deshace al terminar.
    // (Antes vaciaba doctors, activities y más con TRUNCATE, en producción.)

    // La API solo mira reservas desde «ahora»: con el reloj a las 08:00 la
    // reserva de las 09:00 cuenta (antes el test solo pasaba de madrugada).
    $this->travelTo(Carbon::now()->setTime(8, 0));

    // Create Doctor
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Test',
        'email'      => 'test-'.uniqid().'@example.com',
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
