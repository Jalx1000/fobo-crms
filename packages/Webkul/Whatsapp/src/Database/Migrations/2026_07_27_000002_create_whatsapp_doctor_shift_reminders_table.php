<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotencia del aviso de inicio de turno al doctor: una vez por doctor por
 * día. Un envío fallido (message.status = failed) deja al doctor elegible de
 * nuevo en la próxima corrida.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_doctor_shift_reminders')) {
            return;
        }

        Schema::create('whatsapp_doctor_shift_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('doctor_id');
            $table->date('date');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['doctor_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_doctor_shift_reminders');
    }
};
