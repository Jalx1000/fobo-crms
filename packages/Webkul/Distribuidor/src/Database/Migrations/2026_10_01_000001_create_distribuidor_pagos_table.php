<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los cobros de un pedido.
 *
 * El CRM no modelaba pagos: un pedido tenía `lead_value` y nada que dijera
 * cuánto de eso entró. Para un distribuidor que cobra en mano —y a veces por
 * partes— ese dato ES el trabajo.
 *
 * Varias filas por pedido a propósito: un pago parcial es lo normal (se entrega
 * el botellón, se cobra la mitad, el resto queda para la próxima vuelta). El
 * saldo se calcula, no se guarda, así que no puede quedar desincronizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_pagos', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('lead_id')->unsigned();
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');

            // Quién cobró. Nullable porque un usuario borrado no puede borrar el
            // registro de una plata que entró.
            $table->integer('user_id')->unsigned()->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            // Misma precisión que lead_products.amount, para que sumar cobros y
            // comparar contra el total del pedido no arrastre error de redondeo.
            $table->decimal('amount', 12, 4);

            // Cerrado por config (efectivo | transferencia | qr): un campo libre
            // vuelve imposible cuadrar la caja.
            $table->string('method', 32);

            $table->string('note')->nullable();

            $table->timestamps();

            // La consulta del Punto es siempre "los cobros de este pedido".
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_pagos');
    }
};
