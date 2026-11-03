<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quién, además de los administradores, puede elegir el asesor de un pedido o de
     * un cliente. Hasta ahora lo podía hacer cualquier encargado.
     *
     * Arranca habilitado sólo para el responsable (Huascar), que es quien reparte a
     * mano lo que no tiene distribuidor.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('puede_asignar')->default(false)->after('view_permission');
        });

        $email = (string) config('distribuidor.responsable_email');

        if ($email !== '') {
            DB::table('users')->where('email', $email)->update(['puede_asignar' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('puede_asignar');
        });
    }
};
