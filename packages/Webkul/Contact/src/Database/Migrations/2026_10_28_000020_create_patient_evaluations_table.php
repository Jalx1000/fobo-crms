<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de evaluación previa por paciente/tratamiento. Las preguntas las define
 * Tessa (entregable pendiente), por eso las respuestas van en JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('patient_evaluations') || ! Schema::hasTable('persons')) {
            return;
        }

        Schema::create('patient_evaluations', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('person_id');
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');

            $table->unsignedInteger('activity_id')->nullable();
            if (Schema::hasTable('activities')) {
                $table->foreign('activity_id')->references('id')->on('activities')->nullOnDelete();
            }

            $table->unsignedInteger('product_id')->nullable();
            if (Schema::hasTable('products')) {
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            }

            $table->json('answers')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedInteger('performed_by')->nullable();
            if (Schema::hasTable('users')) {
                $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
            }

            $table->dateTime('performed_at')->nullable();
            $table->timestamps();

            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_evaluations');
    }
};
