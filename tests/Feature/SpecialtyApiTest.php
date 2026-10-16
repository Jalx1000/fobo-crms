<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;


/*
| PELIGRO: este archivo hace DELETE/TRUNCATE sin transacción. Los tests corren
| contra la base de producción (no hay .env.testing), y el 16/10/2026 borró
| datos reales (valores de atributos de productos, doctor_specialty). Solo
| corre contra una base de prueba (nombre que termine en «_test» o «_testing»).
*/
beforeEach(function () {
    if (! preg_match('/_test(ing)?$/', (string) config('database.connections.'.config('database.default').'.database'))) {
        $this->markTestSkipped('Destructivo: solo contra una base de prueba.');
    }
});

it('retrieves all specialties', function () {
    // Clear specialties
    DB::table('doctor_specialty')->truncate();
    DB::table('specialties')->truncate();

    // Create test specialties
    DB::table('specialties')->insert([
        ['name' => 'Cardiología', 'slug' => 'cardiologia', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Pediatría', 'slug' => 'pediatria', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Dermatología', 'slug' => 'dermatologia', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = get(route('api.specialties.index'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'name', 'slug'],
        ],
    ]);

    $json = $response->json();
    expect($json['data'])->toHaveCount(3);

    // Check specific data
    $names = array_column($json['data'], 'name');
    expect($names)->toContain('Cardiología');
    expect($names)->toContain('Pediatría');
    expect($names)->toContain('Dermatología');
});

it('returns empty list when no specialties exist', function () {
    DB::table('doctor_specialty')->truncate();
    DB::table('specialties')->truncate();

    $response = get(route('api.specialties.index'));

    $response->assertStatus(200);
    $response->assertJson([
        'data' => [],
    ]);
});
