<?php
/*
 * Septiembre 2026 — eliminación de leads por decisión del usuario (31/10/2026), con respaldo completo.
 *   #1338 Omar (contacto #1544): primer contacto vacío (Bs 0, sin líneas); su pedido del 18/09 está en el #1587.
 *   #1676 Iris Castillo (contacto #2082): duplicado del #1752 (mismo pedido 2× Promo Primavera, Bs 150).
 *         La conversación 1380 pasa al #1752. Nota en #1587 y #1752.
 * Uso: LEADS="1338:1587,1676:1752" (lead a borrar : lead que queda).
 */
use Illuminate\Support\Facades\DB;
$pares = collect(explode(',', getenv('LEADS')))->map(fn ($x) => array_map('intval', explode(':', $x)));
$notas = [
  1338 => 'se eliminó el #1338, un primer contacto vacío (Bs 0, sin productos) del 15/09; el pedido real del cliente es este.',
  1676 => 'se eliminó el #1676, duplicado de este pedido: el agente lo abrió con el primer mensaje (21/09) y creó este al confirmar (22/09). La conversación 1380 pasó a este lead.',
];
foreach ($pares as [$del, $keep]) {
  $l = DB::table('leads')->find($del); $k = DB::table('leads')->find($keep);
  if (! $l) { echo "#{$del} ya no existe.\n"; continue; }
  if (! $k || $l->person_id !== $k->person_id) exit("No se aplica: #{$del} y #{$keep} no son del mismo contacto.\n");
  $acts = DB::table('lead_activities')->where('lead_id', $del)->pluck('activity_id');
  if (DB::table('lead_activities')->whereIn('activity_id', $acts)->where('lead_id', '<>', $del)->exists() || DB::table('person_activities')->whereIn('activity_id', $acts)->exists()) exit("No se aplica: #{$del} tiene actividades compartidas.\n");
  if (DB::table('lead_quotes')->where('lead_id', $del)->exists() || DB::table('emails')->where('lead_id', $del)->exists()) exit("No se aplica: #{$del} tiene cotizaciones o correos.\n");
  file_put_contents("/code/docs/eliminacion_{$del}_respaldo.json", json_encode([
    'lead' => $l, 'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $del)->get(),
    'lead_products' => DB::table('lead_products')->where('lead_id', $del)->get(), 'lead_tags' => DB::table('lead_tags')->where('lead_id', $del)->get(),
    'lead_activities' => DB::table('lead_activities')->where('lead_id', $del)->get(), 'activities' => DB::table('activities')->whereIn('id', $acts)->get(),
    'conversations' => DB::table('whatsapp_conversations')->where('lead_id', $del)->get(['id', 'lead_id']),
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  DB::transaction(function () use ($del, $keep, $acts, $notas) {
    DB::table('whatsapp_conversations')->where('lead_id', $del)->update(['lead_id' => $keep]);
    DB::table('lead_activities')->where('lead_id', $del)->delete();
    DB::table('activities')->whereIn('id', $acts)->delete();
    DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $del)->delete();
    DB::table('lead_products')->where('lead_id', $del)->delete();
    DB::table('lead_tags')->where('lead_id', $del)->delete();
    DB::table('leads')->where('id', $del)->delete();
    $id = DB::table('activities')->insertGetId(['type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
      'comment' => '31/10/2026: '.$notas[$del]." Respaldo en docs/eliminacion_{$del}_respaldo.json."]);
    DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => $keep]);
  });
  echo "#{$del} eliminado (".count($acts)." actividades) | queda #{$keep} con conv ".(DB::table('whatsapp_conversations')->where('lead_id', $keep)->pluck('id')->implode(',') ?: '—')." | leads del contacto: ".DB::table('leads')->where('person_id', $k->person_id)->pluck('id')->implode(', ')."\n";
}
