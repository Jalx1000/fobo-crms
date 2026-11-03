<?php
use Illuminate\Support\Facades\DB;
$ids = array_map('intval', explode(',', getenv('LEADS')));
$prod = DB::table('products')->pluck('name','id'); $st = DB::table('lead_pipeline_stages')->pluck('name','id');
foreach ($ids as $id) {
  $l = DB::table('leads')->find($id); $p = DB::table('persons')->find($l->person_id);
  $lin = DB::table('lead_products')->where('lead_id',$id)->get()->map(fn($x)=>(int)$x->quantity.'x '.$prod[$x->product_id].' @'.round($x->price))->implode(' + ');
  echo "\n===== #{$id} {$l->created_at} | #{$p->id} {$p->name} ".preg_replace('/\D/','',json_decode($p->contact_numbers,true)[0]['value'] ?? '')." | {$st[$l->lead_pipeline_stage_id]} | Bs ".round($l->lead_value)." [{$lin}] | cerrado {$l->closed_at} | conv ".DB::table('whatsapp_conversations')->where('lead_id',$id)->pluck('id')->implode(',')."\n";
  foreach (DB::table('activities as a')->join('lead_activities as la','la.activity_id','=','a.id')->leftJoin('users as u','u.id','=','a.user_id')->where('la.lead_id',$id)->orderBy('a.created_at')->orderBy('a.id')->get(['a.created_at','a.comment','a.additional','u.name']) as $a) {
    $ad = json_decode((string)$a->additional, true);
    if ($ad && in_array($ad['attribute'] ?? '', ['Canal','Tipo de cliente','Fecha de Entrega Esperada','first_stage_change_at','Edad del Cliente'])) continue;
    $t = $ad ? ($ad['attribute'] ?? '').': '.json_encode($ad['old']['label'] ?? null, JSON_UNESCAPED_UNICODE).' → '.json_encode($ad['new']['label'] ?? null, JSON_UNESCAPED_UNICODE) : 'NOTA: '.$a->comment;
    if (trim($t) === 'NOTA:') continue;
    echo '  '.substr($a->created_at,0,16)." [{$a->name}] ".mb_substr(str_replace("\n",' / ',$t),0,220)."\n";
  }
}
