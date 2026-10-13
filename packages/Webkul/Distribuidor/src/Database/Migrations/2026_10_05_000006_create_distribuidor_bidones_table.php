<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El movimiento de bidones: cuántos se dejan y cuántos se retiran.
 *
 * Es un libro de movimientos y NO un contador en la ficha del cliente, por la
 * misma razón por la que la entrega se registra en su tabla: la pregunta que se
 * hace de verdad no es "cuántos tiene" sino "cuántos tiene, desde cuándo y quién
 * los dejó". Un número que se sobrescribe no contesta ninguna de las dos últimas,
 * y si dos distribuidores lo tocan el mismo día el último gana sin dejar rastro.
 *
 * El saldo del cliente se calcula: suma de entregados menos suma de retirados.
 * Sale de una sola consulta agregada, así que no hace falta cachearlo.
 *
 * lead_id es nullable a propósito: retirar vacíos sin dejar nada —pasar a juntar—
 * es una visita real que no genera pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_bidones', function (Blueprint $table) {
            $table->increments('id');

            // El cliente es el sujeto del saldo: sin él no hay a quién atribuirlo.
            $table->integer('person_id')->unsigned();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');

            $table->integer('lead_id')->unsigned()->nullable();
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');

            // Igual que en entregas: si se borra el usuario, el movimiento queda.
            $table->integer('user_id')->unsigned()->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            $table->unsignedInteger('entregados')->default(0);
            $table->unsignedInteger('retirados')->default(0);

            $table->timestamps();

            // El saldo del cliente se pide en cada búsqueda de contacto.
            $table->index('person_id', 'distribuidor_bidones_cliente');

            // El resumen de Caja filtra por distribuidor y por día.
            $table->index(['user_id', 'created_at'], 'distribuidor_bidones_del_dia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_bidones');
    }
};
