<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo el PACIENTE pidió hablar con una persona.
 *
 * Se distingue del apagado normal del agente (`ai_disabled_at`, que es un asesor
 * tomando la conversación) porque la reactivación automática no debe aplicar: si
 * el paciente pidió explícitamente una recepcionista, que el bot vuelva solo al
 * rato contradice ese pedido. Recepción lo reactiva a mano cuando corresponde.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_conversations', 'ai_handoff_at')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->timestamp('ai_handoff_at')->nullable()->after('ai_disabled_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('ai_handoff_at');
        });
    }
};
