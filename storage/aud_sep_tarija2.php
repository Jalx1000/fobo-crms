<?php
use Illuminate\Support\Facades\DB;
$ids = DB::table('leads as l')->join('attribute_values as av', fn($j)=>$j->on('av.entity_id','=','l.id')->where('av.entity_type','leads')->where('av.attribute_id',69))
  ->where('l.created_at','>=','2026-09-01')->where('l.created_at','<','2026-10-01')->where('l.lead_pipeline_id',1)->where('av.integer_value',10)->orderBy('l.id')->pluck('l.id');
$cities = ['tarija','santa cruz','scz','la paz','cochabamba','cbba','sucre','potos','oruro','beni','trinidad','cobija','pando','argentina','yacuiba','bermejo','villa'];
$stats = ['creador'=>[], 'ciudad_chat'=>[], 'cambios'=>[]];
foreach ($ids as $id) {
  $l = DB::table('leads')->find($id); $p = DB::table('persons')->find($l->person_id);
  $acts = DB::select("select a.created_at, a.additional, coalesce(u.name,'null') u, a.title from kl_activities a join kl_lead_activities la on la.activity_id=a.id left join kl_users u on u.id=a.user_id where la.lead_id=? order by a.created_at, a.id", [$id]);
  $creador = collect($acts)->first(fn($a)=>$a->title==='Creado' || str_contains((string)$a->additional,'"Pipeline"'))->u ?? '?';
  $cc = collect($acts)->filter(fn($a)=>str_contains((string)$a->additional,'"attribute": "Ciudad"'))->map(fn($a)=>substr($a->created_at,5,5).' '.$a->u.':'.(json_decode($a->additional,true)['new']['label'] ?? 'null'))->values();
  $conv = DB::table('whatsapp_conversations')->where('person_id',$l->person_id)->pluck('id');
  $in = DB::table('whatsapp_messages')->whereIn('conversation_id',$conv)->where('direction','inbound')->orderBy('id')->pluck('body')->implode(' / ');
  $dijo = collect($cities)->filter(fn($c)=>str_contains(mb_strtolower($in),$c))->implode(',');
  $nIn = DB::table('whatsapp_messages')->whereIn('conversation_id',$conv)->where('direction','inbound')->count();
  $nOut = DB::table('whatsapp_messages')->whereIn('conversation_id',$conv)->where('direction','outbound')->count();
  $first = mb_substr(str_replace("\n",' ',(string)DB::table('whatsapp_messages')->whereIn('conversation_id',$conv)->orderBy('id')->value('body')),0,70);
  $stats['creador'][$creador] = ($stats['creador'][$creador] ?? 0) + 1;
  $stats['ciudad_chat'][$dijo ?: '—'] = ($stats['ciudad_chat'][$dijo ?: '—'] ?? 0) + 1;
  $stats['cambios'][count($cc)] = ($stats['cambios'][count($cc)] ?? 0) + 1;
  echo "#{$id}|".substr($l->created_at,5,11)."|{$creador}|#{$p->id} ".mb_substr($p->name,0,25)."|Bs ".round($l->lead_value)."|msgs in {$nIn} out {$nOut}|dijo:".($dijo?:'—')."|1º: {$first}|ciudad: ".$cc->implode(' > ')."\n";
}
echo "\n".json_encode($stats, JSON_UNESCAPED_UNICODE)."\n";
