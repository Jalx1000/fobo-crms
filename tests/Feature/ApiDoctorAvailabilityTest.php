<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

it('debe devolver la disponibilidad de doctores en formato de slots discretos', function () {
    // 1. Setup: Crear un doctor de prueba
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. API Test '.uniqid(),
        'email'      => 'api.test.'.uniqid().'@odontoking.com',
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Setup: Crear un turno (shift) de 2 horas para hoy
    $today = Carbon::now()->toDateString();
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $today,
        'start_time' => '08:00:00',
        'end_time'   => '10:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Ejecutar: Llamar al endpoint público de doctores
    $response = get('/api/doctors');

    $response->assertStatus(200);
    $data = $response->json('data');

    // Si no aparece por paginación, forzamos el límite
    if (! collect($data)->firstWhere('id', $doctorId)) {
        $response = get('/api/doctors?limit=100');
        $data = $response->json('data');
    }

    $testDoctor = collect($data)->firstWhere('id', $doctorId);

    expect($testDoctor)->not->toBeNull();
    expect($testDoctor)->toHaveKey('availability');

    // 4. Validar: El bloque de 2 horas (08:00-10:00) debe haberse convertido en 2 slots de 60 min
    $availability = $testDoctor['availability'];

    // 08:00-09:00, 09:00-10:00
    expect(count($availability))->toBe(2);

    // Validar el primer slot
    expect($availability[0]['start_time'])->toBe('08:00:00');
    expect($availability[0]['end_time'])->toBe('09:00:00');

    // Validar el último slot
    expect($availability[1]['start_time'])->toBe('09:00:00');
    expect($availability[1]['end_time'])->toBe('10:00:00');
});
