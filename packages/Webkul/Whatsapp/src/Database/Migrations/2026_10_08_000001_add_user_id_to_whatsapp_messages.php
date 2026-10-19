<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién mandó el mensaje, cuando lo mandó una persona.
 *
 * `sender` ya distinguía 'ia' de 'human', pero no CUÁL humano, así que no había
 * forma de medir el tiempo de respuesta por asesor. Se intentó deducirlo del
 * asesor asignado a la conversación y no alcanza: de 374 respuestas humanas,
 * sólo 29 tienen asignación —un 8%—, porque la mayoría de los asesores escribe
 * sin tomar la derivación.
 *
 * El histórico queda en null; se llena de acá en adelante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_messages', 'user_id')) {
                $table->unsignedInteger('user_id')->nullable()->after('sender');
                $table->index('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_messages', 'user_id')) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
