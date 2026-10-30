<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ancla el recordatorio al `external_id` de la cita en SMD, además del
 * `activity_id`.
 *
 * El seguimiento de "ya recordado" se hacía sólo por `activity_id`, que es un
 * autoincrement local: al re-sincronizar (borrar y recrear las citas) los ids
 * cambian, los recordatorios quedan huérfanos y el scheduler vuelve a mandar a
 * quien ya había recibido. El `external_id` de SMD es estable entre re-syncs,
 * así que enganchar el recordatorio a él cierra ese agujero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_appointment_reminders', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('activity_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_appointment_reminders', function (Blueprint $table) {
            $table->dropIndex(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
