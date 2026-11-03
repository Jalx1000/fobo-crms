<?php

/*
 * 01/11/2026 — Las notas de la auditoría de datos pasan a "solo Administrador".
 *
 * Son las notas sin usuario creadas desde el 31/10/2026 que NO son
 * derivaciones del agente IA (esas las tienen que seguir viendo los asesores).
 * Se auditaron 108 (83 en leads y 25 en contactos) + la del #952 del 01/11 = 109.
 * Requiere la migración 2026_11_01_000000_add_admin_only_to_activities_table.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasColumn('activities', 'admin_only')) {
    exit("Falta la columna admin_only: correr primero la migración.\n");
}

$q = fn () => DB::table('activities')->where('type', 'note')->whereNull('user_id')
    ->where('created_at', '>=', '2026-10-31')->where('comment', 'not like', 'Derivaci%');

$ids = $q()->pluck('id');
if ($ids->count() !== 109) {
    exit("No se aplica nada: se esperaban 109 notas y hay {$ids->count()}.\n");
}

file_put_contents('/code/docs/notas_auditoria_admin_only_ids.json', json_encode($ids->all()));

$n = DB::table('activities')->whereIn('id', $ids)->where('admin_only', false)->update(['admin_only' => true]);
echo "Marcadas {$n} (ya estaban: ".(109 - $n).")\n";
echo 'Total admin_only: '.DB::table('activities')->where('admin_only', true)->count()."\n";
