<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->index()
                  ->comment('SHA-256 del Bearer token. Nunca el token en claro.');
            $table->string('ci_hash', 64)->index()
                  ->comment('SHA-256 del ci_paciente. Nunca el CI en claro.');
            $table->string('seguro_hash', 64)
                  ->comment('SHA-256 del seguro_paciente normalizado (lowercase, trim).');
            $table->boolean('result')
                  ->comment('true = has_insurance:true, false = has_insurance:false.');
            $table->string('ip_address', 45)->nullable()
                  ->comment('IP del cliente.');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_audit_logs');
    }
};
