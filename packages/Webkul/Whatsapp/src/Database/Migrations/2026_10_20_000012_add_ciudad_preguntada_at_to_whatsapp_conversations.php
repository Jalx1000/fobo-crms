<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se le preguntó la ciudad al cliente.
 *
 * Sin ciudad no hay a qué distribuidor asignarle el cliente, y el pedido queda sin
 * dueño. El CRM le pregunta la ciudad por WhatsApp antes de que se cierre la
 * ventana de 24 h —después ya no se le puede escribir sin plantilla— y esta marca
 * evita preguntársela dos veces en la misma ventana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->timestamp('ciudad_preguntada_at')->nullable()->after('last_inbound_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('ciudad_preguntada_at');
        });
    }
};
