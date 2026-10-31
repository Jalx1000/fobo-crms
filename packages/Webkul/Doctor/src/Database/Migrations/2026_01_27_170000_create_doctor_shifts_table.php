<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('doctor_shifts')) {
            Schema::create('doctor_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('doctor_id');
                $table->date('date');
                $table->time('start_time');
                $table->time('end_time');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('doctor_id')->references('id')->on('doctors')->onDelete('cascade');
                $table->index(['doctor_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_shifts');
    }
};
