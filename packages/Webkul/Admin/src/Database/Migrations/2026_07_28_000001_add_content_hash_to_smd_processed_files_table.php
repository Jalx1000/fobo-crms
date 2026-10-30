<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ruta de Dropbox NO alcanza como identidad del archivo.
 *
 * El nombre que usa SMD -`event-YYYY-MM-DD-HHMM-<id>.json`- tiene resolución de
 * minutos, así que una cita creada y editada dentro del mismo minuto se escribe
 * DOS VECES en la misma ruta. El webhook nos hace bajar la primera versión y
 * anotamos la ruta como procesada; la segunda escritura llega segundos después
 * y esa ruta ya no se vuelve a mirar nunca. La edición se pierde en silencio,
 * antes incluso de llegar al descarte por `updated_at` de isStale().
 *
 * Guardar el `content_hash` que Dropbox ya devuelve en el listado permite
 * distinguir "misma ruta, mismo contenido" (saltar) de "misma ruta, contenido
 * nuevo" (rebajar). Las filas viejas quedan con hash null: se las trata como
 * desconocidas y se rebajan una vez, lo que además recupera las ediciones que
 * ya se habían perdido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('smd_processed_files', 'content_hash')) {
            Schema::table('smd_processed_files', function (Blueprint $table) {
                $table->string('content_hash', 64)->nullable()->after('path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('smd_processed_files', 'content_hash')) {
            Schema::table('smd_processed_files', function (Blueprint $table) {
                $table->dropColumn('content_hash');
            });
        }
    }
};
