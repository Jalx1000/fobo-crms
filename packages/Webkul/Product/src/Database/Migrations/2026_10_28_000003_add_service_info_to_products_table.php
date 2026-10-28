<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del SERVICIO para que el agente sepa de qué trata y cada cuánto se
 * repite (columnas "Información y beneficios" y "Sesión y frecuencia" del
 * cuadro de Tessa). La duración/precio siguen viviendo en service_zones.
 *
 * - description (ya existe) = Información y beneficios.
 * - frequency_text          = Sesión y frecuencia (texto literal).
 * - min_interval_days       = intervalo mínimo entre sesiones (90/30/7...).
 * - recommended_sessions_min/max = sesiones sugeridas (p. ej. depilación 6–10).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'frequency_text')) {
                $table->text('frequency_text')->nullable()->after('description');
            }
            if (! Schema::hasColumn('products', 'min_interval_days')) {
                $table->unsignedInteger('min_interval_days')->nullable()->after('frequency_text');
            }
            if (! Schema::hasColumn('products', 'recommended_sessions_min')) {
                $table->unsignedSmallInteger('recommended_sessions_min')->nullable()->after('min_interval_days');
            }
            if (! Schema::hasColumn('products', 'recommended_sessions_max')) {
                $table->unsignedSmallInteger('recommended_sessions_max')->nullable()->after('recommended_sessions_min');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['frequency_text', 'min_interval_days', 'recommended_sessions_min', 'recommended_sessions_max'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
