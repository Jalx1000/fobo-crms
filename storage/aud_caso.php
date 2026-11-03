<?php
// Ficha completa de un lead: datos, atributos, actividades, contacto, conversación.
use Illuminate\Support\Facades\DB;
$id = (int) getenv('LEAD');
$st = DB::table('lead_pipeline_stages')->get()->keyBy('id'); $pl = DB::table('lead_pipelines')->pluck('name','id'); $us = DB::table('users')->pluck('name','id'); $prod = DB::table('products')->pluck('name','id');
$l = DB::table('leads')->find($id); $p = DB::table('persons')->find($l->person_id);
$av = DB::table('attribute_values')->where('entity_type','leads')->where('entity_id',$id)->whereIn('attribute_id',[27,28,69])->pluck('integer_value','attribute_id');
echo "LEAD #{$id} creado {$l->created_at} | asesor ".($us[$l->user_id]??'-')." | Bs ".round($l->lead_value)." [".DB::table('lead_products')->where('lead_id',$id)->get()->map(fn($x)=>(int)$x->quantity.'x '.$prod[$x->product_id])->implode(' + ')."] | cerrado {$l->closed_at}\n";
echo "  columna:  pipeline {$pl[$l->lead_pipeline_id]} · etapa ".$st[$l->lead_pipeline_stage_id]->name." (id {$l->lead_pipeline_stage_id})\n";
echo "  atributo: pipeline ".($pl[$av[27] ?? 0] ?? '?')." · etapa ".($st[$av[28]]->name ?? '?')." de ".($pl[$st[$av[28]]->lead_pipeline_id ?? 0] ?? '?')." (id ".($av[28]??'?').") · ciudad ".($pl[$av[69] ?? 0] ?? '?')."\n";
$pc = DB::table('attribute_values')->where('entity_type','persons')->where('entity_id',$p->id)->where('attribute_id',70)->value('integer_value');
echo "CONTACTO #{$p->id} {$p->name} | ".preg_replace('/\D/','',json_decode($p->contact_numbers,true)[0]['value'] ?? '')." | ciudad ".($pl[$pc] ?? 'vacía')." | otros leads: ".DB::table('leads')->where('person_id',$p->id)->where('id','<>',$id)->get()->map(fn($x)=>"#{$x->id} ".substr($x->created_at,0,10)." {$pl[$x->lead_pipeline_id]} ".$st[$x->lead_pipeline_stage_id]->name)->implode(' | ')."\n";
echo "ACTIVIDADES\n";
foreach (DB::select("select a.created_at, a.type, a.comment, a.additional, a.user_id from kl_activities a join kl_lead_activities la on la.activity_id=a.id where la.lead_id=? order by a.created_at, a.id", [$id]) as $a) {
  $x = json_decode((string)$a->additional, true);
  if ($x && in_array($x['attribute'] ?? '', ['Canal','Tipo de cliente','Fecha de Entrega Esperada','first_stage_change_at'])) continue;
  $t = $x ? ($x['attribute'] ?? '').': '.json_encode($x['old']['label'] ?? null, JSON_UNESCAPED_UNICODE).' → '.json_encode($x['new']['label'] ?? null, JSON_UNESCAPED_UNICODE) : 'NOTA: '.$a->comment;
  if (trim($t) === 'NOTA:') $t = 'Creado';
  echo '  '.substr($a->created_at,0,16).' ['.($us[$a->user_id] ?? 'null')."] ".mb_substr(str_replace("\n",' / ',$t),0,200)."\n";
}
echo "CLOUD API\n";
foreach (DB::table('whatsapp_conversations')->where('person_id',$p->id)->get() as $c) {
  echo "  conv {$c->id} (lead {$c->lead_id}) ciudad derivación ".($pl[$c->handoff_city_id ?? 0] ?? '-')."\n";
  foreach (DB::table('whatsapp_messages')->where('conversation_id',$c->id)->orderBy('id')->get() as $m) echo '   '.substr($m->created_at,5,11).' '.($m->direction==='inbound'?'C':'A').($m->user_id?"(".($us[$m->user_id]??$m->user_id).")":'').': '.mb_substr(str_replace("\n",' / ',(string)$m->body),0,$m->direction==='inbound'?200:130)."\n";
}
echo "TEL ".preg_replace('/\D/','',json_decode($p->contact_numbers,true)[0]['value'] ?? '')."\n";
