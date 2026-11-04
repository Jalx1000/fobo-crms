<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los ejes por los que varía el precio de un producto.
 *
 * Bolsas Magia Verde no tiene un precio: tiene quince. El precio sale de cruzar
 * el tamaño (35L…200L) con el canal (TRADICIONAL / HORECA / EMPRESARIAL). Hules
 * varía solo por color y Tapas solo por si lleva impresión.
 *
 * Los ejes viven acá y no en `config/caracteristicas.php` porque no son una
 * propiedad de la categoría sino de cada producto: dos productos de embalaje
 * pueden cotizarse por ejes distintos, y el negocio los cambia sin deploy.
 *
 * Guarda una lista de nombres - ["Tamaño", "Canal"] - que son las columnas de la
 * tabla de precios. Vacío o null = el producto usa el `price` fijo de siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('ejes_precio')->nullable()->after('caracteristicas');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ejes_precio');
        });
    }
};
