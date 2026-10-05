<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué ciudades (pipelines) ve cada usuario.
 *
 * Reemplaza a `distribuidor_ciudades`, que respondía la misma pregunta pero sólo
 * para el Punto. La bandeja de WhatsApp necesita la misma respuesta, y dos tablas
 * para un mismo concepto se desincronizan: alguien asigna Jujuy al distribuidor y
 * el asesor sigue viendo todas las ciudades.
 *
 * Sin filas para un usuario = ve TODAS. Con filas, ve sólo esas. El detalle de
 * por qué ese default está en Webkul\Core\Services\AlcanceCiudades.
 *
 * Las filas de la tabla vieja se copian antes de borrarla, así que la migración
 * es segura en un entorno que ya tenga asignaciones cargadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ciudades', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // La ciudad ES el pipeline en este CRM.
            $table->integer('lead_pipeline_id')->unsigned();
            $table->foreign('lead_pipeline_id')->references('id')->on('lead_pipelines')->onDelete('cascade');

            $table->timestamps();

            // Una ciudad no se asigna dos veces al mismo usuario.
            $table->unique(['user_id', 'lead_pipeline_id'], 'user_ciudades_unica');
        });

        if (! Schema::hasTable('distribuidor_ciudades')) {
            return;
        }

        foreach (DB::table('distribuidor_ciudades')->get() as $fila) {
            DB::table('user_ciudades')->insertOrIgnore([
                'user_id'          => $fila->user_id,
                'lead_pipeline_id' => $fila->lead_pipeline_id,
                'created_at'       => $fila->created_at,
                'updated_at'       => $fila->updated_at,
            ]);
        }

        Schema::drop('distribuidor_ciudades');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ciudades');
    }
};
