<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condiciones de entrega del producto.
 *
 * Son por producto y no por cotización porque no las decide el asesor: las
 * decide qué se vende. Las tapas impresas tardan 20 días se las cotice a
 * quien se las cotice; el stretch film va a domicilio a partir de 50 rollos
 * siempre. La cotización las PROPONE desde acá y el asesor las ajusta.
 *
 * Datos y no reglas en código: el día que el umbral de stretch film pase de 50
 * a 40 rollos es cambiar un número en el producto, no un deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tiempo_entrega', 60)->default('Inmediata')->after('ejes_precio');
            $table->string('lugar_entrega', 60)->default('Puesto almacén cliente')->after('tiempo_entrega');

            // A partir de cuántas unidades se lleva a domicilio. Null = nunca
            // (bolsas, tapas), o sea que el lugar es siempre `lugar_entrega`.
            $table->unsignedInteger('entrega_domicilio_desde')->nullable()->after('lugar_entrega');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tiempo_entrega', 'lugar_entrega', 'entrega_domicilio_desde']);
        });
    }
};
