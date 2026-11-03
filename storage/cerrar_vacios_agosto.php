<?php
/*
 * Agosto 2026 — leads sin pedido (Bs 0, sin productos, sin conversación), 31/10/2026.
 * Ninguno confirmó pedido en el Historial IA. Etapa elegida por el usuario, uno por uno.
 * Directo a la base, en transacción, sin eventos.
 */
use Illuminate\Support\Facades\DB;
$plan = [ // lead => [código de etapa, motivo]
  585 => ['otros-servicios', 'consultó por unirse al Club del Vino; no hizo pedido'],
  664 => ['pedidos-cancelados', 'pidió Promo Varietales y no siguió; no hubo pedido'],
  686 => ['pedidos-cancelados', 'consultó y no siguió; no hubo pedido'],
  689 => ['pedidos-cancelados', 'pidió Promo Varietales y no siguió; no hubo pedido'],
  711 => ['cliente-contactado', 'dio nombre y edad; no confirmó pedido'],
  556 => ['otros-servicios', 'consulta corporativa: 70 Cautivo con etiqueta personalizada'],
  708 => ['otros-servicios', 'preguntó por una agencia en Cochabamba; no hizo pedido'],
];
$errores = [];
foreach ($plan as $id => [$code]) {
  $l = DB::table('leads')->find($id);
  if (! $l) { $errores[] = "#{$id} no existe"; continue; }
  if ((float) $l->lead_value > 0 || DB::table('lead_products')->where('lead_id', $id)->exists() || DB::table('whatsapp_conversations')->where('lead_id', $id)->exists()) $errores[] = "#{$id} no está vacío";
  if (! DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $l->lead_pipeline_id)->where('code', $code)->exists()) $errores[] = "#{$id}: pipeline {$l->lead_pipeline_id} sin {$code}";
}
if ($errores) exit("No se aplica nada:\n  ".implode("\n  ", $errores)."\n");
if (DB::table('leads')->where('id', 585)->whereIn('lead_pipeline_stage_id', DB::table('lead_pipeline_stages')->where('code', 'otros-servicios')->pluck('id'))->exists()) exit("Ya aplicado: no se hace nada.\n");
file_put_contents('/code/docs/cierre_vacios_agosto_respaldo.json', json_encode([
  'leads' => DB::table('leads')->whereIn('id', array_keys($plan))->get(),
  'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->whereIn('entity_id', array_keys($plan))->where('attribute_id', 28)->get(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
DB::transaction(function () use ($plan) {
  foreach ($plan as $id => [$code, $motivo]) {
    $l = DB::table('leads')->find($id);
    $s = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $l->lead_pipeline_id)->where('code', $code)->first();
    $abierta = $code === 'cliente-contactado';
    DB::table('leads')->where('id', $id)->update(['lead_pipeline_stage_id' => $s->id, 'closed_at' => $abierta ? null : ($l->closed_at ?? now()), 'updated_at' => now()]);
    DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $id)->where('attribute_id', 28)->update(['integer_value' => $s->id]);
    $aid = DB::table('activities')->insertGetId(['type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
      'comment' => "31/10/2026 (auditoría de agosto): pasa a \"{$s->name}\". En el Historial IA {$motivo}. Bs 0, sin productos ni conversación."]);
    DB::table('lead_activities')->insert(['activity_id' => $aid, 'lead_id' => $id]);
  }
});
foreach (DB::select('select l.id, s.name e, l.closed_at from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id where l.id in ('.implode(',', array_keys($plan)).') order by l.id') as $r) echo "#{$r->id} {$r->e} | cerrado {$r->closed_at}\n";
