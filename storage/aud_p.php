<?php
use Illuminate\Support\Facades\DB;
$st = DB::table('lead_pipeline_stages')->pluck('name','id'); $prod = DB::table('products')->pluck('name','id'); $us = DB::table('users')->pluck('name','id'); $pl = DB::table('lead_pipelines')->pluck('name','id');
$ign = ['Primer contacto','Pedido en curso (WhatsApp)',''];
foreach ([952,1064,1130,1149,1152,1172,1352,1545,1772,1773] as $id) {
  $l = DB::table('leads')->find($id); $p = DB::table('persons')->find($l->person_id);
  $k = substr(preg_replace('/\D/','',json_decode($p->contact_numbers,true)[0]['value'] ?? ''),-8);
  $convs = DB::table('whatsapp_conversations')->where(fn($q)=>$q->where('person_id',$p->id)->orWhere('wa_phone','like',"%{$k}"))->pluck('id');
  $reg = DB::table('whatsapp_messages')->whereIn('conversation_id',$convs)->where('direction','outbound')->where('body','like','%pedido%registrado%')->get(['created_at','body'])->map(fn($m)=>substr($m->created_at,5,11).' «'.mb_substr(preg_replace('/\s+/',' ',preg_replace('/^.*recoger:|💰.*$/su','',$m->body)),0,90).'»')->implode(' ; ');
  $descs = collect(DB::select("select a.created_at, a.additional from kl_activities a join kl_lead_activities la on la.activity_id=a.id where la.lead_id=? and a.additional like '%\"attribute\": \"Descripción del pedido\"%' order by a.created_at", [$id]))->map(fn($a)=>[substr($a->created_at,0,10), trim((string)(json_decode($a->additional,true)['new']['value'] ?? ''))])->filter(fn($d)=>!in_array($d[1],$ign,true));
  $edits = collect(DB::select("select a.created_at, a.additional, a.user_id from kl_activities a join kl_lead_activities la on la.activity_id=a.id where la.lead_id=? and (a.additional like '%Valor del Pedido%') order by a.created_at", [$id]))->map(fn($a)=>substr($a->created_at,5,11).' '.($us[$a->user_id] ?? 'Agente').' '.(json_decode($a->additional,true)['old']['value'] ?? '?').'→'.(json_decode($a->additional,true)['new']['value'] ?? '?'))->implode(' ; ');
  $lines = DB::table('lead_products')->where('lead_id',$id)->get()->map(fn($x)=>(int)$x->quantity.'x '.$prod[$x->product_id].' @'.round($x->price).' amount '.round($x->amount).' ('.substr($x->created_at,5,11).')')->implode(' + ');
  echo "#{$id} ".substr($l->created_at,5,11)." | {$p->name} | {$pl[$l->lead_pipeline_id]} · {$st[$l->lead_pipeline_stage_id]} | ".($us[$l->user_id]??'-')." | valor Bs ".round($l->lead_value)."\n   líneas: {$lines}\n   valor editado: ".($edits?:'—')."\n   pisado: ".($descs->pluck(0)->unique()->count()>1?'SÍ '.$descs->pluck(0)->unique()->implode('/'):'no')." | otros leads: ".(DB::table('leads')->where('person_id',$p->id)->where('id','<>',$id)->pluck('id')->implode(',')?:'—')."\n   Cloud API registrado: ".($reg?:'—')."\n   TEL {$k}\n";
}
