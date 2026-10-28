<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consentimiento informado firmado por la paciente. Flexible: por paciente
 * (product_id nulo) o por tratamiento (product_id del servicio).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('patient_consents') || ! Schema::hasTable('persons')) {
            return;
        }

        Schema::create('patient_consents', function (Blueprint $table) {
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

            $table->string('document')->nullable();      // nombre/versión del consentimiento
            $table->boolean('accepted')->default(false);
            $table->string('signed_name')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('file_path')->nullable();      // PDF escaneado/firmado (upload en Fase 2)

            $table->unsignedInteger('performed_by')->nullable();
            if (Schema::hasTable('users')) {
                $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
            }

            $table->timestamps();

            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
    }
};
