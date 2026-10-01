<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the inbox needs once it holds more than one channel.
 *
 * - provider_account_id: which of OUR accounts owns the thread. A Messenger
 *   PSID is scoped to the page that issued it, so without this a reply on a
 *   multi-page install could leave through the wrong page.
 *
 * - avatar_url: the contact's picture where the channel exposes one. Stored on
 *   the conversation rather than fetched per render — Meta's CDN URLs expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'provider_account_id')) {
                $table->string('provider_account_id')
                    ->nullable()
                    ->after('provider_conversation_id')
                    ->index('wa_conv_provider_account_idx');
            }

            if (! Schema::hasColumn('whatsapp_conversations', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('wa_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_conversations', 'provider_account_id')) {
                $table->dropIndex('wa_conv_provider_account_idx');
                $table->dropColumn('provider_account_id');
            }

            if (Schema::hasColumn('whatsapp_conversations', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
        });
    }
};
