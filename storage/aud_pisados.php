<?php

/*
 * Auditoría (solo lectura): leads que tuvieron más de un pedido en fechas
 * separadas (> 2 días), según el registro de actividades. Cada pedido de esos
 * debería tener su propio lead.
 */

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$ign = ['Primer contacto', 'Pedido en curso (WhatsApp)', ''];
$stage = DB::table('lead_pipeline_stages')->pluck('name', 'id');
$prod = DB::table('products')->pluck('name', 'id');

$ev = DB::select("select la.lead_id, a.created_at, a.additional, u.name u from kl_activities a
    join kl_lead_activities la on la.activity_id = a.id left join kl_users u on u.id = a.user_id
    where a.type = 'system' and (a.additional like '%\"attribute\": \"Descripción del pedido\"%' or a.additional like '%\"attribute\": \"Etapa\"%'
      or a.additional like '%\"attribute\": \"Valor del Pedido\"%')
    order by la.lead_id, a.created_at, a.id");

$por = [];
foreach ($ev as $e) {
    $ad = json_decode((string) $e->additional, true);
    $por[$e->lead_id][] = ['at' => $e->created_at, 'attr' => $ad['attribute'] ?? '', 'new' => $ad['new'] ?? [], 'old' => $ad['old'] ?? [], 'u' => $e->u];
}

$n = 0;
foreach ($por as $lead => $evs) {
    $peds = [];          // pedidos: [fecha, desc, valor, quién]
    $valor = null;
    foreach ($evs as $e) {
        if ($e['attr'] === 'Valor del Pedido') {
            $valor = $e['new']['value'] ?? null;
            if ($peds) $peds[count($peds) - 1]['v'] = $valor;
        }
        if ($e['attr'] === 'Descripción del pedido') {
            $d = trim((string) ($e['new']['value'] ?? ''));
            if (in_array($d, $ign, true)) continue;
            $last = $peds ? $peds[count($peds) - 1] : null;
            if ($last && Carbon::parse($e['at'])->diffInHours(Carbon::parse($last['at'])) < 48) {
                $peds[count($peds) - 1]['d'] = $d;           // mismo pedido, se corrigió
                $peds[count($peds) - 1]['v'] = $valor;
            } else {
                $peds[] = ['at' => $e['at'], 'd' => $d, 'v' => $valor, 'u' => $e['u']];
            }
        }
    }
    if (count($peds) < 2) continue;

    $l = DB::table('leads')->find($lead);
    if (! $l) continue;
    $p = DB::table('persons')->find($l->person_id);
    $entregas = collect($evs)->filter(fn ($e) => $e['attr'] === 'Etapa' && str_contains((string) ($e['new']['label'] ?? ''), 'entregado'))
        ->map(fn ($e) => substr($e['at'], 0, 10).' '.$e['u'])->implode(', ');
    $reab = collect($evs)->filter(fn ($e) => $e['attr'] === 'Etapa' && str_contains((string) ($e['old']['label'] ?? ''), 'entregado'))
        ->map(fn ($e) => substr($e['at'], 0, 16).' '.$e['u'])->implode(', ');
    $lin = DB::table('lead_products')->where('lead_id', $lead)->get()->map(fn ($x) => (int) $x->quantity.'x '.($prod[$x->product_id] ?? $x->product_id).' @'.round($x->price))->implode(' + ');
    $conv = DB::table('whatsapp_conversations')->where('lead_id', $lead)->pluck('id')->implode(',');
    $n++;
    echo "\n#{$lead} creado ".substr($l->created_at, 0, 10)." | contacto #{$l->person_id} ".($p->name ?? '?')." | hoy: ".$stage[$l->lead_pipeline_stage_id]." Bs ".round($l->lead_value)." [{$lin}] conv {$conv}\n";
    if ($entregas) echo "   entregado: {$entregas}\n";
    if ($reab) echo "   REABIERTO desde entregado: {$reab}\n";
    foreach ($peds as $x) echo '   · '.substr($x['at'], 0, 16)." [{$x['u']}] Bs ".($x['v'] ?? '?').' | '.mb_substr(str_replace("\n", ' / ', $x['d']), 0, 170)."\n";
}
echo "\nTotal leads con más de un pedido: {$n}\n";
