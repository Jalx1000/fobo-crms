<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            /*
            | En qué moneda se cerró la operación.
            |
            | Krayin asume una sola moneda de instancia (`config('app.currency')`),
            | y acá el mercado es bimonetario: una casa se cierra en dólares y un
            | lote en bolivianos. Sin esta columna, "247.000" no dice cuánto se
            | vendió — y como no convertimos en ningún punto del sistema, tampoco
            | se puede deducir después.
            |
            | Nullable y aditiva: el core la ignora por completo.
            */
            $table->char('lead_value_moneda', 3)->nullable()->after('lead_value');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('lead_value_moneda');
        });
    }
};
