<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién cerró la derivación, y por qué vía.
 *
 * Hasta ahora `closed_at` decía cuándo se cerró pero no quién: con el cierre
 * automático a las 8 horas eso deja de ser un detalle, porque «cerrada» pasa a
 * significar dos cosas muy distintas —la atendieron, o nadie la contestó— y el
 * asesor necesita distinguirlas de un vistazo en el timeline del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_handoffs', function (Blueprint $table) {
            $table->unsignedInteger('cerrado_uid')->nullable()->after('closed_at');

            // Nulo mientras está vigente. 'sistema' es el único valor donde
            // cerrado_uid queda en null a propósito.
            $table->string('cierre_motivo', 20)->nullable()->after('cerrado_uid');

            $table->foreign('cerrado_uid')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_handoffs', function (Blueprint $table) {
            $table->dropForeign(['cerrado_uid']);
            $table->dropColumn(['cerrado_uid', 'cierre_motivo']);
        });
    }
};
