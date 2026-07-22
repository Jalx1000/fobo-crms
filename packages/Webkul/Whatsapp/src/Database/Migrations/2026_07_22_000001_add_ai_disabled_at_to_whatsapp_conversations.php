<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the agent was handed over to a human.
 *
 * `ai_enabled` alone says the agent is off but not since when, so nothing can
 * decide it has been off long enough to come back. This stamps the moment of
 * the handoff for whatsapp:reactivate-agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_conversations', 'ai_disabled_at')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->timestamp('ai_disabled_at')->nullable()->after('ai_enabled');
            });
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('ai_disabled_at');
        });
    }
};
