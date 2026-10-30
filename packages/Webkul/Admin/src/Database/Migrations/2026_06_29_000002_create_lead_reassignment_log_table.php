<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría de reasignaciones de dueño de leads (comando
     * leads:reassign-synced-to-unassigned). Guarda el dueño previo para permitir
     * trazabilidad y --rollback.
     */
    public function up(): void
    {
        Schema::create('lead_reassignment_log', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('lead_id')->index();
            $table->unsignedInteger('old_user_id')->nullable();
            $table->unsignedInteger('new_user_id')->nullable();
            $table->string('reason')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_reassignment_log');
    }
};
