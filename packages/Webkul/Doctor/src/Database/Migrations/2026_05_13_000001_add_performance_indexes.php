<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Índices en activities para queries de disponibilidad y calendario
        Schema::table('activities', function (Blueprint $table) {
            if (! $this->hasIndex('activities', 'activities_schedule_from_index')) {
                $table->index('schedule_from', 'activities_schedule_from_index');
            }

            if (! $this->hasIndex('activities', 'activities_schedule_to_index')) {
                $table->index('schedule_to', 'activities_schedule_to_index');
            }

            if (! $this->hasIndex('activities', 'activities_type_schedule_from_index')) {
                $table->index(['type', 'schedule_from'], 'activities_type_schedule_from_index');
            }
        });

        // Índice compuesto en doctor_activities para detección de conflictos
        Schema::table('doctor_activities', function (Blueprint $table) {
            if (! $this->hasIndex('doctor_activities', 'doctor_activities_doctor_id_index')) {
                $table->index('doctor_id', 'doctor_activities_doctor_id_index');
            }
        });

        // Índices en doctor_shifts para queries de disponibilidad
        Schema::table('doctor_shifts', function (Blueprint $table) {
            if (! $this->hasIndex('doctor_shifts', 'doctor_shifts_doctor_date_index')) {
                $table->index(['doctor_id', 'date'], 'doctor_shifts_doctor_date_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndexIfExists('activities_schedule_from_index');
            $table->dropIndexIfExists('activities_schedule_to_index');
            $table->dropIndexIfExists('activities_type_schedule_from_index');
        });

        Schema::table('doctor_activities', function (Blueprint $table) {
            $table->dropIndexIfExists('doctor_activities_doctor_id_index');
        });

        Schema::table('doctor_shifts', function (Blueprint $table) {
            $table->dropIndexIfExists('doctor_shifts_doctor_date_index');
        });
    }

    protected function hasIndex(string $table, string $index): bool
    {
        $conn = Schema::getConnection();
        $prefix = $conn->getTablePrefix();
        $indexes = $conn->getSchemaBuilder()->getIndexListing($table);

        return in_array($prefix.$index, $indexes) || in_array($index, $indexes);
    }
};
