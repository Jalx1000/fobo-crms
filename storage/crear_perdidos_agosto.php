<?php
/*
 * Pedidos de agosto que nunca entraron al CRM (31/10/2026). Sofía respondió
 * "pedido registrado" pero no se creó el lead. Por decisión del usuario se
 * registran como Pedidos cancelados (quedan anotados, no cuentan como venta).
 *   Yovanna (#679, La Paz)        29/08  1× Promo Varietales                                  Bs 239  Stefani
 *   Mariela Saavedra (#689, SCZ)  30/08  1× Promo Varietales + 1× Tinto Clásico + 1× Clásico Blanco  Bs 279  Liliana
 * Productos y cantidades tal cual la última confirmación del Historial IA. Sin eventos.
 */
use Illuminate\Support\Facades\DB;
const A_TITLE = 20, A_DESC = 21, A_VALUE = 22, A_SOURCE = 23, A_TYPE = 24, A_USER = 25, A_PIPE = 27, A_STAGE = 28, A_EDAD = 68, A_CIUDAD = 69;
$casos = [
  ['person' => 679, 'conv' => 7, 'user' => 4, 'pipe' => 7, 'stage' => 30, 'ciudad' => 7, 'edad' => '70',
   'created' => '2026-08-29 09:21:23', 'confirmed' => '2026-08-29 09:39:00', 'title' => 'Promo Varietales',
   'desc' => '1 Promo Varietales — Syrah Block 21, Cabernet Sauvignon, Malbec Block 32, Raiz Tannat',
   'lines' => [[43, 1, 239]],
   'nota' => 'Creado el 31/10/2026 (auditoría de agosto): el 29/08 la clienta confirmó 1× Promo Varietales (Bs 239) con Sofía y recibió "pedido registrado", pero el lead nunca se creó y nadie la contactó (solo los avisos automáticos del 16/09). Se registra como cancelado por decisión del usuario.'],
  ['person' => 689, 'conv' => null, 'user' => 9, 'pipe' => 3, 'stage' => 14, 'ciudad' => 3, 'edad' => '28',
   'created' => '2026-08-30 16:35:06', 'confirmed' => '2026-08-30 17:01:00', 'title' => 'Promo Varietales',
   'desc' => '1 Promo Varietales; 1 Tinto Clásico (2025); 1 Clásico Blanco (2025). Total Bs 279',
   'lines' => [[43, 1, 239], [1, 1, 20], [2, 1, 20]],
   'nota' => 'Creado el 31/10/2026 (auditoría de agosto): el 30/08 la clienta confirmó con Sofía 1× Promo Varietales + 1× Tinto Clásico + 1× Clásico Blanco (Bs 279) y recibió "pedido registrado", pero el lead nunca se creó. El 07/09 escribió "No se pusieron en contacto". Se registra como cancelado por decisión del usuario. Su lead #1354 (15/09) es posterior.'],
];
foreach ($casos as $c) {
  if (DB::table('leads')->where('person_id', $c['person'])->where('created_at', $c['created'])->exists()) exit("Ya aplicado: no se hace nada.\n");
  if (! DB::table('persons')->where('id', $c['person'])->exists()) exit("No existe el contacto #{$c['person']}\n");
}
$setAttr = function (int $lead, int $attr, string $col, $val) {
  DB::table('attribute_values')->insert(['entity_type' => 'leads', 'entity_id' => $lead, 'attribute_id' => $attr, $col => $val]);
};
$creados = [];
DB::transaction(function () use ($casos, $setAttr, &$creados) {
  foreach ($casos as $c) {
    $valor = array_sum(array_map(fn ($x) => $x[1] * $x[2], $c['lines']));
    $id = DB::table('leads')->insertGetId([
      'title' => $c['title'], 'description' => $c['desc'], 'lead_value' => $valor, 'lead_value_is_manual' => 0, 'status' => 1,
      'closed_at' => now(), 'confirmed_at' => $c['confirmed'], 'first_stage_change_at' => $c['confirmed'],
      'user_id' => $c['user'], 'person_id' => $c['person'], 'lead_source_id' => 6, 'lead_type_id' => 1,
      'lead_pipeline_id' => $c['pipe'], 'lead_pipeline_stage_id' => $c['stage'], 'expected_close_date' => null,
      'created_at' => $c['created'], 'updated_at' => now(),
    ]);
    foreach ([[A_TITLE, 'text_value', $c['title']], [A_DESC, 'text_value', $c['desc']], [A_VALUE, 'float_value', $valor], [A_SOURCE, 'integer_value', 6],
      [A_TYPE, 'integer_value', 1], [A_USER, 'integer_value', $c['user']], [A_PIPE, 'integer_value', $c['pipe']], [A_STAGE, 'integer_value', $c['stage']],
      [A_EDAD, 'text_value', $c['edad']], [A_CIUDAD, 'integer_value', $c['ciudad']]] as [$a, $col, $v]) $setAttr($id, $a, $col, $v);
    foreach ($c['lines'] as [$pid, $q, $pr]) DB::table('lead_products')->insert(['lead_id' => $id, 'product_id' => $pid, 'quantity' => $q, 'price' => $pr, 'amount' => $q * $pr, 'created_at' => $c['confirmed'], 'updated_at' => $c['confirmed']]);
    if ($c['conv']) DB::table('whatsapp_conversations')->where('id', $c['conv'])->whereNull('lead_id')->update(['lead_id' => $id]);
    $aid = DB::table('activities')->insertGetId(['type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(), 'comment' => $c['nota']]);
    DB::table('lead_activities')->insert(['activity_id' => $aid, 'lead_id' => $id]);
    $creados[] = $id;
  }
});
foreach ($creados as $id) {
  $r = DB::selectOne('select l.id, l.created_at, s.name e, u.name a, p.name pn, round(l.lead_value) v,
    (select group_concat(concat(lp.quantity, "x ", pr.name, " @", round(lp.price)) separator " + ") from kl_lead_products lp join kl_products pr on pr.id = lp.product_id where lp.lead_id = l.id) li,
    (select group_concat(id) from kl_whatsapp_conversations where lead_id = l.id) conv
    from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id join kl_users u on u.id = l.user_id join kl_persons p on p.id = l.person_id where l.id = ?', [$id]);
  echo "#{$r->id} {$r->created_at} | {$r->pn} | {$r->e} | {$r->a} | Bs {$r->v} | {$r->li} | conv {$r->conv}\n";
}
