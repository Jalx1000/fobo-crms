<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué archivos de Dropbox ya se bajaron, sin importar en qué terminaron.
 *
 * `smd_synced_events` guarda un `source_file` por CITA — el último que la
 * actualizó —, así que los archivos obsoletos o los que no cambiaron nada no
 * quedan anotados en ningún lado y la sincronización vuelve a bajarlos en cada
 * corrida. Con cientos de archivos por carpeta eso son minutos de descargas
 * inútiles cada cinco minutos, y una cita nueva esperando detrás de la cola.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smd_processed_files')) {
            Schema::create('smd_processed_files', function (Blueprint $table) {
                // La ruta de Dropbox es la identidad: SMD nombra los archivos
                // `event-YYYY-MM-DD-HHMM-<id>.json`, así que una modificación
                // llega como archivo nuevo y misma ruta significa mismo contenido.
                $table->string('path', 500)->primary();
                $table->string('outcome', 30)->nullable();
                $table->timestamp('processed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smd_processed_files');
    }
};
