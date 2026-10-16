<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

it('retrieves all specialties', function () {
    // Solo las especialidades de este test (la transacción las deshace al terminar).
    $u = uniqid();
    DB::table('specialties')->insert([
        ['name' => 'Cardiología', 'slug' => "cardiologia-{$u}", 'code' => "cardiologia-{$u}", 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Pediatría', 'slug' => "pediatria-{$u}", 'code' => "pediatria-{$u}", 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Dermatología', 'slug' => "dermatologia-{$u}", 'code' => "dermatologia-{$u}", 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = get(route('api.specialties.index'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'name', 'slug'],
        ],
    ]);

    $json = $response->json();
    expect(collect($json['data'])->filter(fn ($e) => str_ends_with($e['slug'], $u)))->toHaveCount(3);

    // Check specific data
    $names = array_column($json['data'], 'name');
    expect($names)->toContain('Cardiología');
    expect($names)->toContain('Pediatría');
    expect($names)->toContain('Dermatología');
});

it('returns empty list when no specialties exist', function () {
    // DELETE (no TRUNCATE): dentro de la transacción se deshace al terminar.
    DB::table('doctor_specialty')->delete();
    DB::table('specialties')->delete();

    $response = get(route('api.specialties.index'));

    $response->assertStatus(200);
    $response->assertJson([
        'data' => [],
    ]);
});
