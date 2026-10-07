<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El estado del recordatorio de recompra de cada cliente.
 *
 * Una fila por persona, no por pedido: lo que se recuerda es "a este cliente hay que
 * preguntarle si quiere repetir", y eso vive mientras viva el cliente. Atarlo al
 * pedido obligaría a decidir cuál de sus pedidos "manda" cuando tiene varios.
 *
 * Tabla aparte y no columnas en `persons` para no tocar el esquema del CRM por una
 * necesidad de este módulo — mismo criterio que `distribuidor_entregas`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_recordatorios', function (Blueprint $table) {
            $table->increments('id');

            // Único: el estado del recordatorio es uno solo por cliente.
            $table->integer('person_id')->unsigned()->unique();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');

            /*
             * El interruptor por cliente. Distinto de `baja_at`, y la diferencia
             * importa: `activo = false` lo apaga alguien del equipo desde el CRM;
             * `baja_at` lo pidió el CLIENTE. Un operador no debería poder "reactivar"
             * sin querer a alguien que pidió no recibir más mensajes, así que son dos
             * campos y no un estado con tres valores.
             */
            $table->boolean('activo')->default(true);

            // Cuándo el cliente pidió no recibir más. Nullable: lo normal es no haberlo
            // pedido. Se guarda la FECHA y no un booleano porque es el respaldo de que
            // se respetó la baja, y "cuándo" es la mitad de esa prueba.
            $table->timestamp('baja_at')->nullable();

            /*
             * Cuándo toca el próximo recordatorio. NULL significa "no hay nada
             * programado": el cliente dijo que sí y está pidiendo, o se agotaron los
             * intentos, o se dio de baja.
             *
             * Es la columna por la que barre el comando, así que va indexada.
             */
            $table->timestamp('proximo_at')->nullable();

            // Cuántos recordatorios se mandaron en ESTE ciclo. Se reinicia cuando el
            // cliente vuelve a comprar: la insistencia se mide por ciclo, no por vida.
            $table->unsignedTinyInteger('intentos')->default(0);

            $table->timestamp('ultimo_envio_at')->nullable();

            // Qué contestó la última vez: si | no | baja. String y no enum para no
            // necesitar una migración cada vez que aparezca una respuesta nueva.
            $table->string('ultima_respuesta', 20)->nullable();
            $table->timestamp('ultima_respuesta_at')->nullable();

            /*
             * Cada cuántos días compra este cliente, calculado de su historial. Se
             * GUARDA el valor usado al programar y no se recalcula al leer: así la
             * fecha que se ve en pantalla es la misma que se decidió, y queda el
             * rastro de con qué número se programó.
             */
            $table->unsignedSmallInteger('cadencia_dias')->nullable();

            $table->timestamps();

            // El comando pregunta "quiénes vencieron", que es exactamente esto.
            $table->index(['proximo_at', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_recordatorios');
    }
};
