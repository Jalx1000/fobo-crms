<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué distribuidor reparte en cada ciudad.
 *
 * Hoy esa relación existe de hecho —cada distribuidor está en un grupo de Krayin que
 * se llama igual que su ciudad— pero eso es una coincidencia de nombres, no un dato.
 * Depender de ella significa que renombrar un grupo rompe la asignación de pedidos
 * sin ningún aviso.
 *
 * Acá queda explícita: un pipeline (ciudad) apunta a un usuario. La tabla se siembra
 * con lo que hoy dicen los grupos, así que al correr la migración la asignación queda
 * igual que estaba, pero deja de depender de cómo se llamen las cosas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_zonas', function (Blueprint $table) {
            $table->increments('id');

            // Única: una ciudad tiene UN distribuidor que reparte. Si algún día son
            // varios, esto se convierte en una tabla de muchos a muchos y hay que
            // decidir cómo se reparte entre ellos — hoy esa pregunta no existe.
            $table->integer('lead_pipeline_id')->unsigned()->unique();
            $table->foreign('lead_pipeline_id')->references('id')->on('lead_pipelines')->onDelete('cascade');

            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });

        $this->sembrarDesdeLosGrupos();
    }

    /**
     * Siembra la tabla con la relación que hoy vive en los grupos.
     *
     * Sólo toma usuarios con rol Distribuidor: en un grupo de ciudad puede haber
     * también un encargado que lo supervisa, y ése no es quien reparte.
     */
    protected function sembrarDesdeLosGrupos(): void
    {
        $rolDistribuidor = DB::table('roles')->where('name', 'Distribuidor')->value('id');

        if (! $rolDistribuidor) {
            return;
        }

        foreach (DB::table('lead_pipelines')->get(['id', 'name']) as $ciudad) {
            $grupo = DB::table('groups')->where('name', $ciudad->name)->value('id');

            if (! $grupo) {
                continue;
            }

            $userId = DB::table('user_groups')
                ->join('users', 'users.id', '=', 'user_groups.user_id')
                ->where('user_groups.group_id', $grupo)
                ->where('users.role_id', $rolDistribuidor)
                ->where('users.status', 1)
                ->orderBy('users.id')
                ->value('users.id');

            if ($userId) {
                DB::table('distribuidor_zonas')->insert([
                    'lead_pipeline_id' => $ciudad->id,
                    'user_id'          => $userId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_zonas');
    }
};
