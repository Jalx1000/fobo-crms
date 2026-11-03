<?php

/*
 * #713 Albin Pardo (contacto #643) — 31/10/2026.
 *
 * El lead se creó el 22/08 (pidió Promo Varietales en el Historial IA y no
 * confirmó; Stefani lo pasó a confirmado el 24/08 con Bs 0). Quedó abierto y el
 * 15–16/10 el agente lo reutilizó para un pedido nuevo (1× Promo Mujer
 * Boliviana, Bs 90, Cloud API).
 *
 * Decisión del usuario: separar y dejar los dos en Cliente confirmado.
 *   #713  22/08  sin productos, Bs 0         → Cliente confirmado
 *   nuevo 15/10  1× Promo Mujer Boliviana 90 → Cliente confirmado, con la conversación 6441
 *
 * Directo a la base y en una transacción, sin eventos.
 */

use Illuminate\Support\Facades\DB;

const A_TITLE = 20, A_DESC = 21, A_VALUE = 22, A_SOURCE = 23, A_TYPE = 24, A_USER = 25, A_PIPE = 27, A_STAGE = 28, A_EDAD = 68, A_CIUDAD = 69;

$l = DB::table('leads')->find(713);
$firma = DB::table('lead_products')->where('lead_id', 713)->get()->map(fn ($x) => "{$x->product_id}x".(int) $x->quantity)->implode(',');
if ($firma === '' && (float) $l->lead_value === 0.0) {
    exit("Ya aplicado: no se hace nada.\n");
}
if ($firma !== '169x1' || (float) $l->lead_value !== 90.0 || $l->person_id !== 643
    || DB::table('whatsapp_conversations')->where('id', 6441)->value('lead_id') !== 713) {
    exit("No se aplica nada: el #713 no está como se auditó ({$firma}, Bs {$l->lead_value}).\n");
}

file_put_contents('/code/docs/separacion_albin_agosto_respaldo.json', json_encode([
    'leads'            => [$l],
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', 713)->get()->toArray(),
    'lead_products'    => DB::table('lead_products')->where('lead_id', 713)->get()->toArray(),
    'conversations'    => DB::table('whatsapp_conversations')->where('id', 6441)->get(['id', 'lead_id'])->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
$note = function (int $lead, string $text) {
    $id = DB::table('activities')->insertGetId(['type' => 'note', 'comment' => $text, 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => $lead]);
};

$nuevo = null;
DB::transaction(function () use ($l, $write, $setAttr, $note, &$nuevo) {
    $nuevo = DB::table('leads')->insertGetId([
        'title' => $l->title, 'description' => $l->description, 'lead_value' => 90, 'lead_value_is_manual' => 0,
        'status' => 1, 'lost_reason' => null, 'closed_at' => null, 'confirmed_at' => '2026-10-16 20:22:00',
        'first_stage_change_at' => '2026-10-16 20:22:00', 'user_id' => 4, 'person_id' => 643,
        'lead_source_id' => $l->lead_source_id, 'lead_type_id' => $l->lead_type_id, 'lead_pipeline_id' => 7,
        'lead_pipeline_stage_id' => 28, 'expected_close_date' => null,
        'created_at' => '2026-10-15 22:26:50', 'updated_at' => now(),
    ]);
    $write($nuevo, ['title' => $l->title, 'description' => $l->description, 'lead_value' => 90, 'user_id' => 4, 'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 28]);
    $setAttr($nuevo, A_SOURCE, 'integer_value', $l->lead_source_id);
    $setAttr($nuevo, A_TYPE, 'integer_value', $l->lead_type_id);
    $setAttr($nuevo, A_EDAD, 'text_value', '26');
    $setAttr($nuevo, A_CIUDAD, 'integer_value', 7);
    DB::table('lead_products')->where('lead_id', 713)->update(['lead_id' => $nuevo]);
    DB::table('whatsapp_conversations')->where('id', 6441)->update(['lead_id' => $nuevo]);

    $write(713, ['title' => 'Consulta agendada', 'description' => 'Primer contacto', 'lead_value' => 0, 'user_id' => 4,
        'lead_pipeline_id' => 7, 'lead_pipeline_stage_id' => 28, 'closed_at' => null, 'confirmed_at' => '2026-08-24 13:19:04']);
    $setAttr(713, A_EDAD, 'text_value', '25');
    $setAttr(713, A_CIUDAD, 'integer_value', 7);

    $note(713, "Separado el 31/10/2026: este lead es del 22/08 (pidió Promo Varietales en el Historial IA, sin confirmar; Stefani lo pasó a confirmado el 24/08). Quedó abierto y el agente lo reutilizó el 15–16/10 para un pedido nuevo (1× Promo Mujer Boliviana, Bs 90), que pasó al #{$nuevo}. Por decisión del usuario queda en Cliente confirmado.");
    $note($nuevo, 'Creado el 31/10/2026 al separar el #713: pedido del 15–16/10 (Cloud API) que se había registrado en el lead de agosto. Tiene la conversación de WhatsApp. Por decisión del usuario queda en Cliente confirmado (en el #713 figuraba entregado por Stefani el 28/10).');
});

foreach ([713, $nuevo] as $id) {
    $r = DB::selectOne('select l.id, l.created_at, l.closed_at, round(l.lead_value) v, s.name e, u.name a,
        (select group_concat(concat(lp.quantity, "x ", p.name, " @", round(lp.price))) from kl_lead_products lp join kl_products p on p.id = lp.product_id where lp.lead_id = l.id) pr,
        (select group_concat(id) from kl_whatsapp_conversations where lead_id = l.id) conv
        from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id left join kl_users u on u.id = l.user_id where l.id = ?', [$id]);
    echo "#{$r->id} {$r->created_at} | {$r->e} | {$r->a} | Bs {$r->v} | {$r->pr} | conv {$r->conv} | cerrado {$r->closed_at}\n";
}
