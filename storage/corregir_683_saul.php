<?php
/*
 * #683 Saúl (19/08/2026, Santa Cruz, entregado por Liliana el 20/08) — 31/10/2026.
 * El cliente confirmó 2 Promo Vinos Iconos (Tempranillo + Malbec ×2, Bs 1.198) y
 * Liliana tomó el control de la conversación; el agente había registrado el valor
 * con 1 promo (Bs 599). La línea ya estaba en 2× pero con amount 0.
 * Decisión del usuario: son 2 → valor 1.198 y amount 1.198. Sin eventos.
 */
use Illuminate\Support\Facades\DB;
$l = DB::table('leads')->find(683);
$lp = DB::table('lead_products')->where('lead_id', 683)->get();
if ((float) $l->lead_value === 1198.0) exit("Ya aplicado: no se hace nada.\n");
if ($lp->count() !== 1 || (int) $lp[0]->product_id !== 42 || (float) $lp[0]->quantity !== 2.0 || (float) $l->lead_value !== 599.0) exit("No se aplica: el #683 no está como se auditó.\n");
file_put_contents('/code/docs/correccion_683_saul_respaldo.json', json_encode(['lead' => $l, 'lead_products' => $lp,
  'attribute_values' => DB::table('attribute_values')->where('entity_type','leads')->where('entity_id',683)->where('attribute_id',22)->get()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
DB::transaction(function () use ($lp) {
  DB::table('lead_products')->where('id', $lp[0]->id)->update(['amount' => 1198]);
  DB::table('leads')->where('id', 683)->update(['lead_value' => 1198, 'updated_at' => now()]);
  DB::table('attribute_values')->where('entity_type','leads')->where('entity_id',683)->where('attribute_id',22)->update(['float_value' => 1198]);
  $id = DB::table('activities')->insertGetId(['type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
    'comment' => '31/10/2026: el valor estaba en Bs 599 (1 promo), pero el cliente confirmó 2 Promo Vinos Iconos (Tempranillo + Malbec ×2, Bs 1.198) y Liliana tomó la conversación. Se corrigió el valor a Bs 1.198; la línea ya decía 2×.']);
  DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => 683]);
});
$r = DB::selectOne('select round(lead_value) v, (select concat(quantity,"x @",round(price)," = ",round(amount)) from kl_lead_products where lead_id=683) li from kl_leads where id=683');
echo "#683 Bs {$r->v} | línea {$r->li}\n";
