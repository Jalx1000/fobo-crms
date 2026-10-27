<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento de "no-show": cuando una paciente falta sin avisar, se le manda
 * UNA sola vez el mensaje de reprogramación, pasada la hora de su cita + 2 h.
 * Esta tabla garantiza la idempotencia (un follow-up por cita).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_no_show_followups')) {
            return;
        }

        Schema::create('whatsapp_no_show_followups', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('activity_id')->unsigned();
            $table->integer('conversation_id')->unsigned()->nullable();
            $table->integer('message_id')->unsigned()->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Un seguimiento por cita: el comando es idempotente.
            $table->unique('activity_id', 'wa_no_show_activity_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_no_show_followups');
    }
};
