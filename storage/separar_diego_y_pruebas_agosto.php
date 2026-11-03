<?php

/*
 * Agosto 2026 (31/10/2026).
 *
 * 1. #647 Diego Álvarez (contacto #488): el agente reabrió el lead de agosto
 *    (ya entregado) y le escribió encima 3 pedidos de octubre. Decisión del
 *    usuario: fechas bien separadas → cada pedido en su lead, todos entregados.
 *      #647  13/08 La Paz     1× Promo Varietales            Bs 239  entregado 27/08 (Stefani)
 *      nuevo 07/10 Santa Cruz 1× Icono Don Julio (con caja) @390 + 1× Icono Elia Rosa @420  Bs 810 (Liliana)
 *      nuevo 16/10 La Paz     4× Raiz Tannat @80             Bs 320  (Stefani)
 *      nuevo 28/10 La Paz     2× Querkus @270 + 4× Raiz @80  Bs 860  entregado 30/10 (Stefani), con la conversación
 *    El de julio (#523, Icónicos) ya estaba aparte.
 *    Los del 07/10 y 16/10 no tienen fecha de entrega registrada: se cierran con
 *    la hora en que se registró el pedido, y la nota lo dice.
 *
 * 2. Leads de prueba hechos con el teléfono de Gabriel Muñoz (contacto #525) →
 *    Otros Servicios, con nota.
 *
 * Verifica antes de tocar nada; directo a la base y en una transacción, sin eventos.
 */

use Illuminate\Support\Facades\DB;

const A_TITLE = 20, A_DESC = 21, A_VALUE = 22, A_SOURCE = 23, A_TYPE = 24, A_USER = 25, A_PIPE = 27, A_STAGE = 28, A_EDAD = 68, A_CIUDAD = 69;

$pruebas = [573, 694, 733, 748, 1781, 2768];

if (DB::table('lead_products')->where('lead_id', 647)->where('product_id', 43)->exists()) {
    exit("Ya aplicado: no se hace nada.\n");
}

// ---- verificaciones ----
$errores = [];
$firma = DB::table('lead_products')->where('lead_id', 647)->orderBy('product_id')->get()
    ->map(fn ($l) => "{$l->product_id}x{$l->quantity}@".round($l->price))->implode(',');
$l647 = DB::table('leads')->find(647);
if ($firma !== '126x4@80,180x2@270' || (float) $l647->lead_value !== 860.0 || $l647->person_id !== 488) {
    $errores[] = "#647 no está como se auditó ({$firma}, Bs {$l647->lead_value}, contacto {$l647->person_id})";
}
foreach ($pruebas as $id) {
    $l = DB::table('leads')->find($id);
    if (! $l || $l->person_id !== 525) {
        $errores[] = "#{$id} no es del contacto #525";
    } elseif (! DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $l->lead_pipeline_id)->where('code', 'otros-servicios')->exists()) {
        $errores[] = "#{$id}: su pipeline {$l->lead_pipeline_id} no tiene Otros Servicios";
    }
}
if ($errores) {
    exit("No se aplica nada:\n  ".implode("\n  ", $errores)."\n");
}

