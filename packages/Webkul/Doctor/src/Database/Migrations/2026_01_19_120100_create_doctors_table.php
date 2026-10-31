<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->string('name')->unique();
            $table->string('title')->nullable();
            $table->string('unique_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('doctor_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('doctor_id');
            $table->unsignedInteger('activity_id');
            $table->foreign('doctor_id')->references('id')->on('doctors')->onDelete('cascade');
            $table->foreign('activity_id')->references('id')->on('activities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_activities');
        Schema::dropIfExists('doctors');
    }
};
