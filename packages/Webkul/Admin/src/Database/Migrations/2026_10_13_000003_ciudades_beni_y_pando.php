<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Beni y Pando se nombran por departamento (pedido del 13/10), no por su
 * capital: Trinidad → Beni, Cobija → Pando.
 *
 * Contacto y pedido: se renombra la opción, que conserva su id y con él los
 * valores ya guardados. Cotización: ya tenía Beni y Pando; las Trinidad y
 * Cobija que se le agregaron se pasan a esas y se borran.
 */
return new class extends Migration
{
    protected const RENOMBRAR = ['Trinidad' => 'Beni', 'Cobija' => 'Pando'];

    public function up(): void
    {
        foreach (DB::table('attributes')->where('code', 'ciudad')->get() as $atributo) {
            foreach (self::RENOMBRAR as $capital => $departamento) {
                $vieja = DB::table('attribute_options')->where('attribute_id', $atributo->id)->where('name', $capital)->first();

                if (! $vieja) {
                    continue;
                }

                $nueva = DB::table('attribute_options')->where('attribute_id', $atributo->id)->where('name', $departamento)->first();

                if (! $nueva) {
                    DB::table('attribute_options')->where('id', $vieja->id)->update(['name' => $departamento]);

                    continue;
                }

                DB::table('attribute_values')
                    ->where('attribute_id', $atributo->id)
                    ->where('integer_value', $vieja->id)
                    ->update(['integer_value' => $nueva->id]);

                DB::table('attribute_options')->where('id', $vieja->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: se perdería a qué capital correspondía cada valor.
    }
};
