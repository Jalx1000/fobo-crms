<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_handoffs', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->onDelete('cascade');

            // El asesor titular del inmueble. Nunca se reasigna a otro: el
            // agente ya le prometió al cliente que le escribe esta persona.
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');

            /*
            | Sobre qué se derivó. Deliberadamente SIN foreign key a `inmuebles`:
            | este módulo es genérico y lo comparten otros tenants que no tienen
            | catálogo inmobiliario. Una FK acá haría que WhatsApp no se pueda
            | instalar sin Inmobiliaria.
            |
            | Se guarda además el código público porque es lo que el cliente vio
            | y lo que el agente citó: si el inmueble se borra, el handoff sigue
            | siendo legible para el asesor que lo atiende.
            */
            $table->unsignedInteger('referencia_id')->nullable();
            $table->string('referencia_codigo', 32)->nullable();

            $table->string('reason', 500)->nullable();

            $table->enum('state', ['abierto', 'tomado', 'cerrado'])->default('abierto');

            $table->timestamp('opened_at');
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Se marca cuando ya se avisó al supervisor, para no notificar dos
            // veces por la misma conversación.
            $table->timestamp('escalated_at')->nullable();

            $table->timestamps();

            /*
            | La idempotencia del handoff (§8 del handoff del agente) se apoya en
            | el esquema, no solo en el código: una conversación no puede tener
            | dos handoffs abiertos ni aunque dos requests lleguen a la vez.
            | MySQL trata los NULL como distintos en un índice único, así que
            | `abierto_uid` vale 1 mientras está abierto y NULL cuando se cierra.
            */
            $table->unsignedTinyInteger('abierto_uid')->nullable();
            $table->unique(['conversation_id', 'abierto_uid'], 'whatsapp_handoffs_abierto_unique');

            $table->index(['state', 'escalated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_handoffs');
    }
};
