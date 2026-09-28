<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ficha técnica de cada variante.
 *
 * `products.caracteristicas` existe desde antes, pero es del PRODUCTO, y hay
 * fichas que cambian con la variante: el stretch film manual y el automático
 * comparten ancho y espesor, y difieren en peso, estiramiento, tipo de cono y
 * tipo de aplicación. Con un solo juego por producto había que elegir cuál de
 * las dos fichas era «la» ficha.
 *
 * Se guarda como lista ordenada —no como mapa— porque la ficha lleva
 * encabezados («Dimensiones del rollo», «Dimensiones del cono») y el orden es
 * parte de cómo se lee. Cada entrada es `{"clave": …, "valor": …}` y una
 * entrada sin `valor` es un encabezado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_variantes', 'caracteristicas')) {
            return;
        }

        Schema::table('product_variantes', function (Blueprint $table) {
            $table->json('caracteristicas')->nullable()->after('nombre_erp');
        });
    }

    public function down(): void
    {
        Schema::table('product_variantes', function (Blueprint $table) {
            $table->dropColumn('caracteristicas');
        });
    }
};
