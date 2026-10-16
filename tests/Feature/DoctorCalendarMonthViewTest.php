<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


/*
| PELIGRO: este archivo escribe sin transacción. Los tests corren contra la
| base de producción (no hay .env.testing), y el 16/10/2026 dejó datos de
| prueba en producción. Solo corre contra una base de prueba (nombre que
| termine en «_test» o «_testing»).
*/
beforeEach(function () {
    if (! preg_match('/_test(ing)?$/', (string) config('database.connections.'.config('database.default').'.database'))) {
        $this->markTestSkipped('Escribe sin transacción: solo contra una base de prueba.');
    }
});

it('retorna datos para vista mensual con days/doctors/appointments/availability', function () {
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    // Crear doctor
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. Mes '.rand(100, 999),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Cursor de mes
    $cursor = Carbon::now()->startOfMonth()->toDateString();

    // Disponibilidad en el mes
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $cursor,
        'start_time' => '08:00',
        'end_time'   => '12:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Llamar endpoint con calendar_view=month
    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'month',
        'start'         => $cursor,
    ]));

    $response->assertStatus(200);
    $json = $response->json();

    expect($json)->toBeArray();
    expect($json)->toHaveKeys(['days', 'doctors', 'appointments', 'availability', 'calendar_view']);
    expect($json['calendar_view'])->toBe('month');
    expect(is_array($json['days']))->toBeTrue();
    expect(count($json['days']))->toBeGreaterThanOrEqual(28);

    $availForMonth = array_values(array_filter($json['availability'], fn ($s) => (int) ($s['doctor_id'] ?? 0) === (int) $doctorId
    ));
    expect(count($availForMonth))->toBeGreaterThanOrEqual(1);
});
