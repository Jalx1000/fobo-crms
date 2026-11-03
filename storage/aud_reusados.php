<?php
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
// Leads cuyo primer pedido real se registró > 7 días después de crearse el lead (lead viejo reutilizado).
$ign = ['Primer contacto', 'Pedido en curso (WhatsApp)', ''];
$stage = DB::table('lead_pipeline_stages')->pluck('name', 'id');
$prod = DB::table('products')->pluck('name', 'id');
$rows = DB::select("select la.lead_id, a.created_at, a.additional from kl_activities a join kl_lead_activities la on la.activity_id=a.id
  where a.type='system' and a.additional like '%\"attribute\": \"Descripción del pedido\"%' order by la.lead_id, a.created_at, a.id");
$first = [];
foreach ($rows as $r) {
  $d = trim((string) (json_decode($r->additional, true)['new']['value'] ?? ''));
  if (in_array($d, $ign, true) || isset($first[$r->lead_id])) continue;
  $first[$r->lead_id] = [$r->created_at, $d];
}
foreach ($first as $id => [$at, $d]) {
  $l = DB::table('leads')->find($id); if (! $l) continue;
  $gap = Carbon::parse($l->created_at)->diffInDays(Carbon::parse($at));
  if ($gap <= 7) continue;
  $p = DB::table('persons')->find($l->person_id);
  $lin = DB::table('lead_products')->where('lead_id',$id)->get()->map(fn($x)=>(int)$x->quantity.'x '.($prod[$x->product_id]??$x->product_id))->implode(' + ');
  $ent = DB::selectOne("select group_concat(concat(date(a.created_at),' ',coalesce(u.name,'?')) separator ', ') e from kl_activities a join kl_lead_activities la on la.activity_id=a.id left join kl_users u on u.id=a.user_id where la.lead_id=? and a.additional like '%\"attribute\": \"Etapa\"%' and a.additional like '%\"new\": {\"label\": \"Pedidos entregados\"%' and a.created_at < ?", [$id, $at])->e;
  echo "#{$id} creado ".substr($l->created_at,0,10)." → pedido ".substr($at,0,10)." ({$gap} días) | #{$l->person_id} {$p->name} | hoy ".$stage[$l->lead_pipeline_stage_id]." Bs ".round($l->lead_value)." [{$lin}]".($ent ? " | entregado ANTES: {$ent}" : '')."\n   ".mb_substr(str_replace("\n",' / ',$d),0,160)."\n";
}
