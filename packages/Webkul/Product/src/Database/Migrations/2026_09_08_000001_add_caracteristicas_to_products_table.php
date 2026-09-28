<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Características propias de cada categoría de producto.
 *
 * Van en JSON y no en columnas porque lo que se guarda cambia según qué se
 * vende: el gramaje importa en un embalaje y no en un banner. Una columna por
 * dato daría una tabla con decenas de campos nulos y una migración por cada
 * categoría nueva.
 *
 * Qué campos ofrece cada categoría se declara en config/caracteristicas.php,
 * no acá: agregar uno es una línea, sin tocar la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('caracteristicas')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('caracteristicas');
        });
    }
};
