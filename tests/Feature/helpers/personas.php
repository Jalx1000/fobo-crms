<?php

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;

/*
| Helpers de contactos compartidos por PersonListFilterTest y PersonExportTest.
| Antes vivían en PersonListFilterTest y PersonExportTest solo funcionaba si
| Pest cargaba los dos archivos juntos.
*/

/**
 * El atributo `ciudad` de los contactos (un select con opciones por nombre:
 * Santa Cruz, La Paz…). Reemplazó a `cliente_ciudad` el 15/09/2026
 * (migración create_ciudad_attributes). Devuelve su id.
 */
function ensureCityAttribute(): int
{
    return (int) Attribute::where('code', 'ciudad')->where('entity_type', 'persons')->value('id');
}

/**
 * Un contacto de la ciudad del pipeline $pipelineId: la ciudad viaja por
 * NOMBRE (pipeline «La Paz» = opción «La Paz» del atributo `ciudad`).
 */
function createPerson(string $name, $createdAt, int $attributeId, int $pipelineId): int
{
    $personId = createPersonWithoutCity($name, $createdAt);

    $ciudad = DB::table('lead_pipelines')->where('id', $pipelineId)->value('name');

    expect(app(\Webkul\Admin\Services\CiudadCliente::class)->escribir('persons', $personId, (string) $ciudad))
        ->toBeTrue("No hay una opción de ciudad para el pipeline «{$ciudad}».");

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
