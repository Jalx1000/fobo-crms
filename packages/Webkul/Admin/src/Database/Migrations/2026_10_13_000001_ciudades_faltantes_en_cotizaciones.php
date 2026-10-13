<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La ciudad de la cotización tenía menos opciones que la del contacto y la del
 * pedido: un cliente de Trinidad o Cobija no la podía pasar a su cotización.
 * Se agregan las que faltan, salvo `ciudades_sin_cotizacion` (El Alto,
 * Exterior). No se tocan ni se borran las que ya están.
 */
return new class extends Migration
{
    public function up(): void
    {
        $attributeId = DB::table('attributes')->where('entity_type', 'quotes')->where('code', 'ciudad')->value('id');

        if (! $attributeId) {
            return;
        }

        $excluidas = (array) config('cotizacion.ciudades_sin_cotizacion', []);

        $existentes = DB::table('attribute_options')->where('attribute_id', $attributeId)->pluck('name')->all();

        $orden = (int) DB::table('attribute_options')->where('attribute_id', $attributeId)->max('sort_order');

        foreach ((array) config('cotizacion.ciudades_cliente', []) as $ciudad) {
            if (in_array($ciudad, $existentes, true) || in_array($ciudad, $excluidas, true)) {
                continue;
            }

            DB::table('attribute_options')->insert([
                'name' => $ciudad,
                'sort_order' => ++$orden,
                'attribute_id' => $attributeId,
            ]);
        }
    }

    public function down(): void
    {
        // Las opciones pueden tener cotizaciones guardadas: no se borran.
    }
};
