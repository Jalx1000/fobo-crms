<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            if (! Schema::hasColumn('persons', 'smd_patient_id')) {
                $table->string('smd_patient_id')->nullable()->after('unique_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            if (Schema::hasColumn('persons', 'smd_patient_id')) {
                $table->dropColumn('smd_patient_id');
            }
        });
    }
};
