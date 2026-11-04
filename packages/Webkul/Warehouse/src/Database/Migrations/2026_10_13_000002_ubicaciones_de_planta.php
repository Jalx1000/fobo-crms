<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un solo almacén (Santa Cruz) con una ubicación por planta.
 *
 * Antes cada planta era un almacén. Ahora el almacén es la ciudad y la planta
 * es una ubicación suya, con su enlace de Google Maps: lo que el agente manda
 * cuando el cliente pregunta dónde queda. El producto apunta a la ubicación de
 * la planta que lo fabrica.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouse_locations', 'maps_url')) {
            Schema::table('warehouse_locations', function (Blueprint $table) {
                $table->string('maps_url')->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('products', 'warehouse_location_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('warehouse_location_id')->nullable()->after('warehouse_id');
                $table->foreign('warehouse_location_id')->references('id')->on('warehouse_locations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'warehouse_location_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['warehouse_location_id']);
                $table->dropColumn('warehouse_location_id');
            });
        }

        if (Schema::hasColumn('warehouse_locations', 'maps_url')) {
            Schema::table('warehouse_locations', function (Blueprint $table) {
                $table->dropColumn('maps_url');
            });
        }
    }
};
