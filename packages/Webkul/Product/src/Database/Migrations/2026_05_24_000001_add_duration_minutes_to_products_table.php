<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')
                  ->nullable()
                  ->after('price')
                  ->comment('Duración estimada del servicio en minutos. Null = el agente usa su default (60 min).');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
