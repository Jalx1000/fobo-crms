<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paciente ↔ responsable: quien agenda (padre, madre, tutor, familiar) no es
 * necesariamente quien se atiende. Un paciente puede tener varios responsables
 * (los dos padres, cada uno desde su número) y un responsable varios pacientes.
 *
 * Ver planning/14-pacientes-terceros/00.diseno.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_relations', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('patient_person_id')->unsigned();
            $table->integer('responsible_person_id')->unsigned();

            // Qué es el PACIENTE para el responsable:
            // hijo | padre_madre | pareja | hermano | familiar | otro
            $table->string('relation', 30)->nullable();

            // Por dónde se creó el vínculo: whatsapp_flow | recepcion | smd
            $table->string('source', 30)->nullable();

            $table->timestamps();

            $table->unique(['patient_person_id', 'responsible_person_id'], 'person_relations_pair_unique');
            $table->index('responsible_person_id');

            $table->foreign('patient_person_id')->references('id')->on('persons')->onDelete('cascade');
            $table->foreign('responsible_person_id')->references('id')->on('persons')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_relations');
    }
};