file_put_contents('/code/docs/separacion_diego_y_pruebas_agosto_respaldo.json', json_encode([
    'leads'            => DB::table('leads')->whereIn('id', array_merge([647], $pruebas))->get()->toArray(),
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->whereIn('entity_id', array_merge([647], $pruebas))->get()->toArray(),
    'lead_products'    => DB::table('lead_products')->where('lead_id', 647)->get()->toArray(),
    'conversations'    => DB::table('whatsapp_conversations')->where('id', 3608)->get(['id', 'lead_id'])->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// La descripción que el agente escribió en ese minuto, textual (la última de ese minuto).
$descAt = function (int $lead, string $minuto): string {
    $r = null;
    foreach (DB::select("select a.additional from kl_activities a join kl_lead_activities la on la.activity_id = a.id
        where la.lead_id = ? and a.created_at like ? order by a.id", [$lead, $minuto.'%']) as $a) {
        $ad = json_decode((string) $a->additional, true);
        if (($ad['attribute'] ?? '') === 'Descripción del pedido') {
            $r = (string) $ad['new']['value'];
        }
    }
    if ($r === null) {
        throw new RuntimeException("No encontré la descripción del #{$lead} a las {$minuto}");
    }

    return $r;
};

$setAttr = function (int $lead, int $attr, string $col, $val) {
    $q = DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $lead)->where('attribute_id', $attr);
    $q->exists()
        ? $q->update([$col => $val])
        : DB::table('attribute_values')->insert(['entity_type' => 'leads', 'entity_id' => $lead, 'attribute_id' => $attr, $col => $val]);
};

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
    $edad = DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $from)->where('attribute_id', A_EDAD)->value('text_value');
    if ($edad !== null) {
        $setAttr($id, A_EDAD, 'text_value', $edad);
    }
    $setAttr($id, A_CIUDAD, 'integer_value', $c['ciudad']);

    return $id;
};

$hoy = '31/10/2026';
$creados = [];

DB::transaction(function () use ($descAt, $write, $lines, $note, $nuevo, $setAttr, $hoy, $pruebas, &$creados) {

    /* ---------- Diego: 07/10 Santa Cruz (P3, entregado 13) ---------- */
    $scz = $nuevo(647, [
        'title' => 'Promo Vinos Icónico', 'description' => $descAt(647, '2026-10-07 10:14'),
        'lead_value' => 810, 'user_id' => 9, 'lead_pipeline_id' => 3, 'lead_pipeline_stage_id' => 13, 'ciudad' => 3,
        'created_at' => '2026-10-07 10:07:27', 'confirmed_at' => '2026-10-07 10:14:00', 'closed_at' => '2026-10-07 10:14:00',
    ]);
    $lines($scz, [[110, 1, 390, '2026-10-07 10:14:00'], [19, 1, 420, '2026-10-07 10:14:00']]);

    /* ---------- Diego: 16/10 La Paz (P7, entregado 29) ---------- */
    $raiz = $nuevo(647, [
        'title' => 'Pedido de Raiz Tannat', 'description' => $descAt(647, '2026-10-16 14:53'),
        'lead_value' => 320, 'user_id' => 4, 'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 29, 'ciudad' => 7,
        'created_at' => '2026-10-16 14:41:00', 'confirmed_at' => '2026-10-16 14:53:00', 'closed_at' => '2026-10-16 14:53:00',
    ]);
    $lines($raiz, [[126, 4, 80, '2026-10-16 14:53:00']]);

    /* ---------- Diego: 28/10 La Paz (lo que tiene hoy el #647) ---------- */
    $cur = DB::table('leads')->find(647);
    $oct = $nuevo(647, [
        'title' => $cur->title, 'description' => $cur->description, 'lead_value' => 860, 'user_id' => 4,
        'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 29, 'ciudad' => 7,
        'created_at' => '2026-10-28 22:04:00', 'confirmed_at' => '2026-10-28 22:07:00', 'closed_at' => $cur->closed_at,
    ]);
    DB::table('lead_products')->where('lead_id', 647)->update(['lead_id' => $oct]);
    DB::table('whatsapp_conversations')->where('id', 3608)->update(['lead_id' => $oct]);

    /* ---------- #647 vuelve a ser el pedido de agosto ---------- */
    $write(647, ['title' => 'Promo Varietales', 'description' => $descAt(647, '2026-08-13 23:21'), 'lead_value' => 239, 'user_id' => 4,
        'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 29, 'closed_at' => '2026-08-27 23:23:21', 'confirmed_at' => '2026-08-17 16:43:12']);
    $setAttr(647, A_CIUDAD, 'integer_value', 7);
    $lines(647, [[43, 1, 239, '2026-08-13 23:21:00']]);

    $note(647, "Restaurado el {$hoy}: esta venta de agosto (1× Promo Varietales, Bs 239, entregada por Stefani el 27/08) fue reabierta por el agente el 07/10 y le escribió encima tres pedidos de octubre. Cada uno pasó a su lead: 07/10 Santa Cruz → #{$scz}, 16/10 La Paz → #{$raiz}, 28/10 La Paz → #{$oct}. El pedido de julio (Icónicos) es el #523.");
    $sinFecha = 'No hay fecha de entrega registrada (el pedido se pisó antes de cerrarse): se marcó entregado por decisión del usuario, con la hora en que se registró el pedido.';
    $note($scz, "Creado el {$hoy} al separar el #647: pedido del 07/10 en Santa Cruz (chat de Cloud API). {$sinFecha}");
    $note($raiz, "Creado el {$hoy} al separar el #647: pedido del 16/10 en La Paz (chat de Cloud API). {$sinFecha}");
    $note($oct, "Creado el {$hoy} al separar el #647: pedido del 28/10, entregado por Stefani el 30/10. Tiene la conversación de WhatsApp.");
    $creados = [$scz, $raiz, $oct];

    /* ---------- pruebas de Gabriel → Otros Servicios ---------- */
    foreach ($pruebas as $id) {
        $l = DB::table('leads')->find($id);
        $s = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $l->lead_pipeline_id)->where('code', 'otros-servicios')->value('id');
        $write($id, ['lead_pipeline_stage_id' => $s, 'closed_at' => $l->closed_at ?? now()]);
        $note($id, "{$hoy}: lead de prueba hecho con el teléfono del asesor Gabriel Muñoz (contacto #525). Se pasa a Otros Servicios para que no cuente como cliente ni como venta.");
    }
});

foreach (array_merge([523, 647], $creados, $pruebas) as $id) {
    $l = DB::selectOne('select l.id, round(l.lead_value) v, s.name e, l.lead_pipeline_id p, l.created_at, l.closed_at, u.name a,
        (select group_concat(concat(lp.quantity, "x ", p.name, " @", round(lp.price)) separator " + ") from kl_lead_products lp join kl_products p on p.id = lp.product_id where lp.lead_id = l.id) pr,
        (select coalesce(sum(quantity * price), 0) from kl_lead_products where lead_id = l.id) li,
        (select group_concat(id) from kl_whatsapp_conversations c where c.lead_id = l.id) conv
        from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id left join kl_users u on u.id = l.user_id where l.id = ?', [$id]);
    echo "#{$l->id} P{$l->p} {$l->created_at} | {$l->e} | {$l->a} | Bs {$l->v} (líneas ".(float) $l->li.") | {$l->pr} | conv {$l->conv} | cerrado {$l->closed_at}\n";
}
