<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/*
 * La suite corre contra la base de PRODUCCIÓN.
 *
 * Estos tests vaciaban `specialties` y `doctor_specialty` con truncate para
 * tener una tabla limpia sobre la que contar. TRUNCATE es DDL: en MySQL provoca
 * un COMMIT implícito, así que ni siquiera envuelto en una transacción se puede
 * deshacer. Hoy TestCase lo bloquea.
 *
 * El reemplazo es un DELETE acotado dentro de la transacción del test: expresa
 * lo mismo —"partir de una tabla vacía"— y el rollback lo revierte.
 */
uses(DatabaseTransactions::class);

/** Deja las dos tablas vacías; el rollback del test lo deshace. */
function vaciarEspecialidades(): void
{
    DB::table('doctor_specialty')->whereNotNull('specialty_id')->delete();
    DB::table('specialties')->whereNotNull('id')->delete();
}

it('retrieves all specialties', function () {
    vaciarEspecialidades();

    /*
     * `code` y `area_of_knowledge` son NOT NULL sin default, y `code` es único.
     * Sin ellos las tres filas colisionaban con '' — un error que el truncate
     * tapaba, porque el test moría antes de llegar al insert.
     */
    DB::table('specialties')->insert([
        ['name' => 'Cardiología', 'slug' => 'cardiologia', 'code' => 'cardio', 'area_of_knowledge' => 'Medicina', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Pediatría', 'slug' => 'pediatria', 'code' => 'pedia', 'area_of_knowledge' => 'Medicina', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Dermatología', 'slug' => 'dermatologia', 'code' => 'derma', 'area_of_knowledge' => 'Medicina', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = get(route('api.specialties.index'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'name', 'slug'],
        ],
    ]);

    $names = array_column($response->json('data'), 'name');

    expect($response->json('data'))->toHaveCount(3)
        ->and($names)->toContain('Cardiología')
        ->and($names)->toContain('Pediatría')
        ->and($names)->toContain('Dermatología');
});

it('returns empty list when no specialties exist', function () {
    vaciarEspecialidades();

    get(route('api.specialties.index'))
        ->assertStatus(200)
        ->assertJson(['data' => []]);
});
