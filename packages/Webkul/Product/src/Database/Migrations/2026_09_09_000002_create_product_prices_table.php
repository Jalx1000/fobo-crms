<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de precios de un producto sin precio fijo.
 *
 * Cada fila es una combinación de valores de los ejes declarados en
 * `products.ejes_precio` más lo que se le cobra a esa combinación:
 *
 *   {"Tamaño": "50 L", "Canal": "HORECA"}  →  180 Bs  ·  por bulto  ·  mín. 5
 *
 * `precio` es NULLABLE a propósito. El flujo de Imprimir tiene combinaciones que
 * existen pero no se cotizan por chat: "Para mi casa" se deriva al supermercado
 * y "Quiero revender" al equipo comercial. Esas filas se cargan con precio null
 * y una `nota`, para que el agente sepa que el caso está previsto y responda lo
 * que corresponde en vez de improvisar un precio o decir que no existe.
 *
 * `valores` es JSON y no columnas fijas porque la cantidad de ejes varía por
 * producto: Hules tiene uno (Color), Bolsas dos (Tamaño × Canal). Columnas
 * fijas obligarían a una migración cada vez que un producto se cotiza distinto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->json('valores');

            $table->decimal('precio', 12, 4)->nullable();

            // "por bulto", "por rollo", "por caja de 5.300 u." — sin esto un
            // número suelto no le dice nada al cliente ni al agente.
            $table->string('unidad', 60)->nullable();

            // Texto libre y no un entero: el mínimo se expresa en la unidad de
            // venta ("30 cajas", "50 unidades") y esa unidad cambia por producto.
            $table->string('minimo', 60)->nullable();

            // Qué hacer cuando no hay precio, o cualquier aclaración de la fila.
            $table->string('nota', 200)->nullable();

            $table->unsignedSmallInteger('orden')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'orden'], 'product_prices_lista_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
