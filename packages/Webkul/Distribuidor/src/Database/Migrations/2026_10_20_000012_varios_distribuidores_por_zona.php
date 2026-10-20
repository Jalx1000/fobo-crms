<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una ciudad pasa a tener VARIOS distribuidores, de empresas distintas.
 *
 * La tabla nació con `lead_pipeline_id` único ("una ciudad tiene UN distribuidor")
 * y el comentario de esa migración ya avisaba que esto se convertía en muchos a
 * muchos el día en que hubiera más de uno. Ese día llegó: cada distribuidor es una
 * empresa, y en una misma ciudad puede haber varias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            // Primero el índice nuevo: la foreign key de lead_pipeline_id necesita un
            // índice que empiece por esa columna, y si se borra el único antes MySQL
            // lo rechaza ("needed in a foreign key constraint").
            $table->unique(['lead_pipeline_id', 'user_id']);
        });

        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            // Con columnas y no con el nombre: así Laravel arma el nombre real, que
            // lleva el prefijo de tabla (kl_), igual que cuando lo creó.
            $table->dropUnique(['lead_pipeline_id']);
        });
    }

    public function down(): void
    {
        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            // Volver a "una ciudad, un distribuidor" falla si hoy hay dos en la misma
            // ciudad: es a propósito, porque elegir cuál borrar no es decisión de una
            // migración.
            $table->unique(['lead_pipeline_id']);
        });

        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            $table->dropUnique(['lead_pipeline_id', 'user_id']);
        });
    }
};
