<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

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
