<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sin `after('smd_patient_id')`: esa columna la crea otra migración con
        // la MISMA fecha (2026_05_19_000001), y en una base desde cero Laravel
        // ordena por nombre —"insurance" antes que "smd"—, así que esta corría
        // primero y fallaba porque smd_patient_id aún no existía. La posición de
        // la columna es cosmética; el orden ya no importa. Los guardas hasColumn
        // dejan la migración repetible.
        Schema::table('persons', function (Blueprint $table) {
            if (! Schema::hasColumn('persons', 'insurance_status')) {
                $table->string('insurance_status', 30)->nullable();
            }

            if (! Schema::hasColumn('persons', 'insurance_checked_at')) {
                $table->timestamp('insurance_checked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            if (Schema::hasColumn('persons', 'insurance_status')) {
                $table->dropColumn('insurance_status');
            }

            if (Schema::hasColumn('persons', 'insurance_checked_at')) {
                $table->dropColumn('insurance_checked_at');
            }
        });
    }
};
