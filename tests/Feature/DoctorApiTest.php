<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/*
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 *
 * Este archivo truncaba doctors (73 filas reales), doctor_shifts (112) y
 * activities (29.760) para "limpiar". Nunca llegó a hacerlo porque fallaba una
 * línea antes por una foreign key de doctor_activities — o sea que lo único que
 * separaba a la suite de borrar el historial del CRM era una restricción de
 * integridad que nadie puso con ese fin. Hoy TestCase lo bloquea.
 *
 * No hacen falta: el test consulta por el $doctorId que él mismo creó, así que
 * lo que haya en la tabla no lo afecta.
 */
uses(DatabaseTransactions::class);

it('retrieves doctor details with weekly schedule', function () {
    // Create Doctor
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Test',
        'email'      => 'test@example.com',
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /*
     * El turno va MAÑANA, no hoy.
     *
     * El endpoint arma la agenda desde Carbon::now() y filtra las reservas con
     * whereBetween('schedule_from', [now, +6 días]), así que una cita de hoy a
     * las 09:00 desaparece de la consulta en cuanto el reloj pasa las 09:00: el
     * mismo test daba "booked" a la mañana y "available" a la tarde.
     *
     * Con una fecha futura el resultado no depende de la hora en que se corra
     * la suite.
     */
    $today = Carbon::tomorrow()->toDateString();
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
        'schedule_from' => Carbon::tomorrow()->setTime(9, 0)->format('Y-m-d H:i:s'),
        'schedule_to'   => Carbon::tomorrow()->setTime(9, 30)->format('Y-m-d H:i:s'),
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
