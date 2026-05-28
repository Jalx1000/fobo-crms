<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->string('insurance_status', 30)->nullable()->after('smd_patient_id');
            $table->timestamp('insurance_checked_at')->nullable()->after('insurance_status');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn(['insurance_status', 'insurance_checked_at']);
        });
    }
};
