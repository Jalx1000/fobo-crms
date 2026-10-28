<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una promoción puede apuntar a una zona específica (ej. "Axilas") además del
 * servicio. Nullable: null = promo a nivel servicio (todas sus zonas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promotions') || ! Schema::hasTable('service_zones')) {
            return;
        }

        if (! Schema::hasColumn('promotions', 'service_zone_id')) {
            Schema::table('promotions', function (Blueprint $table) {
                $table->unsignedBigInteger('service_zone_id')->nullable()->after('product_id');
                $table->foreign('service_zone_id')->references('id')->on('service_zones')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('promotions', 'service_zone_id')) {
            Schema::table('promotions', function (Blueprint $table) {
                try {
                    $table->dropForeign(['service_zone_id']);
                } catch (\Throwable $e) {
                    // ignore
                }
                $table->dropColumn('service_zone_id');
            });
        }
    }
};
