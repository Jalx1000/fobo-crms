<?php

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
 */

uses(\Tests\TestCase::class)->in('Feature');

/**
 * ¿Los tests están apuntando a la base de producción?
 *
 * Sirve para saltear los tests que solo son correctos sobre una base vacía —
 * los que afirman totales absolutos ("la respuesta trae 2 productos") — en vez
 * de dejarlos borrar el catálogo para que su assert cierre.
 */
function apuntaAProduccion(): bool
{
    return DB::connection()->getDatabaseName() === 'kohlberg';
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
 */

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
 */

/**
 * Get default admin which is created on fresh instance.
 *
 * @return \Webkul\User\Models\User
 */
function getDefaultAdmin()
{
    $admin = \Webkul\User\Models\User::find(1);

    return $admin;
}

/**
 * Sanctum authenticated admin.
 *
 * @return \Webkul\User\Models\User
 */
function actingAsSanctumAuthenticatedAdmin()
{
    return \Laravel\Sanctum\Sanctum::actingAs(
        getDefaultAdmin(),
        ['*']
    );
}

/**
 * Get first name.
 *
 * @param  string  $fullName
 * @return string
 */
function getFirstName($fullName)
{
    return explode(' ', $fullName)[0];
}

/*
|--------------------------------------------------------------------------
| Atributo de ciudad
|--------------------------------------------------------------------------
|
| Vivía dentro de PersonListFilterTest y PersonExportTest lo llamaba desde su
| propio archivo. Las funciones sueltas de un test son globales para toda la
| suite, así que funcionaba sólo si Pest cargaba primero el archivo que la
| definía: corriendo PersonExportTest solo, "Call to undefined function".
|
| Un helper compartido va en el bootstrap compartido.
*/

/**
 * Ensures the "cliente_ciudad" select attribute (lookup to lead_pipelines)
 * exists for persons and returns its id.
 */
function ensureCityAttribute(): int
{
    $attribute = \Webkul\Attribute\Models\Attribute::where('code', 'cliente_ciudad')
        ->where('entity_type', 'persons')
        ->first();

    if (! $attribute) {
        $attribute = \Webkul\Attribute\Models\Attribute::create([
            'code'            => 'cliente_ciudad',
            'name'            => 'Ciudad',
            'type'            => 'select',
            'entity_type'     => 'persons',
            'lookup_type'     => 'lead_pipelines',
            'is_required'     => 0,
            'is_unique'       => 0,
            'validation'      => '',
            'sort_order'      => 1,
            'is_user_defined' => 1,
        ]);
    }

    return $attribute->id;
}

/*
 * Compartidos por PersonListFilterTest y PersonExportTest, por el mismo
 * motivo que ensureCityAttribute(): vivían en el primero y el segundo los
 * llamaba, así que el orden de carga decidía si el test corría o reventaba
 * con "Call to undefined function".
 */
function createPerson(string $name, $createdAt, int $attributeId, int $pipelineId): int
{
    $personId = createPersonWithoutCity($name, $createdAt);

    DB::table('attribute_values')->insert([
        'entity_type'   => 'persons',
        'attribute_id'  => $attributeId,
        'entity_id'     => $personId,
        'integer_value' => $pipelineId,
    ]);

    return $personId;
}

function createPersonWithoutCity(string $name, $createdAt): int
{
    return DB::table('persons')->insertGetId([
        'name'       => $name,
        'emails'     => json_encode([]),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * Resolves the "Sin ciudad" pipeline id, creating it if the environment
 * doesn't already have it.
 */
