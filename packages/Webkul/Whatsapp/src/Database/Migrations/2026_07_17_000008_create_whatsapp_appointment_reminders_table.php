<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_appointment_reminders', function (Blueprint $table) {
            $table->increments('id');

            // The appointment (Krayin activity) a reminder was sent for.
            $table->integer('activity_id')->unsigned();

            $table->integer('conversation_id')->unsigned()->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // One reminder per appointment: the scheduled command is idempotent.
            $table->unique('activity_id', 'wa_appt_reminder_activity_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_appointment_reminders');
    }
};
