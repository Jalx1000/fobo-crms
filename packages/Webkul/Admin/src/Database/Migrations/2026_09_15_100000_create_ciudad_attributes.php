<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La ciudad del cliente, en las tres entidades donde hace falta, y la sucursal
 * de la cotización pasada a los almacenes de verdad.
 *
 * Dos arreglos en uno porque son el mismo malentendido: el atributo `sucursal`
 * de cotizaciones era un select con ciudades adentro («Santa Cruz», «La Paz»…),
 * así que no había dónde anotar la sucursal -las tres plantas del módulo
 * Warehouse- ni dónde anotar la ciudad sin pisar la otra.
 *
 * Ahora:
 *   - `sucursal` (quotes) es un lookup a `warehouses`: las plantas se cargan y
 *     se renombran en Configuración → Almacenes, y no hay una lista paralela
 *     de opciones que se desincronice.
 *   - `ciudad` es un select nuevo, IGUAL en `persons`, `leads` y `quotes`: el
 *     mismo código y las mismas opciones en las tres, para que copiarla de una
 *     a otra sea una asignación y no una traducción.
 *
 * `persons.cliente_ciudad` se elimina: era un select sin opciones y con
 * `lookup_type = lead_pipelines` por error, nunca se usó (cero valores) y
 * dejarlo daría dos ciudades por contacto.
 */
return new class extends Migration
{
    protected const ENTIDADES = ['persons', 'leads', 'quotes'];

    public function up(): void
    {
        $now = Carbon::now();

        foreach (self::ENTIDADES as $entidad) {
            if (DB::table('attributes')->where('entity_type', $entidad)->where('code', 'ciudad')->exists()) {
                continue;
            }

            $id = DB::table('attributes')->insertGetId([
                'code'            => 'ciudad',
                'name'            => 'Ciudad',
                'type'            => 'select',
                'entity_type'     => $entidad,
                'lookup_type'     => null,
                'validation'      => null,
                'sort_order'      => 15,
                'is_required'     => 0,
                'is_unique'       => 0,
                'quick_add'       => 1,
                'is_user_defined' => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            $orden = 1;

            foreach ((array) config('cotizacion.ciudades_cliente', []) as $ciudad) {
                DB::table('attribute_options')->insert([
                    'name'         => $ciudad,
                    'sort_order'   => $orden++,
                    'attribute_id' => $id,
                ]);
            }
        }

        // La sucursal pasa a ser el almacén. Las opciones viejas eran ciudades
        // y no tenían un solo valor guardado: se borran con la conversión.
        $sucursal = DB::table('attributes')->where('entity_type', 'quotes')->where('code', 'sucursal')->first();

        if ($sucursal) {
            DB::table('attribute_options')->where('attribute_id', $sucursal->id)->delete();

            DB::table('attributes')->where('id', $sucursal->id)->update([
                'type'        => 'lookup',
                'lookup_type' => 'warehouses',
                'updated_at'  => $now,
            ]);
        }

        // El select roto que nadie llegó a usar.
        $viejo = DB::table('attributes')->where('entity_type', 'persons')->where('code', 'cliente_ciudad')->first();

        if ($viejo) {
            DB::table('attribute_values')->where('attribute_id', $viejo->id)->delete();
            DB::table('attribute_options')->where('attribute_id', $viejo->id)->delete();
            DB::table('attributes')->where('id', $viejo->id)->delete();
        }
    }

    public function down(): void
    {
        $ids = DB::table('attributes')
            ->whereIn('entity_type', self::ENTIDADES)
            ->where('code', 'ciudad')
            ->pluck('id');

        DB::table('attribute_values')->whereIn('attribute_id', $ids)->delete();
        DB::table('attribute_options')->whereIn('attribute_id', $ids)->delete();
        DB::table('attributes')->whereIn('id', $ids)->delete();

        $sucursal = DB::table('attributes')->where('entity_type', 'quotes')->where('code', 'sucursal')->first();

        if ($sucursal) {
            DB::table('attribute_values')->where('attribute_id', $sucursal->id)->delete();

            DB::table('attributes')->where('id', $sucursal->id)->update([
                'type'        => 'select',
                'lookup_type' => null,
            ]);

            $orden = 1;

            foreach (['Santa Cruz', 'La Paz', 'Cochabamba', 'Otra'] as $ciudad) {
                DB::table('attribute_options')->insert([
                    'name'         => $ciudad,
                    'sort_order'   => $orden++,
                    'attribute_id' => $sucursal->id,
                ]);
            }
        }
    }
};
