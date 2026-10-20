<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una ciudad pasa a tener VARIOS distribuidores, de empresas distintas.
 *
 * La tabla nació con `lead_pipeline_id` único ("una ciudad tiene UN distribuidor")
 * y el comentario de esa migración ya avisaba que esto se convertía en muchos a
 * muchos el día en que hubiera más de uno. Ese día llegó: cada distribuidor es una
 * empresa, y los clientes nuevos se reparten entre ellos por turno (round robin).
 *
 * `ultima_asignacion_at` es lo que hace equitativo el turno: le toca al que hace
 * más tiempo que no recibe un cliente. Va por fila (ciudad + distribuidor) y no
 * por usuario porque el turno es de cada ciudad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            // Primero el índice nuevo: la foreign key de lead_pipeline_id necesita un
            // índice que empiece por esa columna, y si se borra el único antes MySQL
            // lo rechaza ("needed in a foreign key constraint").
            $table->unique(['lead_pipeline_id', 'user_id']);
        });

        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            // Con columnas y no con el nombre: así Laravel arma el nombre real, que
            // lleva el prefijo de tabla (kl_), igual que cuando lo creó.
            $table->dropUnique(['lead_pipeline_id']);

            /*
             * Con microsegundos. Con segundos, dos clientes asignados en el mismo segundo
             * dejaban a los distribuidores empatados, desempataba el id y le tocaba DOS
             * veces seguidas al mismo: justo lo contrario de equitativo, y en el momento
             * en que más importa (una ráfaga de pedidos).
             */
            $table->timestamp('ultima_asignacion_at', 6)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            $table->dropColumn('ultima_asignacion_at');

            // Volver a "una ciudad, un distribuidor" falla si hoy hay dos en la misma
            // ciudad: es a propósito, porque elegir cuál borrar no es decisión de una
            // migración.
            $table->unique(['lead_pipeline_id']);
        });

        Schema::table('distribuidor_zonas', function (Blueprint $table) {
            $table->dropUnique(['lead_pipeline_id', 'user_id']);
        });
    }
};
