<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se apagó el agente en esta conversación.
 *
 * Hacía falta una marca propia: `handoff_requested_at` sólo cubre las
 * derivaciones y `updated_at` no sirve porque cambia con cada mensaje que
 * entra. Sin esto no hay forma de saber hace cuánto está callado el bot, y al
 * medirlo había 100 conversaciones apagadas —98 sin ninguna derivación— que
 * nadie volvió a prender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'ai_disabled_at')) {
                $table->timestamp('ai_disabled_at')->nullable()->after('ai_enabled');
                $table->index('ai_disabled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_conversations', 'ai_disabled_at')) {
                $table->dropIndex(['ai_disabled_at']);
                $table->dropColumn('ai_disabled_at');
            }
        });
    }
};
