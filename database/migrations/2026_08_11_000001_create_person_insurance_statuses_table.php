<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de cobertura POR aseguradora.
 *
 * `persons.insurance_status` solo puede guardar un veredicto, pero un paciente
 * puede tener varias aseguradoras (atributo multiselect `seguro_paciente`) y cada
 * una tiene su propia vigencia. Esta tabla guarda una fila por par
 * (paciente, aseguradora); `persons.insurance_status` se mantiene como el
 * agregado denormalizado que consumen datagrids y filtros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_insurance_statuses', function (Blueprint $table) {
            $table->id();

            // `persons.id` es int unsigned (no bigint): el tipo debe coincidir para la FK.
            $table->unsignedInteger('person_id');

            // Opción de `seguro_paciente` (attribute_options.id) que identifica la aseguradora.
            $table->unsignedInteger('attribute_option_id');

            /**
             * Nombre en el momento de la consulta. Denormalizado a propósito: si mañana
             * renombran la opción, el historial sigue diciendo contra qué se verificó.
             */
            $table->string('seguro_name');

            $table->string('status', 30);
            $table->string('badge', 20)->nullable();
            $table->text('message')->nullable();

            // Respuesta cruda de la aseguradora, para auditoría y para repintar la UI.
            $table->json('payload')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            // Una sola fila vigente por aseguradora y paciente.
            $table->unique(['person_id', 'attribute_option_id'], 'person_insurance_unique');

            $table->index(['person_id', 'status']);

            $table->foreign('person_id')
                ->references('id')
                ->on('persons')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_insurance_statuses');
    }
};
