<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La planta (sucursal) que fabrica y despacha cada producto.
 *
 * Las sucursales de Imprimir son los ALMACENES del módulo Warehouse, que ya
 * tiene su pantalla en Configuración y su sistema de atributos. No se crea una
 * tabla aparte: serían dos listas de lo mismo, y la segunda quedaría
 * desactualizada apenas alguien cargue una sucursal desde el CRM.
 *
 * Es una relación DIRECTA y no la de `product_inventories`, que ya existe entre
 * las dos tablas: esa dice cuánto stock hay en cada almacén (varios por
 * producto), y esta dice de cuál sale. Un producto puede tener stock en tres
 * lados y despacharse siempre desde uno.
 *
 * Nullable y SET NULL: un producto sin planta se cotiza igual —solo que el
 * agente no puede decir dónde retirarlo— y borrar una planta no puede borrar
 * los productos que fabricaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('warehouse_id')->nullable()->after('lugar_entrega');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
