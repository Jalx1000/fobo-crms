<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized timestamp of the last inbound message, so the 24h WhatsApp
 * messaging window can be computed without a subquery on every poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_conversations', 'last_inbound_at')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->timestamp('last_inbound_at')->nullable()->after('last_message_at');
            });
        }

        // Backfill from existing inbound messages.
        $prefix = DB::getTablePrefix();

        DB::statement("
            UPDATE {$prefix}whatsapp_conversations c
            SET last_inbound_at = (
                SELECT MAX(m.created_at)
                FROM {$prefix}whatsapp_messages m
                WHERE m.conversation_id = c.id AND m.direction = 'inbound'
            )
        ");
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('last_inbound_at');
        });
    }
};
