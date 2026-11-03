<?php
use Illuminate\Support\Facades\DB;
foreach (array_map('intval', explode(',', getenv('CONVS'))) as $cid) {
  $c = DB::table('whatsapp_conversations')->find($cid); echo "### Cloud API conv {$cid} creada {$c->created_at} person {$c->person_id} lead {$c->lead_id}\n";
  foreach (DB::table('whatsapp_messages')->where('conversation_id',$cid)->orderBy('id')->get() as $m)
    echo substr($m->created_at,5,11).' '.($m->direction==='inbound'?'C':'A').($m->user_id?"(u{$m->user_id})":'').': '.mb_substr(str_replace("\n",' / ',(string)$m->body),0,220)."\n";
}
