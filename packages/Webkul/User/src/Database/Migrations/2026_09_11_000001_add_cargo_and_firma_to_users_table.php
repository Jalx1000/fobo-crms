<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargo y firma del asesor.
 *
 * Los dos van en la firma de lo que el asesor manda: una cotización o un correo
 * cierra con «Juan Pérez · Ejecutivo comercial» y su firma escaneada. Sin estos
 * campos cada asesor tenía que pegarlos a mano, y cada uno lo hacía distinto.
 *
 * Columnas reales y no atributos personalizados: `users` no pasa por el sistema
 * de atributos de Krayin (es la única entidad que no lo usa), igual que `image`
 * y `horario`, que ya son columnas.
 *
 * `firma` guarda la RUTA en el disco público, como `image`. La imagen en sí no
 * va en la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cargo', 120)->nullable()->after('name');
            $table->string('firma')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cargo', 'firma']);
        });
    }
};
