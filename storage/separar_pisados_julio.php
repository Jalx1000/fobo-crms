<?php

/*
 * Leads de julio pisados por pedidos posteriores (31/10/2026).
 *
 * El agente reabrió estos leads y les escribió encima pedidos de septiembre u
 * octubre. Cada pedido vuelve a tener su propio lead, con su fecha, sus
 * productos y su estado; el de julio queda como estaba en julio.
 *
 *   #515 Víctor Catari:  jul 12× Fundador (entregado) | sep Don Julio + Elia Rosa (entregado) | oct Querkus… (abierto)
 *   #478 Henry Menacho:  jul 1× Varietales (entregado 03/08) | oct 1× Mujer Boliviana (entregado 23/10)
 *   #425 Adri Rivera:    jul 2× Varietales (abierto) | oct ya existe aparte en el #6044
 *   #482 Mariel:         jul vacío (sin interés) | sep 5× Primavera (abierto) | oct 3× Mujer Boliviana (abierto)
 *
 * Las descripciones de los pedidos viejos se toman textuales del registro de
 * actividades. Directo a la base y en una transacción, sin eventos (no avisa a
 * nadie ni dispara automatizaciones).
 *
 * Correr: docker exec -u www-data -e HOME=/tmp -w /code <contenedor> php artisan tinker storage/separar_pisados_julio.php
 */

use Illuminate\Support\Facades\DB;

if (DB::table('lead_products')->where('lead_id', 515)->where('product_id', 3)->where('quantity', 12)->exists()) {
    exit("Ya aplicado: no se hace nada.\n");
}

