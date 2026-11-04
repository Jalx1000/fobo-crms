<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un adjunto puede ser de UNA variante.
 *
 * Magia Verde tiene una ficha técnica en PDF por tamaño (35, 50, 75, 140 y
 * 200 L), pero los adjuntos colgaban del producto: cuando el cliente pedía la
 * ficha de 50 L, el agente solo podía mandar «las fichas de Magia Verde» -las
 * cinco, o las primeras N-, nunca la que correspondía.
 *
 * `variante_id` nulo = adjunto general del producto (una foto del producto, el
 * catálogo entero). Con variante = solo se manda cuando se pide esa variante.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_medias', 'variante_id')) {
            return;
        }

        Schema::table('product_medias', function (Blueprint $table) {
            $table->unsignedInteger('variante_id')->nullable()->after('product_id');

            // Si se borra la variante, el adjunto vuelve a ser general en vez
            // de desaparecer: el archivo sigue siendo del producto.
            $table->foreign('variante_id')->references('id')->on('product_variantes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_medias', function (Blueprint $table) {
            $table->dropForeign(['variante_id']);
            $table->dropColumn('variante_id');
        });
    }
};
