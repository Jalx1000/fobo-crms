<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inmueble_lead', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('inmueble_id');
            $table->foreign('inmueble_id')->references('id')->on('inmuebles')->onDelete('cascade');

            $table->unsignedInteger('lead_id');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');

            /*
            | De dónde salió el interés. Un inmueble que el cliente pidió por su
            | código no vale lo mismo que uno que el agente le ofreció: el
            | primero es demanda, el segundo es sugerencia, y el asesor necesita
            | distinguirlos al preparar la visita.
            */
            $table->enum('origen', ['consultado', 'ofrecido'])->default('consultado');

            $table->timestamps();

            // Un inmueble aparece una sola vez por lead, aunque el cliente
            // pregunte por él tres veces en la misma conversación.
            $table->unique(['lead_id', 'inmueble_id']);
            $table->index('inmueble_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inmueble_lead');
    }
};
