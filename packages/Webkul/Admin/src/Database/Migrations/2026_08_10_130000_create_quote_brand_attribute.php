<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Atributo "Marca" en cotizaciones: define con que logo y color se imprime
     * el PDF.
     *
     * Va como `is_user_defined = 1` a proposito: con esa bandera el bloque de
     * atributos personalizados de `quotes/create.blade.php` y `edit.blade.php`
     * lo renderiza solo, sin tocar las vistas.
     *
     * El nombre de cada opcion se convierte a slug (`Magia Verde` -> `magia_verde`)
     * para resolver la clave de configuracion en el PDF, asi que renombrar una
     * opcion desde el panel rompe el vinculo con su logo.
     */
    public function up()
    {
        $now = Carbon::now();

        $attributeId = DB::table('attributes')->insertGetId([
            'code'            => 'marca',
            'name'            => 'Marca',
            'type'            => 'select',
            'entity_type'     => 'quotes',
            'lookup_type'     => null,
            'validation'      => null,
            'sort_order'      => '14',
            'is_required'     => '0',
            'is_unique'       => '0',
            'quick_add'       => '1',
            'is_user_defined' => '1',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        DB::table('attribute_options')->insert([
            [
                'name'         => 'Imprimir',
                'sort_order'   => 1,
                'attribute_id' => $attributeId,
            ], [
                'name'         => 'Magia Verde',
                'sort_order'   => 2,
                'attribute_id' => $attributeId,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $attributeId = DB::table('attributes')
            ->where('entity_type', 'quotes')
            ->where('code', 'marca')
            ->value('id');

        if (! $attributeId) {
            return;
        }

        // attribute_options y attribute_values tienen foreign key con
        // onDelete('cascade') sobre attributes, asi que se van solos.
        DB::table('attributes')->where('id', $attributeId)->delete();
    }
};
