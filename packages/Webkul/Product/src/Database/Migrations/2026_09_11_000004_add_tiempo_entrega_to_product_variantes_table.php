<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiempo de entrega por variante, como override del producto.
 *
 * El spec lo puso en el producto, y para bolsas, hules y stretch film alcanza.
 * Tapas lo rompe: sin impresión es inmediata y con impresión son 20 días. Es
 * el mismo producto y la misma planta; lo que cambia es la variante.
 *
 * Null = usa el del producto. Solo se carga donde difiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variantes', function (Blueprint $table) {
            $table->string('tiempo_entrega', 60)->nullable()->after('nombre_erp');
        });
    }

    public function down(): void
    {
        Schema::table('product_variantes', function (Blueprint $table) {
            $table->dropColumn('tiempo_entrega');
        });
    }
};
