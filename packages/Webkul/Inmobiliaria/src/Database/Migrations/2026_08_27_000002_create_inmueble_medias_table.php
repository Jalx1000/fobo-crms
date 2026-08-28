<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inmueble_medias', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('inmueble_id');
            $table->foreign('inmueble_id')->references('id')->on('inmuebles')->onDelete('cascade');

            $table->enum('tipo', ['foto', 'video', 'plano', 'tour']);

            // path: archivo en el disco del CRM (foto/video/plano).
            // url:  destino externo (tour virtual). Uno u otro, no ambos.
            $table->string('path')->nullable();
            $table->string('url', 500)->nullable();

            $table->string('caption', 200)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('es_portada')->default(false);

            /*
            |------------------------------------------------------------------
            | Caché del media_id de la Cloud API (D10)
            |------------------------------------------------------------------
            | La foto se sube a Meta cuando el asesor la carga, no cuando el
            | agente la envía: así Meta no tiene que descargar nada de este
            | servidor en medio de una conversación. Meta retiene el id ~30 días;
            | al vencer se vuelve a subir y el envío cae a URL firmada mientras
            | tanto.
            */
            $table->string('provider_media_id', 120)->nullable();
            $table->timestamp('provider_expira_at')->nullable();

            $table->timestamps();

            $table->index(['inmueble_id', 'tipo', 'orden'], 'inmueble_medias_galeria_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inmueble_medias');
    }
};
