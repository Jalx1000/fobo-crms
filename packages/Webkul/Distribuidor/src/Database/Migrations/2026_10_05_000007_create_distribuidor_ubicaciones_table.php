<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ubicación del CLIENTE, no la del pedido.
 *
 * Hasta ahora la ubicación se resolvía pedido por pedido: el pin que el cliente
 * mandó por WhatsApp en esa conversación, o la dirección escrita en el lead. Eso
 * obliga a redescubrir el domicilio en cada pedido, y si el cliente no mandó pin
 * esta vez, no hay ubicación — aunque la haya mandado el mes pasado.
 *
 * Una fila por cliente, con la fuente de dónde salió. La fuente no es decorativa:
 * el GPS tomado en la puerta le gana al pin de WhatsApp, porque el pin lo manda el
 * cliente desde donde está en ese momento —que puede ser el trabajo— mientras el
 * GPS del distribuidor se toma en el domicilio al entregar. Sin la columna no hay
 * forma de impedir que un pin posterior pise una coordenada mejor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_ubicaciones', function (Blueprint $table) {
            $table->increments('id');

            // Una sola ubicación por cliente: esto es el domicilio, no un historial.
            $table->integer('person_id')->unsigned()->unique();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');

            /*
             * decimal y no float: en float, comparar o deduplicar coordenadas falla
             * por el redondeo binario. 7 decimales son ~1 cm, de sobra para una
             * puerta.
             */
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            // 'gps' (tomada en la puerta) o 'whatsapp' (pin que mandó el cliente).
            $table->string('fuente', 20);

            // Quién la dejó, para poder preguntarle si quedó mal.
            $table->integer('user_id')->unsigned()->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_ubicaciones');
    }
};
