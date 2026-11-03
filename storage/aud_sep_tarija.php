<?php
use Illuminate\Support\Facades\DB;
$ids = DB::table('leads as l')->join('attribute_values as av', fn($j)=>$j->on('av.entity_id','=','l.id')->where('av.entity_type','leads')->where('av.attribute_id',69))
  ->where('l.created_at','>=','2026-09-01')->where('l.created_at','<','2026-10-01')->where('l.lead_pipeline_id',1)->where('av.integer_value',10)->pluck('l.id');
$st = DB::table('lead_pipeline_stages')->pluck('name','id'); $pl = DB::table('lead_pipelines')->pluck('name','id');
echo "etapas: ".json_encode(DB::table('leads')->whereIn('id',$ids)->get()->countBy(fn($l)=>$st[$l->lead_pipeline_stage_id]), JSON_UNESCAPED_UNICODE)."\n";
echo "valor total: ".DB::table('leads')->whereIn('id',$ids)->sum('lead_value')."\n";
echo "ciudad del contacto: ".json_encode(DB::table('leads as l')->leftJoin('attribute_values as av', fn($j)=>$j->on('av.entity_id','=','l.person_id')->where('av.entity_type','persons')->where('av.attribute_id',70))->whereIn('l.id',$ids)->get(['av.integer_value'])->countBy(fn($x)=>$pl[$x->integer_value] ?? 'vacío'), JSON_UNESCAPED_UNICODE)."\n";
echo "con conversación: ".DB::table('whatsapp_conversations')->whereIn('lead_id',$ids)->count()." | usuarios: ".json_encode(DB::table('leads as l')->leftJoin('users as u','u.id','=','l.user_id')->whereIn('l.id',$ids)->get(['u.name'])->countBy('name'), JSON_UNESCAPED_UNICODE)."\n";
$ej = DB::table('leads')->whereIn('id',$ids)->orderBy('id')->limit(3)->pluck('id');
foreach ($ej as $id) { echo "--- #{$id}\n"; foreach (DB::select("select a.created_at, a.additional, u.name from kl_activities a join kl_lead_activities la on la.activity_id=a.id left join kl_users u on u.id=a.user_id where la.lead_id=? and (a.additional like '%Pipeline%' or a.additional like '%\"Ciudad\"%' or a.additional like '%Etapa%') order by a.created_at, a.id", [$id]) as $a) { $x=json_decode($a->additional,true); echo "  ".substr($a->created_at,5,11)." [{$a->name}] {$x['attribute']}: ".json_encode($x['old']['label'] ?? null, JSON_UNESCAPED_UNICODE)." → ".json_encode($x['new']['label'] ?? null, JSON_UNESCAPED_UNICODE)."\n"; } }
