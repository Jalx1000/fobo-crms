<?php
use Illuminate\Support\Facades\DB;
$attrs = DB::table('attributes')->where('entity_type','persons')->pluck('code','id');
foreach (array_map('intval', explode(',', getenv('PERSONS'))) as $pid) {
  $p = DB::table('persons')->find($pid);
  if (! $p) { echo "#{$pid} NO EXISTE\n"; continue; }
  $tel = preg_replace('/\D/','', json_decode((string)$p->contact_numbers,true)[0]['value'] ?? '');
  echo "\n===== Contacto #{$pid}\n";
  foreach ((array)$p as $k=>$v) if (!in_array($k,['id'])) echo "  {$k}: ".mb_substr((string)$v,0,160)."\n";
  echo "  atributos: ".DB::table('attribute_values')->where('entity_type','persons')->where('entity_id',$pid)->get()->map(fn($a)=>($attrs[$a->attribute_id]??$a->attribute_id).'='.mb_substr((string)($a->text_value ?? $a->integer_value ?? $a->json_value ?? $a->float_value ?? $a->boolean_value ?? $a->date_value ?? ''),0,40))->implode(' | ')."\n";
  echo "  leads (person_id): ".DB::table('leads')->where('person_id',$pid)->pluck('id')->implode(',')."\n";
  echo "  actividades: ".DB::table('person_activities as pa')->join('activities as a','a.id','=','pa.activity_id')->where('pa.person_id',$pid)->get(['a.created_at','a.type','a.title','a.comment'])->map(fn($a)=>substr($a->created_at,0,16)." {$a->type} ".mb_substr(trim($a->title.' '.$a->comment),0,60))->implode(' ; ')."\n";
  echo "  etiquetas: ".DB::table('person_tags')->where('person_id',$pid)->count()." | emails CRM: ".DB::table('emails')->where('person_id',$pid)->count()." | cotizaciones: ".DB::table('quotes')->where('person_id',$pid)->count()."\n";
  echo "  conversaciones (person_id): ".DB::table('whatsapp_conversations')->where('person_id',$pid)->pluck('id')->implode(',')."\n";
  if ($tel) {
    $k = substr($tel,-8);
    echo "  conversaciones por número ({$k}): ".DB::table('whatsapp_conversations')->where('wa_phone','like',"%{$k}")->get(['id','person_id','wa_name','created_at'])->map(fn($c)=>"#{$c->id} p{$c->person_id} {$c->wa_name} {$c->created_at}")->implode(' ; ')."\n";
    echo "  otros contactos con el número: ".DB::table('persons')->where('id','<>',$pid)->where('contact_numbers','like',"%{$k}%")->get(['id','name'])->map(fn($x)=>"#{$x->id} {$x->name}")->implode(' ; ')."\n";
  }
  echo "  leads que lo mencionan en título: ".DB::table('leads')->where('title','like','%'.mb_substr($p->name,0,20).'%')->pluck('id')->implode(',')."\n";
}