$leads = [515, 478, 425, 482, 6044];
file_put_contents('/code/docs/separacion_pisados_julio_respaldo.json', json_encode([
    'leads'            => DB::table('leads')->whereIn('id', $leads)->get()->toArray(),
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->whereIn('entity_id', $leads)->get()->toArray(),
    'lead_products'    => DB::table('lead_products')->whereIn('lead_id', $leads)->get()->toArray(),
    'conversations'    => DB::table('whatsapp_conversations')->whereIn('id', [3512, 6662, 6657, 3480])->get(['id', 'lead_id'])->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

const A_TITLE = 20, A_DESC = 21, A_VALUE = 22, A_SOURCE = 23, A_TYPE = 24, A_USER = 25, A_PIPE = 27, A_STAGE = 28, A_EDAD = 68, A_CIUDAD = 69;

// La descripción que el agente escribió en ese momento, textual.
$descAt = function (int $lead, string $at): string {
    foreach (DB::select("select a.additional from kl_activities a join kl_lead_activities la on la.activity_id = a.id
        where la.lead_id = ? and a.created_at = ?", [$lead, $at]) as $a) {
        $ad = json_decode((string) $a->additional, true);
        if (($ad['attribute'] ?? '') === 'Descripción del pedido') {
            return (string) $ad['new']['value'];
        }
    }
    throw new RuntimeException("No encontré la descripción del #{$lead} a las {$at}");
};

$setAttr = function (int $lead, int $attr, string $col, $val) {
    $q = DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $lead)->where('attribute_id', $attr);
    $q->exists()
        ? $q->update([$col => $val])
        : DB::table('attribute_values')->insert(['entity_type' => 'leads', 'entity_id' => $lead, 'attribute_id' => $attr, $col => $val]);
};

// Escribe columnas y sus attribute_values espejo.
$write = function (int $id, array $c) use ($setAttr) {
    DB::table('leads')->where('id', $id)->update($c + ['updated_at' => now()]);
    $map = ['title' => [A_TITLE, 'text_value'], 'description' => [A_DESC, 'text_value'], 'lead_value' => [A_VALUE, 'float_value'],
        'user_id' => [A_USER, 'integer_value'], 'lead_pipeline_id' => [A_PIPE, 'integer_value'], 'lead_pipeline_stage_id' => [A_STAGE, 'integer_value']];
    foreach ($map as $col => [$attr, $vcol]) {
        if (array_key_exists($col, $c)) {
            $setAttr($id, $attr, $vcol, $c[$col]);
        }
    }
};

$lines = function (int $lead, array $rows) {
    foreach ($rows as [$pid, $qty, $price, $at]) {
        DB::table('lead_products')->insert(['lead_id' => $lead, 'product_id' => $pid, 'quantity' => $qty, 'price' => $price,
            'amount' => $qty * $price, 'created_at' => $at, 'updated_at' => $at]);
    }
};

$note = function (int $lead, string $text) {
    $id = DB::table('activities')->insertGetId(['type' => 'note', 'comment' => $text, 'is_done' => 1, 'user_id' => null,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => $lead]);
};

// Lead nuevo a partir del original: copia persona, fuente, tipo, edad y ciudad.
$nuevo = function (int $from, array $c) use ($write, $setAttr) {
    $o = DB::table('leads')->find($from);
    $id = DB::table('leads')->insertGetId([
        'title' => $c['title'], 'description' => $c['description'], 'lead_value' => $c['lead_value'], 'lead_value_is_manual' => 0,
        'status' => 1, 'lost_reason' => null, 'closed_at' => $c['closed_at'], 'confirmed_at' => $c['confirmed_at'],
        'first_stage_change_at' => $c['confirmed_at'], 'user_id' => $c['user_id'], 'person_id' => $o->person_id,
        'lead_source_id' => $o->lead_source_id, 'lead_type_id' => $o->lead_type_id, 'lead_pipeline_id' => $c['lead_pipeline_id'],
        'lead_pipeline_stage_id' => $c['lead_pipeline_stage_id'], 'expected_close_date' => null,
        'created_at' => $c['created_at'], 'updated_at' => now(),
    ]);
    $write($id, array_intersect_key($c, array_flip(['title', 'description', 'lead_value', 'user_id', 'lead_pipeline_id', 'lead_pipeline_stage_id'])));
    $setAttr($id, A_SOURCE, 'integer_value', $o->lead_source_id);
    $setAttr($id, A_TYPE, 'integer_value', $o->lead_type_id);
    foreach ([A_EDAD => 'text_value', A_CIUDAD => 'integer_value'] as $attr => $col) {
        $v = DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $from)->where('attribute_id', $attr)->value($col);
        if ($v !== null) {
            $setAttr($id, $attr, $col, $v);
        }
    }

    return $id;
};

$hoy = '31/10/2026';
$creados = [];

DB::transaction(function () use ($descAt, $write, $lines, $note, $nuevo, $hoy, &$creados) {

    /* ---------- #515 Víctor Catari (La Paz: confirmado 28, entregado 29) ---------- */
    $sep = $nuevo(515, [
        'title' => 'Pedido Club del Vino - . - Víctor Catari', 'description' => $descAt(515, '2026-09-29 14:46:25'),
        'lead_value' => 750, 'user_id' => 4, 'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 29,
        'created_at' => '2026-09-29 14:43:39', 'confirmed_at' => '2026-09-29 14:46:25', 'closed_at' => '2026-09-29 17:28:05',
    ]);
    $lines($sep, [[110, 1, 330, '2026-09-29 14:46:25'], [19, 1, 420, '2026-09-29 14:46:25']]);

    $cur = DB::table('leads')->find(515);
    $oct = $nuevo(515, [
        'title' => $cur->title, 'description' => $cur->description, 'lead_value' => 481, 'user_id' => 4,
        'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 28,
        'created_at' => '2026-10-31 00:44:24', 'confirmed_at' => '2026-10-31 00:51:09', 'closed_at' => null,
    ]);
    DB::table('lead_products')->where('lead_id', 515)->update(['lead_id' => $oct]);
    DB::table('whatsapp_conversations')->where('id', 3512)->update(['lead_id' => $oct]);

    $write(515, ['title' => '12 vinos fundador', 'description' => 'Fundador', 'lead_value' => 288, 'user_id' => 4,
        'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 29, 'closed_at' => '2026-09-29 14:27:33', 'confirmed_at' => '2026-07-28 23:38:55']);
    $lines(515, [[3, 12, 24, '2026-07-28 23:38:55']]);

    $note(515, "Restaurado el {$hoy}: el agente reabrió este pedido de julio (12× Fundador, Bs 288) y le cargó encima dos pedidos posteriores. El de septiembre (Icono Don Julio + Icono Elia Rosa, Bs 750, entregado 29/09) pasó al #{$sep} y el de octubre (Querkus + 3 Fundador + Cautivo, Bs 481) al #{$oct}.");
    $note($sep, "Creado el {$hoy} al separar el #515: este pedido de septiembre se había registrado encima del de julio. Datos tomados del registro de actividades y del chat del 29/09.");
    $note($oct, "Creado el {$hoy} al separar el #515: este pedido de octubre se había registrado encima del de julio. Tiene la conversación de WhatsApp.");
    $creados['515'] = [$sep, $oct];

    /* ---------- #478 Henry Menacho (Tarija: entregado 5) ---------- */
    $cur = DB::table('leads')->find(478);
    $oct = $nuevo(478, [
        'title' => $cur->title, 'description' => $cur->description, 'lead_value' => 90, 'user_id' => 14,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'created_at' => '2026-10-16 21:23:29', 'confirmed_at' => '2026-10-16 21:26:44', 'closed_at' => '2026-10-23 11:24:03',
    ]);
    DB::table('lead_products')->where('lead_id', 478)->update(['lead_id' => $oct]);
    DB::table('whatsapp_conversations')->where('id', 6662)->update(['lead_id' => $oct]);

    $write(478, ['title' => 'Consulta agendada', 'description' => 'Primer contacto', 'lead_value' => 239, 'user_id' => 14,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'closed_at' => '2026-08-03 11:11:26', 'confirmed_at' => null]);
    $lines(478, [[43, 1, 239, '2026-08-28 06:14:37']]);

    $note(478, "Restaurado el {$hoy}: esta venta (entregada el 03/08, Bs 239) fue pisada el 16/10 por un pedido de Promo Mujer Boliviana, que pasó al #{$oct}. La línea 1× Promo Varietales se deduce del chat del 27/07 (\"quiero hacer un pedido Promo Varietales\") y del valor; no estaba cargada.");
    $note($oct, "Creado el {$hoy} al separar el #478: este pedido de octubre se había registrado encima de la venta de julio. Tiene la conversación de WhatsApp.");
    $creados['478'] = [$oct];

    /* ---------- #425 Adri Rivera (vuelve a Cochabamba: confirmado 32, Jorge) ---------- */
    DB::table('lead_products')->where('lead_id', 425)->delete();   // la misma línea ya está en el #6044
    DB::table('whatsapp_conversations')->where('id', 6657)->update(['lead_id' => 6044]);
    $write(425, ['title' => 'Pedido Promo Varietales', 'description' => $descAt(425, '2026-07-14 15:51:04'), 'lead_value' => 478,
        'user_id' => 5, 'lead_pipeline_id' => 8, 'lead_pipeline_stage_id' => 32]);
    DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', 425)->where('attribute_id', A_CIUDAD)->update(['integer_value' => 8]);
    DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', 425)->where('attribute_id', A_EDAD)->update(['text_value' => '32']);
    $lines(425, [[43, 2, 239, '2026-07-14 15:50:19']]);

    $note(425, "Restaurado el {$hoy}: el 16/10 el agente reabrió este pedido de julio (2× Promo Varietales, Bs 478, Cochabamba) y lo cambió por 1× Mujer Boliviana en Tarija. Ese pedido de octubre ya existía en el #6044, que ahora tiene la conversación. Este pedido de julio sigue sin cerrar: confirmar con Jorge si se entregó.");
    $note(6044, "{$hoy}: se le pasó la conversación de WhatsApp del 16/10, que estaba enlazada al #425 (pedido de julio).");

    /* ---------- #482 Mariel (Cochabamba: confirmado 32, sin interés 47) ---------- */
    $sep = $nuevo(482, [
        'title' => 'Pedido de Promo Primavera', 'description' => $descAt(482, '2026-09-27 21:31:09'),
        'lead_value' => 375, 'user_id' => 15, 'lead_pipeline_id' => 8, 'lead_pipeline_stage_id' => 32,
        'created_at' => '2026-09-27 21:28:00', 'confirmed_at' => '2026-09-27 21:31:09', 'closed_at' => null,
    ]);
    $lines($sep, [[65, 5, 75, '2026-09-27 21:31:09']]);

    $cur = DB::table('leads')->find(482);
    $oct = $nuevo(482, [
        'title' => $cur->title, 'description' => $cur->description, 'lead_value' => 270, 'user_id' => 15,
        'lead_pipeline_id' => 8, 'lead_pipeline_stage_id' => 32,
        'created_at' => '2026-10-16 01:29:31', 'confirmed_at' => '2026-10-16 11:45:40', 'closed_at' => null,
    ]);
    DB::table('lead_products')->where('lead_id', 482)->update(['lead_id' => $oct]);
    DB::table('whatsapp_conversations')->where('id', 3480)->update(['lead_id' => $oct]);

    $write(482, ['title' => 'Consulta agendada', 'description' => 'Primer contacto', 'lead_value' => 0, 'user_id' => 5,
        'lead_pipeline_id' => 8, 'lead_pipeline_stage_id' => 47, 'closed_at' => now()]);

    $aviso = "La clienta escribió el 13/10 \"aún puedo recoger el pedido\" y el 16/10 pidió unir \"5 promo primavera y 3 mujer boliviana\"; el agente no reconoció la Primavera. Contactarla.";
    $note(482, "Revisado el {$hoy}: en julio este lead nunca tuvo pedido (Bs 0); se cierra como sin interés. El agente lo reutilizó para la reserva de septiembre (5× Primavera, ahora #{$sep}) y la pisó el 16/10 con 3× Mujer Boliviana (ahora #{$oct}).");
    $note($sep, "Creado el {$hoy} al separar el #482: reserva de septiembre que el pedido de octubre había borrado. {$aviso}");
    $note($oct, "Creado el {$hoy} al separar el #482. {$aviso}");
    $creados['482'] = [$sep, $oct];
});

echo 'Leads nuevos: '.json_encode($creados)."\n";
foreach (array_merge([515, 478, 425, 482, 6044], ...array_values($creados)) as $id) {
    $l = DB::selectOne('select l.id, l.title, round(l.lead_value) v, s.name e, l.created_at, l.closed_at, u.name a,
        (select group_concat(concat(lp.quantity, "x ", p.name, " @", round(lp.price)) separator " + ") from kl_lead_products lp join kl_products p on p.id = lp.product_id where lp.lead_id = l.id) pr,
        (select coalesce(sum(quantity * price), 0) from kl_lead_products where lead_id = l.id) li,
        (select group_concat(id) from kl_whatsapp_conversations c where c.lead_id = l.id) conv
        from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id left join kl_users u on u.id = l.user_id where l.id = ?', [$id]);
    echo "#{$l->id} {$l->created_at} | {$l->e} | {$l->a} | Bs {$l->v} (líneas ".(float) $l->li.") | {$l->pr} | conv {$l->conv} | cerrado {$l->closed_at}\n";
}
