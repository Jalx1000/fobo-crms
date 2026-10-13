<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La dirección escrita y la referencia del domicilio del cliente.
 *
 * Van junto a las coordenadas y no en el atributo `ubicacion_lead`, que es por PEDIDO:
 * el domicilio es del cliente y no cambia pedido a pedido, y tenerlo por pedido obliga a
 * reescribirlo cada vez —o a no tenerlo cuando el pedido nuevo no lo trae.
 *
 * Las dos nullable: hay domicilios marcados con el pin y sin texto, y clientes con la
 * dirección escrita antes de que alguien pase a marcar el pin. Exigir las dos convertiría
 * cada caso parcial en un error.
 *
 * `direccion` la precarga el geocodificador a partir del pin y queda editable;
 * `referencia` la escribe el distribuidor y nunca se deduce: "portón verde, hay un árbol
 * afuera" no sale de ninguna coordenada, y es lo que de verdad usa quien toca el timbre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidor_ubicaciones', function (Blueprint $table) {
            // 255 alcanza para "Avenida San Martín 1234, Barrio Alto Comedero".
            $table->string('direccion')->nullable()->after('lng');

            /*
             * text y no string: la referencia es prosa de quien conoce la casa y se
             * escribe sin límite mental. Un varchar(255) corta la frase justo donde
             * estaba el dato que importaba.
             */
            $table->text('referencia')->nullable()->after('direccion');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidor_ubicaciones', function (Blueprint $table) {
            $table->dropColumn(['direccion', 'referencia']);
        });
    }
};
