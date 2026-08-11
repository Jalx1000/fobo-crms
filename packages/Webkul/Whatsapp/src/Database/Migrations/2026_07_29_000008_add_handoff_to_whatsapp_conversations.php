<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Handoff: the conversation stops being the AI agent's and becomes a named
 * advisor's responsibility (or the team pool's, when nobody owns the contact).
 *
 * Strings, not enums: altering an enum later needs doctrine/dbal, and the set of
 * states is validated in PHP anyway (see Conversation::HANDOFF_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_state')) {
                // null = never derived. requested | assigned | resolved
                $table->string('handoff_state', 20)->nullable()->after('ai_enabled');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_reason')) {
                // Written by an LLM; truncated to 490 chars before saving.
                $table->string('handoff_reason', 500)->nullable()->after('handoff_state');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_source')) {
                $table->string('handoff_source', 10)->nullable()->after('handoff_reason'); // ai | human
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'handoff_requested_at')) {
                $table->timestamp('handoff_requested_at')->nullable()->after('handoff_source');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'assigned_user_id')) {
                // unsignedInteger, NOT bigInteger: users.id is increments().
                // A mismatch fails with a cryptic errno 150.
                $table->unsignedInteger('assigned_user_id')->nullable()->after('handoff_requested_at');
                $table->foreign('assigned_user_id')->references('id')->on('users')->onDelete('set null');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('assigned_user_id');
            }
        });

        // Serves the inbox filters: "derivadas", "sin asesor" (pool) and "míos".
        if (! $this->hasIndex('wa_conv_handoff_idx')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->index(['handoff_state', 'assigned_user_id'], 'wa_conv_handoff_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if ($this->hasIndex('wa_conv_handoff_idx')) {
                $table->dropIndex('wa_conv_handoff_idx');
            }

            // The foreign key has to go before the column it constrains.
            if (Schema::hasColumn('whatsapp_conversations', 'assigned_user_id')) {
                $table->dropForeign(['assigned_user_id']);
            }

            $table->dropColumn([
                'handoff_state',
                'handoff_reason',
                'handoff_source',
                'handoff_requested_at',
                'assigned_user_id',
                'assigned_at',
            ]);
        });
    }

    /**
     * SHOW INDEX rather than the schema manager: introspecting through Doctrine
     * would pull in doctrine/dbal, the dependency this migration avoids.
     */
    protected function hasIndex(string $name): bool
    {
        $table = DB::getTablePrefix().'whatsapp_conversations';

        return (bool) DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]);
    }
};
