<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zonas / variantes de un servicio (product). Cada servicio (Depilación láser,
 * HIFU, ...) tiene una o más zonas, y es la ZONA la que lleva la duración y el
 * precio (depilación axilas 30 min, piernas 30 min con otro precio, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_zones') || ! Schema::hasTable('products')) {
            return;
        }

        Schema::create('service_zones', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->string('name');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_zones');
    }
};
