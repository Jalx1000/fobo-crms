<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            /*
            | Cuándo se mandó el último recordatorio de cierre de ventana.
            |
            | UNA SOLA COLUMNA Y NO UNA TABLA DE ENVÍOS, a propósito. El requisito
            | es «máximo 1 por ventana», y una ventana queda identificada por su
            | `last_inbound_at`: cada mensaje del cliente abre una nueva.
            |
            | Entonces «ya se envió en esta ventana» es exactamente
            | `followup_sent_at >= last_inbound_at`, y el contador se reinicia
            | solo cuando el cliente vuelve a escribir. Sin tabla, sin job que
            | cancelar, sin estado que pueda quedar desincronizado.
            */
            $table->timestamp('followup_sent_at')->nullable()->after('last_inbound_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('followup_sent_at');
        });
    }
};
