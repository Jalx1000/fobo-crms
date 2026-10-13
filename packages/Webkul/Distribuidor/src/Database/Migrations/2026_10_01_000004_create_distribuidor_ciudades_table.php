<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué ciudades ve cada distribuidor.
 *
 * Sin filas para un usuario = ve TODAS. Es el default a propósito: un
 * distribuidor recién creado tiene que poder trabajar antes de que alguien se
 * acuerde de asignarle ciudades, y el caso de una sola ciudad —el más común— no
 * necesita configuración.
 *
 * Con filas, ve sólo esas: tanto los pedidos pendientes como las sucursales
 * desde donde puede vender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_ciudades', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // La ciudad ES el pipeline en este CRM.
            $table->integer('lead_pipeline_id')->unsigned();
            $table->foreign('lead_pipeline_id')->references('id')->on('lead_pipelines')->onDelete('cascade');

            $table->timestamps();

            // Una ciudad no se asigna dos veces al mismo usuario.
            $table->unique(['user_id', 'lead_pipeline_id'], 'distribuidor_ciudades_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_ciudades');
    }
};
