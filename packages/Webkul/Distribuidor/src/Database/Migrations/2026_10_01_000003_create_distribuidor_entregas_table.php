<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se entregó un pedido, y quién lo entregó.
 *
 * La etapa `pedido-entregado` dice QUE se entregó, pero no cuándo ni por quién:
 * `updated_at` del lead se mueve con cualquier edición posterior, así que no
 * sirve como fecha de entrega. Y es justamente el dato que se pregunta cuando un
 * cliente dice que no le llegó.
 *
 * Tabla aparte y no columnas en `leads` para no tocar el esquema del CRM por una
 * necesidad de este módulo. Una fila por entrega: si un pedido se reabre y se
 * vuelve a entregar, quedan las dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_entregas', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('lead_id')->unsigned();
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');

            // Nullable: un usuario borrado no puede borrar el registro de una
            // entrega que ocurrió.
            $table->integer('user_id')->unsigned()->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            // Explícito, no derivado de timestamps: `updated_at` se mueve con
            // cualquier edición y dejaría de ser la fecha de entrega.
            $table->timestamp('entregado_at');

            $table->string('note')->nullable();

            $table->timestamps();

            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_entregas');
    }
};
