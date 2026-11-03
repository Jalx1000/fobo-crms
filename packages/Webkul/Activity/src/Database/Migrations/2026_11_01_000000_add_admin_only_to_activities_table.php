<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Actividades que sólo ve un rol con acceso total (Administrador).
 *
 * Las notas que deja la auditoría de datos (uniones de contactos, valores
 * corregidos, leads separados o eliminados) tienen que quedar registradas,
 * pero los asesores no necesitan verlas en el historial del lead o del
 * contacto. El filtro está en el modelo Activity (AdminOnlyScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->boolean('admin_only')->default(false)->after('is_done');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('admin_only');
        });
    }
};
