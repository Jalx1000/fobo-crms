<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías transversales del servicio (Facial / Corporal / Depilación),
 * aparte de la LÍNEA (especialidad). Un servicio puede pertenecer a MÁS DE UNA
 * (ej. Depilación láser = Depilación + Facial por las zonas de rostro).
 * Pregunta 6 del brief: "el CRM debe permitir que un servicio pertenezca a más
 * de una categoría".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_categories')) {
            Schema::create('service_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_service_category') && Schema::hasTable('products')) {
            Schema::create('product_service_category', function (Blueprint $table) {
                $table->unsignedInteger('product_id');
                $table->unsignedBigInteger('service_category_id');

                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
                $table->foreign('service_category_id')->references('id')->on('service_categories')->onDelete('cascade');

                $table->primary(['product_id', 'service_category_id'], 'product_service_category_pk');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_service_category');
        Schema::dropIfExists('service_categories');
    }
};
