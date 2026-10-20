<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Asesor Asignado" pasa a ofrecer sólo distribuidores.
 *
 * El asesor de un pedido es quien lo reparte, así que ofrecer administradores y
 * encargados en el desplegable es ofrecer gente que nunca va a salir a entregar — y
 * elegirlos deja el pedido fuera de la lista de todos los distribuidores.
 *
 * 🔴 Sólo los dos campos que se llaman "Asesor Asignado": pedidos y contactos. NO se
 * tocan "Propietario de Ventas" de cotizaciones ni de organizaciones, que son otra
 * cosa —dueño comercial de la cuenta— y donde un encargado sí corresponde.
 *
 * Los pedidos ya asignados a un administrador se siguen VIENDO: el resolver del valor
 * actual (`getLookUpEntity`) no filtra por rol, sólo el listado de opciones. Lo que
 * cambia es que no se los puede volver a elegir.
 */
return new class extends Migration
{
    /** Los atributos que pasan a usar el lookup filtrado. */
    protected const CAMPOS = [
        ['code' => 'user_id', 'entity_type' => 'leads'],
        ['code' => 'user_id', 'entity_type' => 'persons'],
    ];

    public function up(): void
    {
        $this->apuntarA('distribuidores');
    }

    public function down(): void
    {
        $this->apuntarA('users');
    }

    protected function apuntarA(string $lookup): void
    {
        foreach (self::CAMPOS as $campo) {
            DB::table('attributes')
                ->where('code', $campo['code'])
                ->where('entity_type', $campo['entity_type'])
                ->update(['lookup_type' => $lookup, 'updated_at' => now()]);
        }
    }
};
