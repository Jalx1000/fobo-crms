<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promociones por servicio (y opcionalmente por sucursal), con vigencia. Los
 * precios de Tessa cambian cada mes: el precio base vive en products.price y
 * la promo vigente se aplica encima durante su ventana valid_from..valid_to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promotions') || ! Schema::hasTable('products')) {
            return;
        }

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->unsignedBigInteger('branch_id')->nullable();
            if (Schema::hasTable('branches')) {
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            }

            $table->string('name')->nullable();
            $table->decimal('price', 12, 4)->nullable();           // precio promocional
            $table->decimal('discount_percent', 5, 2)->nullable(); // o % de descuento
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
