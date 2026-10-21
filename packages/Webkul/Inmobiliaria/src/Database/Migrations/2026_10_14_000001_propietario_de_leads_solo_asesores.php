<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El selector «Propietario de Ventas» de los leads pasa a mostrar solo asesores.
 *
 * Se cambia el lookup del atributo y no el lookup `users` en sí: `users` lo
 * usan también contactos y organizaciones, que no se pidió tocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('attributes')
            ->where('entity_type', 'leads')
            ->where('code', 'user_id')
            ->where('lookup_type', 'users')
            ->update(['lookup_type' => 'asesores']);
    }

    public function down(): void
    {
        DB::table('attributes')
            ->where('entity_type', 'leads')
            ->where('code', 'user_id')
            ->where('lookup_type', 'asesores')
            ->update(['lookup_type' => 'users']);
    }
};
