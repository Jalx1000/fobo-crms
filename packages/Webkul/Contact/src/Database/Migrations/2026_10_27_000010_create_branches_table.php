<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales de primera clase. El CRM maneja varias sucursales (Santa Cruz,
 * Asunción, ...), cada una con su agenda, horarios, base de pacientes, moneda
 * y zona horaria. Reemplaza al atributo EAV `sucursal` (que queda como legado).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches')) {
            return;
        }

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('currency', 3)->default('BOB');        // BOB (Bolivia) / PYG (Paraguay)
            $table->string('timezone')->default('America/La_Paz'); // Asunción = America/Asuncion
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
