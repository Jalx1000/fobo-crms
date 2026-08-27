<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // Contadores del pedido de teléfono (§7 del handoff del agente): los
            // lleva el CRM, no el agente, para que sobrevivan a un reinicio del
            // servicio del agente y no se pueda pedir el número 20 veces.
            $table->unsignedTinyInteger('phone_prompt_attempts')->default(0)->after('wa_name');
            $table->boolean('phone_rechazado')->default(false)->after('phone_prompt_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn(['phone_prompt_attempts', 'phone_rechazado']);
        });
    }
};
