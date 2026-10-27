<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué contestó el paciente al recordatorio ("confirma" / "no_asiste") y cuándo.
 *
 * Antes el "Sí" al recordatorio no quedaba en ningún lado: llegaba al agente
 * como un mensaje más. Ahora el agente lo registra (POST .../appointments/respond)
 * y con esto el contexto sabe qué recordatorios siguen sin respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_appointment_reminders', function (Blueprint $table) {
            $table->string('response', 20)->nullable()->after('sent_at');
            $table->timestamp('responded_at')->nullable()->after('response');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_appointment_reminders', function (Blueprint $table) {
            $table->dropColumn(['response', 'responded_at']);
        });
    }
};
