<?php
/*
 * Elimina el #648 (Fabiola Gonzales, contacto #520) — 31/10/2026, decisión del usuario.
 * Duplicado del #567: el 13/08 la clienta escribió "Puedo pasar el sábado" sobre
 * el pedido del 12/08 (1× Promo Varietales, entregado el 24/08) y el agente abrió
 * otro lead con los mismos datos. La conversación de Cloud API 1036 (pedido del
 * 15/09, Promo Tannat) pasa a su lead, el #1326.
 * Respaldo completo antes de borrar. Sin eventos.
 */
use Illuminate\Support\Facades\DB;
if (! DB::table('leads')->where('id', 648)->exists()) exit("Ya aplicado: no se hace nada.\n");
$l = DB::table('leads')->find(648);
$sig = DB::table('lead_products')->where('lead_id', 648)->get()->map(fn ($x) => $x->product_id.'x'.(int) $x->quantity)->implode(',');
$o = DB::table('leads')->find(567);
if ($l->person_id !== 520 || $o->person_id !== 520 || $sig !== '43x1' || $o->lead_pipeline_stage_id !== 33
    || DB::table('leads')->where('id', 1326)->value('person_id') !== 520) exit("No se aplica: no está como se auditó.\n");
$acts = DB::table('lead_activities')->where('lead_id', 648)->pluck('activity_id');
if (DB::table('lead_activities')->whereIn('activity_id', $acts)->where('lead_id', '<>', 648)->exists()
    || DB::table('person_activities')->whereIn('activity_id', $acts)->exists()) exit("No se aplica: hay actividades compartidas.\n");
file_put_contents('/code/docs/eliminacion_648_fabiola_respaldo.json', json_encode([
  'lead' => $l,
  'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', 648)->get(),
  'lead_products' => DB::table('lead_products')->where('lead_id', 648)->get(),
  'lead_activities' => DB::table('lead_activities')->where('lead_id', 648)->get(),
  'activities' => DB::table('activities')->whereIn('id', $acts)->get(),
  'conversations' => DB::table('whatsapp_conversations')->where('lead_id', 648)->get(['id', 'lead_id']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
DB::transaction(function () use ($acts) {
  DB::table('whatsapp_conversations')->where('lead_id', 648)->update(['lead_id' => 1326]);
  DB::table('lead_activities')->where('lead_id', 648)->delete();
  DB::table('activities')->whereIn('id', $acts)->delete();
  DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', 648)->delete();
  DB::table('lead_products')->where('lead_id', 648)->delete();
  DB::table('leads')->where('id', 648)->delete();
  $id = DB::table('activities')->insertGetId(['type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
    'comment' => '31/10/2026: se eliminó el #648, duplicado de este pedido. El 13/08 la clienta escribió "Puedo pasar el sábado" y el agente abrió otro lead con los mismos datos (1× Promo Varietales). Respaldo en docs/eliminacion_648_fabiola_respaldo.json.']);
  DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => 567]);
});
echo '#648 existe: '.(DB::table('leads')->where('id', 648)->exists() ? 'sí' : 'no').' | actividades huérfanas: '.DB::table('activities')->whereIn('id', $acts)->count()
  .' | conv 1036 → #'.DB::table('whatsapp_conversations')->where('id', 1036)->value('lead_id')
  .' | leads de Fabiola: '.DB::table('leads')->where('person_id', 520)->pluck('id')->implode(', ')."\n";
