<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variantes de un producto: los códigos que dependen de ALGUNOS de sus ejes.
 *
 * En bolsas el código TR4 cambia con el tamaño pero no con el canal ni la
 * zona. No va en el producto (hay cinco códigos) ni en la fila de precio (se
 * repetiría diez veces por tamaño): va acá, indexado solo por los ejes que lo
 * identifican, que el producto declara en `ejes_identidad`.
 *
 * Un producto, una lista de precios, una lista de códigos. El mismo modelo
 * sirve para hules (por color), stretch film (por presentación) y tapas (por
 * impresión): el negocio confirmó que todos tienen TR4 por variante.
 *
 * `codigo_tr4` nullable a propósito: hoy solo están los de Magia Verde. Una
 * variante sin código se cotiza igual, con el campo vacío para completar a
 * mano — bloquearla dejaría tres productos sin poder cotizarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('ejes_identidad')->nullable()->after('ejes_precio');
        });

        Schema::create('product_variantes', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->json('valores');                              // {"Tamaño": "50 L"}

            $table->string('codigo_tr4', 30)->nullable();
            $table->string('codigo_barra', 20)->nullable();
            $table->string('medida', 60)->nullable();             // "(65×80) cm"
            $table->string('unidad', 10)->default('PZA');
            $table->string('nombre_erp', 120)->nullable();        // "BSA MULTIUSO MAGIA VERDE 50L 10UN"

            $table->unsignedSmallInteger('orden')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'orden'], 'product_variantes_lista_index');
            $table->index('codigo_tr4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variantes');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ejes_identidad');
        });
    }
};
