<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_appointment_reminders', function (Blueprint $table) {
            // The message the reminder was sent as. Idempotency now checks its
            // status: a failed send leaves the appointment eligible for retry,
            // instead of being marked reminded forever.
            $table->unsignedInteger('message_id')->nullable()->after('conversation_id');

            $table->foreign('message_id')
                ->references('id')->on('whatsapp_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_appointment_reminders', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
            $table->dropColumn('message_id');
        });
    }
};
