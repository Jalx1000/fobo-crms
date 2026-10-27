<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categoría del servicio = Especialidad (línea de tratamiento de Tessa).
 * Un producto/servicio pertenece a una especialidad (Depilación láser,
 * Faciales de rejuvenecimiento, Reducción de grasa localizada).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('specialties')) {
            return;
        }

        if (! Schema::hasColumn('products', 'specialty_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('specialty_id')->nullable()->after('sku');
                $table->foreign('specialty_id')->references('id')->on('specialties')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'specialty_id')) {
            Schema::table('products', function (Blueprint $table) {
                try {
                    $table->dropForeign(['specialty_id']);
                } catch (\Throwable $e) {
                    // ignore
                }
                $table->dropColumn('specialty_id');
            });
        }
    }
};
