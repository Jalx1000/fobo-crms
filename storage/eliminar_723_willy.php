<?php
/*
 * Willy Gonzáles Rivas (contacto #642) — 31/10/2026, decisión del usuario.
 *  - Elimina el #723: duplicado del #712. El 24/08 el cliente escribió "hoy no
 *    podré pasar, mañana" y el agente abrió otro lead con el mismo pedido.
 *  - El #712 (1× Promo Varietales, Bs 239) pasa a Pedidos entregados. No hay
 *    fecha de entrega registrada: se usa el 25/08 09:00, día que el cliente
 *    avisó que pasaba por la mañana.
 * Respaldo completo antes de borrar. Sin eventos.
 */
use Illuminate\Support\Facades\DB;
if (! DB::table('leads')->where('id', 723)->exists()) exit("Ya aplicado: no se hace nada.\n");
$l = DB::table('leads')->find(723); $o = DB::table('leads')->find(712);
$sig = fn ($id) => DB::table('lead_products')->where('lead_id', $id)->get()->map(fn ($x) => $x->product_id.'x'.(int) $x->quantity)->implode(',');
if ($l->person_id !== 642 || $o->person_id !== 642 || $sig(723) !== '43x1' || $sig(712) !== '43x1' || $o->lead_pipeline_id !== 7
    || DB::table('whatsapp_conversations')->whereIn('lead_id', [712, 723])->exists()) exit("No se aplica: no está como se auditó.\n");
$acts = DB::table('lead_activities')->where('lead_id', 723)->pluck('activity_id');
if (DB::table('lead_activities')->whereIn('activity_id', $acts)->where('lead_id', '<>', 723)->exists()
    || DB::table('person_activities')->whereIn('activity_id', $acts)->exists()) exit("No se aplica: hay actividades compartidas.\n");
file_put_contents('/code/docs/eliminacion_723_willy_respaldo.json', json_encode([
  'lead' => $l, 'lead_712_antes' => $o,
  'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->whereIn('entity_id', [712, 723])->get(),
  'lead_products' => DB::table('lead_products')->where('lead_id', 723)->get(),
  'lead_activities' => DB::table('lead_activities')->where('lead_id', 723)->get(),
  'activities' => DB::table('activities')->whereIn('id', $acts)->get(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$entregado = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 7)->where('code', 'pedidos-entregados')->value('id');
DB::transaction(function () use ($acts, $entregado) {
  DB::table('lead_activities')->where('lead_id', 723)->delete();
  DB::table('activities')->whereIn('id', $acts)->delete();
  DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', 723)->delete();
  DB::table('lead_products')->where('lead_id', 723)->delete();
  DB::table('leads')->where('id', 723)->delete();
  DB::table('leads')->where('id', 712)->update(['lead_pipeline_stage_id' => $entregado, 'closed_at' => '2026-08-25 09:00:00', 'updated_at' => now()]);
  DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', 712)->where('attribute_id', 28)->update(['integer_value' => $entregado]);
  $id = DB::table('activities')->insertGetId(['type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
    'comment' => '31/10/2026: pasa a Pedidos entregados por decisión del usuario (fecha estimada 25/08 por la mañana, cuando el cliente avisó que pasaba). Se eliminó el #723, duplicado de este pedido: el 24/08 el cliente escribió "hoy no podré pasar, mañana" y el agente abrió otro lead. Respaldo en docs/eliminacion_723_willy_respaldo.json.']);
  DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => 712]);
});
$r = DB::selectOne('select l.id, s.name e, round(l.lead_value) v, l.closed_at from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id where l.id = 712');
echo "#723 existe: ".(DB::table('leads')->where('id', 723)->exists() ? 'sí' : 'no')." | #712 {$r->e} Bs {$r->v} cerrado {$r->closed_at} | leads de Willy: ".DB::table('leads')->where('person_id', 642)->pluck('id')->implode(', ')."\n";
