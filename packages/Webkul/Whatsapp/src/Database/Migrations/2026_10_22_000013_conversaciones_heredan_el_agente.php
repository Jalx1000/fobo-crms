<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las conversaciones nacían con el agente prendido (`ai_enabled = true`), así que
 * WHATSAPP_AI_ENABLED=false no apagaba nada: el agente le contestaba a todos.
 *
 * Desde ahora una conversación sin decisión propia hereda lo general: con el agente
 * apagado, solo contesta a los números de WHATSAPP_AI_NUMEROS (o los de
 * Configuración → WhatsApp → Agente de IA). Las que nacieron en true pasan a heredar.
 *
 * Las que un asesor apagó (false) quedan apagadas. Las que tienen una derivación
 * abierta ya están en false.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('whatsapp_conversations')->where('ai_enabled', true)->update(['ai_enabled' => null]);
    }

    public function down(): void
    {
        DB::table('whatsapp_conversations')->whereNull('ai_enabled')->update(['ai_enabled' => true]);
    }
};
