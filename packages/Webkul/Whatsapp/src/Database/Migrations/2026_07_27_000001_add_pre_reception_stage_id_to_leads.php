<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda la etapa que tenía el lead ANTES de que el agente lo derivara a
 * Recepcionista (CONTRATO B). Al reactivar el agente a mano, se restaura esta
 * etapa para que el lead salga de Recepcionista y aiEffective() vuelva a true.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leads', 'pre_reception_stage_id')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedInteger('pre_reception_stage_id')->nullable()->after('lead_pipeline_stage_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leads', 'pre_reception_stage_id')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('pre_reception_stage_id');
        });
    }
};
