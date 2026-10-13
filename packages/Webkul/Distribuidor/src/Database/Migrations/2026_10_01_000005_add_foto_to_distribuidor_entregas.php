<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto que confirma la entrega.
 *
 * Es la prueba de que el pedido llegó: cuando un cliente dice que no recibió
 * nada, la fecha sola no alcanza. Obligatoria para marcar entregado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidor_entregas', function (Blueprint $table) {
            // Ruta relativa dentro del disco `public`, igual que media_path en
            // los mensajes de WhatsApp.
            $table->string('foto_path')->nullable()->after('entregado_at');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidor_entregas', function (Blueprint $table) {
            $table->dropColumn('foto_path');
        });
    }
};
