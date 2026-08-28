<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inmuebles', function (Blueprint $table) {
            $table->increments('id');

            // Público: el código que el cliente ve en el anuncio y el único
            // identificador que el agente de IA manipula (D9). El CRM propone
            // uno (C21-0001) y el asesor lo puede pisar con el del anuncio.
            $table->string('codigo', 32)->unique();

            $table->enum('operacion', ['venta', 'alquiler', 'anticretico']);

            // Tipo como string, no enum: la lista crece (quinta, penthouse,
            // depósito...) y no queremos una migración por cada tipo nuevo.
            // Se valida contra config('inmobiliaria.inmuebles.tipos').
            $table->string('tipo', 40);

            // Estado SÍ es enum: gobierna qué ve el agente (D4) y no debe poder
            // escribirse texto libre desde ningún lado.
            $table->enum('estado', [
                'borrador', 'disponible', 'reservado', 'vendido', 'alquilado', 'suspendido',
            ])->default('borrador');

            $table->string('titulo', 160);

            // "Una línea real de la ficha", no un adjetivo genérico (§6 del
            // handoff). Si viene null, la API cae a la 1ª oración de descripcion.
            $table->string('destacado', 200)->nullable();
            $table->text('descripcion')->nullable();

            /*
            |------------------------------------------------------------------
            | Ubicación — público vs gateado (D6)
            |------------------------------------------------------------------
            | zona, barrio y referencia son siempre públicos: el agente los usa
            | para ubicar al cliente. Los tres campos de abajo NO salen nunca en
            | la ficha; la única ruta a ellos es el endpoint de ubicación, que
            | los lee server-side y los envía sin que pasen por el LLM.
            */
            $table->string('zona', 120)->index();
            $table->string('barrio', 120)->nullable()->index();
            $table->string('referencia', 200)->nullable();

            $table->string('direccion_exacta')->nullable();
            $table->string('google_maps_url', 500)->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();

            // Dónde se encuentra el asesor con el cliente para la visita.
            $table->string('punto_encuentro')->nullable();

            /*
            |------------------------------------------------------------------
            | Precio — nunca se convierte entre monedas (D4)
            |------------------------------------------------------------------
            | Cada inmueble se guarda y se muestra en la moneda en que el
            | propietario lo puso. `modalidad_cambio` es una condición comercial
            | real que varía por ficha ("al T/C oficial BCB" vs "al paralelo") y
            | que el agente debe mostrar literal.
            */
            $table->decimal('precio', 15, 2);
            $table->decimal('precio_anterior', 15, 2)->nullable();
            $table->char('moneda', 3)->default('USD');
            $table->enum('modalidad_cambio', ['solo_usd', 'tc_oficial', 'tc_paralelo'])->nullable();
            $table->boolean('negociable')->default(false);

            /*
            |------------------------------------------------------------------
            | Métricas — lo filtrable en columnas, lo específico en JSON (D13)
            |------------------------------------------------------------------
            | Un galpón trae altura y m² de oficinas; una casa trae el frente.
            | Todo eso vive en `caracteristicas`: se muestra, no se filtra.
            */
            $table->unsignedTinyInteger('dormitorios')->nullable();
            $table->unsignedTinyInteger('banos')->nullable();
            $table->unsignedTinyInteger('parqueos')->nullable();
            $table->decimal('m2_construidos', 10, 2)->nullable();
            $table->decimal('m2_terreno', 10, 2)->nullable();
            $table->json('caracteristicas')->nullable();

            // Asesor titular. RESTRICT y no CASCADE a propósito: si un asesor
            // deja la oficina, sus inmuebles deben reasignarse explícitamente.
            // Borrarlos en silencio dejaría al agente ofreciendo fantasmas.
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');

            $table->timestamp('publicado_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'operacion', 'tipo'], 'inmuebles_busqueda_index');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inmuebles');
    }
};
