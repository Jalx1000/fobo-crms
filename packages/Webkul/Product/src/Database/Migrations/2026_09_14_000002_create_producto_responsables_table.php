<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién atiende cada producto en cada ciudad.
 *
 * No alcanza con un responsable por producto ni con uno por ciudad: el que
 * vende tapas en Santa Cruz no es el que vende tapas en La Paz, y en la misma
 * ciudad las bolsas y el stretch film los llevan personas distintas. Es una
 * matriz, y por eso es una tabla y no una columna.
 *
 * Sirve para dos cosas: asignar el asesor de una cotización sin que nadie lo
 * elija a mano, y que el agente sepa a quién derivar cuando el cliente pide
 * hablar con alguien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_responsables', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            // Texto y no FK: las ciudades viven como opciones de atributo, y
            // atarlas por id haría que renombrar una opción rompa la matriz.
            $table->string('ciudad', 60);

            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();

            // Un solo responsable por producto y ciudad: dos serían una
            // asignación ambigua y el CRM tendría que elegir por su cuenta.
            $table->unique(['product_id', 'ciudad'], 'producto_responsable_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_responsables');
    }
};
