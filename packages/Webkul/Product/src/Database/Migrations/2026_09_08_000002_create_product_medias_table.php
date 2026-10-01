<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos de un producto: imágenes, documentos y enlaces.
 *
 * Portado de `inmueble_medias` (proyecto c21). Se colapsan los cuatro tipos de
 * allá (foto/video/plano/tour) a tres: acá no hay videos de propiedades, y un
 * plano es un documento más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_medias', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->enum('tipo', ['imagen', 'documento', 'enlace']);

            /*
            | path: archivo en el disco privado del CRM (imagen o documento).
            | url:  destino externo (enlace). Uno u otro, nunca ambos.
            */
            $table->string('path')->nullable();
            $table->string('url', 500)->nullable();

            $table->string('caption', 200)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('es_portada')->default(false);

            $table->timestamps();

            $table->index(['product_id', 'tipo', 'orden'], 'product_medias_galeria_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_medias');
    }
};
