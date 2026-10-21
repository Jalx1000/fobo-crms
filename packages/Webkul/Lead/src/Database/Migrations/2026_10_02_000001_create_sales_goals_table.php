<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_goals', function (Blueprint $table) {
            $table->id();

            /*
            | A quién aplica. NULL es la meta del equipo entero.
            |
            | Nulo y no una fila aparte con un id especial: así el gerente fija
            | «el equipo cierra 10 este mes» y además «Alejandro cierra 3», y las
            | dos conviven sin inventar un usuario ficticio «Equipo».
            */
            $table->unsignedInteger('user_id')->nullable();

            /*
            | El período, como 'YYYY-MM'.
            |
            | Texto y no dos columnas de fecha: una meta es de un mes
            | calendario, no de un rango arbitrario, y '2026-10' se ordena y se
            | compara igual que una fecha sin la ambigüedad de los bordes.
            */
            $table->string('periodo', 7);

            /*
            | Qué se mide. No es un enum de base de datos a propósito: agregar
            | 'visitas' no debería requerir una migración. La lista válida vive
            | en config/reporting.php, que es donde se puede cambiar sin deploy.
            */
            $table->string('metrica', 30);

            // Decimal y no entero: 'monto' lleva centavos, 'ventas' no.
            $table->decimal('objetivo', 15, 2);

            // Solo para metas de monto. La cartera mezcla USD y Bs y convertir
            // inventaría un tipo de cambio.
            $table->string('moneda', 3)->nullable();

            $table->unsignedInteger('created_by')->nullable();

            $table->timestamps();

            /*
            | Una sola meta por persona, período y métrica. Sin esto, dos
            | gerentes cargando la misma meta dejan dos filas y el avance se
            | calcula contra la que la consulta devuelva primero.
            */
            $table->unique(['user_id', 'periodo', 'metrica'], 'sales_goals_unico');

            // El tablero siempre filtra por período primero.
            $table->index(['periodo', 'metrica']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_goals');
    }
};
