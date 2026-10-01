<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De «un responsable por producto y ciudad» a un equipo que se turna.
 *
 * El equipo de ventas de Imprimir no es uno por casilla: en Santa Cruz los
 * hules los atiende Fabio y también Mariano, que cubre todo el país. Con un
 * único responsable por casilla había que elegir a uno y el otro nunca
 * recibía nada. Ahora cada casilla es una lista y el CRM la recorre por turnos
 * (round robin): `ultimo_asignado_at` es el único estado que hace falta para
 * eso —el siguiente es siempre el que hace más tiempo no recibe—.
 *
 * La cobertura nacional se guarda como una ciudad más («Bolivia», ver
 * `cotizacion.cobertura_nacional`). Es texto porque las ciudades también lo
 * son, y así no hay que enseñarle a nadie una columna nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cada paso se comprueba antes de hacerse: la migración ya se corrió a
        // medias en producción (Laravel agrega columnas antes que índices, y
        // el primer intento falló en el índice), así que tiene que poder
        // retomarse desde donde quedó.
        if (! Schema::hasColumn('producto_responsables', 'ultimo_asignado_at')) {
            Schema::table('producto_responsables', function (Blueprint $table) {
                $table->timestamp('ultimo_asignado_at')->nullable()->after('user_id');
            });
        }

        // El índice único viejo es el que sostiene la FK de product_id: MySQL
        // no deja borrarlo hasta que exista otro que empiece por esa columna.
        // Por eso se crea el nuevo primero y se borra el viejo después.
        if (! $this->tieneIndice('producto_responsable_por_persona')) {
            Schema::table('producto_responsables', function (Blueprint $table) {
                $table->unique(['product_id', 'ciudad', 'user_id'], 'producto_responsable_por_persona');
            });
        }

        if ($this->tieneIndice('producto_responsable_unico')) {
            Schema::table('producto_responsables', function (Blueprint $table) {
                $table->dropUnique('producto_responsable_unico');
            });
        }

        // El agente le dice al cliente con quién va a hablar y cómo ubicarlo:
        // el teléfono del asesor es dato del CRM, no del prompt.
        if (! Schema::hasColumn('users', 'telefono')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('telefono', 30)->nullable()->after('horario');
            });
        }
    }

    protected function tieneIndice(string $nombre): bool
    {
        $tabla = DB::getTablePrefix().'producto_responsables';

        // SQL crudo: el query builder le pondría el prefijo `kl_` a
        // information_schema como si fuera una tabla propia.
        return DB::selectOne(
            'select count(*) as n from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ?',
            [$tabla, $nombre]
        )->n > 0;
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telefono');
        });

        Schema::table('producto_responsables', function (Blueprint $table) {
            $table->unique(['product_id', 'ciudad'], 'producto_responsable_unico');
        });

        Schema::table('producto_responsables', function (Blueprint $table) {
            $table->dropUnique('producto_responsable_por_persona');
            $table->dropColumn('ultimo_asignado_at');
        });
    }
};
