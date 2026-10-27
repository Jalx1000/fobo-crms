<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada agenda/recurso agendable pertenece a una sucursal (Tessa maneja una
 * agenda por sucursal). Nullable para no romper datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('doctors') || ! Schema::hasTable('branches')) {
            return;
        }

        if (! Schema::hasColumn('doctors', 'branch_id')) {
            Schema::table('doctors', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('id');
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('doctors', 'branch_id')) {
            Schema::table('doctors', function (Blueprint $table) {
                try {
                    $table->dropForeign(['branch_id']);
                } catch (\Throwable $e) {
                    // ignore
                }
                $table->dropColumn('branch_id');
            });
        }
    }
};
