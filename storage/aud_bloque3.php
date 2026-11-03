<?php
use Illuminate\Support\Facades\DB;
$ids = [1219,1229,1274,1328,1333,1334,1335,1336,1338,1339,1340,1435,1454,1569,1576,1579,1606,1611,1615,1634,1647,1664,1676,1701,1705,1716,1726,1768,2322,3670,3736,3762,3771];
$st = DB::table('lead_pipeline_stages')->get()->keyBy('id'); $pl = DB::table('lead_pipelines')->pluck('name','id'); $us = DB::table('users')->pluck('name','id');
$ign = ['Primer contacto','Pedido en curso (WhatsApp)',''];
foreach ($ids as $id) {
  $l = DB::table('leads')->find($id); $p = DB::table('persons')->find($l->person_id);
  $k = substr(preg_replace('/\D/','',json_decode($p->contact_numbers,true)[0]['value'] ?? ''),-8);
  $otrosP = $k ? DB::table('persons')->where('id','<>',$p->id)->where('contact_numbers','like',"%{$k}%")->count() : 0;
  $convs = DB::table('whatsapp_conversations')->where(fn($q)=>$q->where('person_id',$p->id)->orWhere('wa_phone','like',"%{$k}"))->pluck('id');
  $msgs = DB::table('whatsapp_messages')->whereIn('conversation_id',$convs)->count();
  $reg = DB::table('whatsapp_messages')->whereIn('conversation_id',$convs)->where('direction','outbound')->where('body','like','%pedido%registrado%')->get(['created_at'])->map(fn($m)=>substr($m->created_at,5,5))->implode(',');
  $descs = collect(DB::select("select a.created_at, a.additional from kl_activities a join kl_lead_activities la on la.activity_id=a.id where la.lead_id=? and a.additional like '%\"attribute\": \"Descripción del pedido\"%' order by a.created_at", [$id]))
    ->map(fn($a)=>[substr($a->created_at,0,10), trim((string)(json_decode($a->additional,true)['new']['value'] ?? ''))])->filter(fn($d)=>!in_array($d[1],$ign,true));
  $dias = $descs->pluck(0)->unique()->values();
  $pis = $dias->count() > 1 ? 'SÍ '.$dias->map(fn($d)=>substr($d,5))->implode('/') : 'no';
  $lin = DB::table('lead_products')->where('lead_id',$id)->count();
  $otrosL = DB::table('leads')->where('person_id',$p->id)->where('id','<>',$id)->pluck('id')->implode(',');
  echo "#{$id}|".mb_substr($p->name,0,22)."|{$pl[$l->lead_pipeline_id]} · ".$st[$l->lead_pipeline_stage_id]->name."|".($us[$l->user_id]??'-')."|Bs ".round($l->lead_value)." líneas {$lin}|msgs {$msgs}|registrado: ".($reg?:'—')."|pisado: {$pis}|otros leads: ".($otrosL?:'—')."|otros contactos: {$otrosP}|TEL {$k}\n";
}
