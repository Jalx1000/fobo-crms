<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smd_synced_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique()->index();
            $table->string('source_file')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->text('raw_payload')->nullable();
            $table->string('status', 30)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smd_synced_events');
    }
};
