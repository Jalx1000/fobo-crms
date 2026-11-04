<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de la variante en el ítem de cotización.
 *
 * `quote_items` ya copiaba `sku` y `name` del producto: una cotización es un
 * documento emitido, no una vista sobre el catálogo. Estos cuatro campos siguen
 * el mismo criterio - si mañana alguien corrige un TR4 o una medida, la
 * cotización que el cliente ya tiene en la mano no puede cambiar.
 *
 * Es también lo que hace que el PDF sobreviva a que se borre un producto, que
 * es exactamente el bug que arreglamos en `QuoteItemRepository::update()`.
 *
 * `variante` guarda qué combinación era (`{"Tamaño":"50 L"}`) para poder
 * reconstruir la línea sin depender del producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->string('codigo_tr4', 30)->nullable()->after('sku');
            $table->string('codigo_barra', 20)->nullable()->after('codigo_tr4');
            $table->string('medida', 60)->nullable()->after('name');
            $table->string('unidad', 10)->nullable()->after('medida');
            $table->json('variante')->nullable()->after('unidad');
        });
    }

    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn(['codigo_tr4', 'codigo_barra', 'medida', 'unidad', 'variante']);
        });
    }
};
