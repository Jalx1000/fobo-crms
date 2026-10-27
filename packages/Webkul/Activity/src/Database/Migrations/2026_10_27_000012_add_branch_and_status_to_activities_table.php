<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citas: sucursal + estado de la cita + enlace de reprogramación.
 *
 * status: scheduled | confirmed | completed | no_show | cancelled | rescheduled
 * (ver Webkul\Activity\Enums\AppointmentStatus). Todo nullable/aditivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        Schema::table('activities', function (Blueprint $table) {
            if (! Schema::hasColumn('activities', 'branch_id') && Schema::hasTable('branches')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('user_id');
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            }

            if (! Schema::hasColumn('activities', 'status')) {
                $table->string('status')->nullable()->index()->after('is_done');
            }

            if (! Schema::hasColumn('activities', 'rescheduled_to_activity_id')) {
                $table->unsignedInteger('rescheduled_to_activity_id')->nullable()->after('status');
                $table->foreign('rescheduled_to_activity_id')->references('id')->on('activities')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        Schema::table('activities', function (Blueprint $table) {
            foreach (['rescheduled_to_activity_id', 'branch_id'] as $col) {
                if (Schema::hasColumn('activities', $col)) {
                    try {
                        $table->dropForeign([$col]);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                    $table->dropColumn($col);
                }
            }

            if (Schema::hasColumn('activities', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
