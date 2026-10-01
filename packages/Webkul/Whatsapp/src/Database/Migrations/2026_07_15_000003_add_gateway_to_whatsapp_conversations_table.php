<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // Which provider owns this conversation. Existing rows predate the
            // gateway layer and all came from Cloud API.
            $table->string('gateway')->default('cloud_api')->after('id')->index();

            // Providers that address by their own id instead of a phone number
            // (Kommo uses its lead id).
            $table->string('provider_conversation_id')->nullable()->after('wa_phone')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex(['gateway']);
            $table->dropIndex(['provider_conversation_id']);
            $table->dropColumn(['gateway', 'provider_conversation_id']);
        });
    }
};
