<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La direccion de facturacion deja de ser obligatoria en las cotizaciones.
     *
     * El AttributeSeeder solo corre en instalaciones nuevas, asi que la fila ya
     * existente en produccion hay que tocarla aca. El componente `address`
     * propaga `is_required` a sus cinco subcampos (direccion, pais, estado,
     * ciudad y codigo postal), de modo que este unico flag los libera a todos.
     */
    public function up()
    {
        DB::table('attributes')
            ->where('entity_type', 'quotes')
            ->where('code', 'billing_address')
            ->update(['is_required' => 0]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('attributes')
            ->where('entity_type', 'quotes')
            ->where('code', 'billing_address')
            ->update(['is_required' => 1]);
    }
};
