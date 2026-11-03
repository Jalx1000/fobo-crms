<?php
// Todas las actividades (sin filtrar) de todos los leads de un contacto y del contacto mismo.
use Illuminate\Support\Facades\DB;
$pid = (int) getenv('PERSON');
$st = DB::table('lead_pipeline_stages')->pluck('name','id'); $pl = DB::table('lead_pipelines')->pluck('name','id'); $us = DB::table('users')->pluck('name','id'); $prod = DB::table('products')->pluck('name','id');
$p = DB::table('persons')->find($pid);
echo "CONTACTO #{$pid} {$p->name} | ".$p->contact_numbers."\n";
$fmt = function ($a) use ($us) {
  $x = json_decode((string) $a->additional, true);
  $t = $x && isset($x['attribute']) ? "{$x['attribute']}: ".json_encode($x['old']['label'] ?? null, JSON_UNESCAPED_UNICODE).' → '.json_encode($x['new']['label'] ?? null, JSON_UNESCAPED_UNICODE) : trim(($a->title ?? '').' '.($a->comment ?? ''));
  return '  '.substr($a->created_at,0,16)." {$a->type} [".($us[$a->user_id] ?? 'null')."] ".mb_substr(str_replace("\n",' / ',$t),0,260);
};
echo "ACTIVIDADES DEL CONTACTO\n";
foreach (DB::select("select a.* from kl_activities a join kl_person_activities pa on pa.activity_id=a.id where pa.person_id=? order by a.created_at, a.id", [$pid]) as $a) echo $fmt($a)."\n";
foreach (DB::table('leads')->where('person_id',$pid)->orderBy('id')->get() as $l) {
  echo "\nLEAD #{$l->id} creado {$l->created_at} | {$pl[$l->lead_pipeline_id]} · {$st[$l->lead_pipeline_stage_id]} | ".($us[$l->user_id]??'-')." | Bs ".round($l->lead_value)." [".DB::table('lead_products')->where('lead_id',$l->id)->get()->map(fn($x)=>(int)$x->quantity.'x '.$prod[$x->product_id].' @'.round($x->price))->implode(' + ')."] | cerrado {$l->closed_at}\n";
  foreach (DB::select("select a.* from kl_activities a join kl_lead_activities la on la.activity_id=a.id where la.lead_id=? order by a.created_at, a.id", [$l->id]) as $a) {
    $x = json_decode((string) $a->additional, true);
    if ($x && in_array($x['attribute'] ?? '', ['Canal','Tipo de cliente','Fecha de Entrega Esperada','first_stage_change_at','Ubicacion'])) continue;
    echo $fmt($a)."\n";
  }
}
echo "\nCLOUD API\n";
foreach (DB::table('whatsapp_conversations')->where('person_id',$pid)->get() as $c) {
  echo "  conv {$c->id} (lead {$c->lead_id})\n";
  foreach (DB::table('whatsapp_messages')->where('conversation_id',$c->id)->orderBy('id')->get() as $m) echo '   '.substr($m->created_at,5,11).' '.($m->direction==='inbound'?'C':'A').($m->user_id?"(".($us[$m->user_id]??$m->user_id).")":'').': '.mb_substr(str_replace("\n",' / ',(string)$m->body),0,$m->direction==='inbound'?200:140)."\n";
}
