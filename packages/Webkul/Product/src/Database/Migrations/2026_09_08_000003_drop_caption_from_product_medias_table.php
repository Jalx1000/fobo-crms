<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuera la descripción por archivo.
 *
 * Se portó de `inmueble_medias` (c21), donde una foto de propiedad gana con un
 * pie ("Frente", "Living"). Acá no: nadie va a escribir una descripción por cada
 * imagen de producto, y un campo que siempre queda vacío es ruido en el
 * formulario y una columna muerta en la tabla.
 *
 * Sin datos que perder: la tabla se creó hoy y nunca se cargó un caption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_medias', function (Blueprint $table) {
            $table->dropColumn('caption');
        });
    }

    public function down(): void
    {
        Schema::table('product_medias', function (Blueprint $table) {
            $table->string('caption', 200)->nullable()->after('url');
        });
    }
};
