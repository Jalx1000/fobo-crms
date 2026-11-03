<?php

/*
 * Auditoría (solo lectura): cada pedido confirmado (Historial IA n8n + Cloud API)
 * debe tener su propio lead del mismo contacto, creado cerca de esa fecha y con
 * los mismos productos o el mismo total.
 */

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$prod = DB::table('products')->get(['id', 'name', 'price'])->keyBy('id');
$prodByName = $prod->mapWithKeys(fn ($p) => [mb_strtolower(trim($p->name)) => $p->id]);
$d8 = fn ($s) => substr(preg_replace('/\D/', '', (string) $s), -8);

// contactos por últimos 8 dígitos
$porTel = [];
foreach (DB::table('persons')->get(['id', 'name', 'contact_numbers']) as $p) {
    foreach ((array) json_decode((string) $p->contact_numbers, true) as $n) {
        $k = $d8($n['value'] ?? '');
        if (strlen($k) === 8) {
            $porTel[$k][$p->id] = $p->name;
        }
    }
}

$pedidos = [];

/* ---------- n8n ---------- */
foreach (file('/code/storage/n8n_confirmados.jsonl') as $row) {
    $r = json_decode($row, true);
    if (! $r || ! $r['hoy']) {
        continue;
    }
    $tel = explode('@', $r['s'])[0];
    $at = Carbon::createFromFormat('d F Y H:i', trim(preg_replace('/\s*(AM|PM)$/', '', $r['hoy'])));
    $pids = (array) ($r['pid'] ?? []);
    $qty = (array) ($r['qty'] ?? []);
    $names = (array) ($r['name'] ?? []);
    $lin = [];
    foreach ($names as $i => $nm) {
        $id = $pids[$i] ?? ($prodByName[mb_strtolower(trim((string) $nm))] ?? null);
        $lin[] = [$id, (int) ($qty[$i] ?? 1), (string) $nm];
    }
    $total = null;
    if (preg_match('/total[^0-9\n]*Bs\.?\s*([\d.,]+)/iu', ($r['desc'] ?? '')."\n".($r['msg'] ?? ''), $m)) {
        $total = (float) str_replace(',', '', $m[1]);
    }
    $pedidos[] = ['src' => 'IA', 'ref' => $r['id'], 'tel' => $tel, 'at' => $at, 'lin' => $lin, 'total' => $total,
        'persons' => $porTel[$d8($tel)] ?? [], 'txt' => $r['desc'] ?? ''];
}

/* ---------- Cloud API ---------- */
foreach (DB::table('whatsapp_messages as m')->join('whatsapp_conversations as c', 'c.id', '=', 'm.conversation_id')
    ->where('m.direction', 'outbound')->where('m.body', 'like', '%pedido ha sido registrado%')
    ->orderBy('m.id')->get(['m.id', 'm.body', 'm.created_at', 'c.person_id', 'c.id as conv']) as $m) {
    $lin = [];
    if (preg_match_all('/^\s*(\d+)\s*x?\s+(.+?)\s+—\s+Bs\s*([\d.,]+)/mu', $m->body, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) {
            $nm = trim(preg_replace('/\s+CON CAJA$/i', '', $x[2]));
            $lin[] = [$prodByName[mb_strtolower($nm)] ?? null, (int) $x[1], $x[2]];
        }
    }
    $total = preg_match('/Total a cancelar:\s*Bs\s*([\d.,]+)/u', $m->body, $t) ? (float) str_replace(',', '', $t[1]) : null;
    $p = DB::table('persons')->find($m->person_id);
    $pedidos[] = ['src' => 'API', 'ref' => $m->id, 'conv' => $m->conv, 'tel' => '', 'at' => Carbon::parse($m->created_at), 'lin' => $lin,
        'total' => $total, 'persons' => $p ? [$p->id => $p->name] : [], 'txt' => ''];
}

/* ---------- agrupar confirmaciones repetidas del mismo pedido (mismo contacto, < 24 h) ---------- */
usort($pedidos, fn ($a, $b) => $a['at'] <=> $b['at']);
$grupos = [];
foreach ($pedidos as $p) {
    $key = implode(',', array_keys($p['persons'])) ?: 'tel'.$p['tel'];
    $g = &$grupos[$key];
    $last = $g ? count($g) - 1 : -1;
    if ($last >= 0 && $p['at']->diffInHours($g[$last]['at']) < 24) {
        $p['primero'] = $g[$last]['primero'] ?? $g[$last]['at'];
        $p['veces'] = ($g[$last]['veces'] ?? 1) + 1;
        $g[$last] = $p;          // vale la última confirmación
    } else {
        $g[] = $p;
    }
    unset($g);
}

/* ---------- cruzar con leads ---------- */
$sig = fn (array $lin) => collect($lin)->map(fn ($l) => ($l[0] ?? '?').'x'.$l[1])->sort()->implode(',');
$res = [];
foreach ($grupos as $key => $lista) {
    $pids = array_keys($lista[0]['persons']);
    $leads = $pids ? DB::table('leads')->whereIn('person_id', $pids)->orderBy('created_at')->get() : collect();
    $info = [];
    foreach ($leads as $l) {
        $lp = DB::table('lead_products')->where('lead_id', $l->id)->get();
        $info[$l->id] = ['l' => $l, 'sig' => $lp->map(fn ($x) => $x->product_id.'x'.(int) $x->quantity)->sort()->implode(','),
            'li' => (float) $lp->sum(fn ($x) => $x->quantity * $x->price),
            'txt' => $lp->map(fn ($x) => (int) $x->quantity.'x '.($prod[$x->product_id]->name ?? '#'.$x->product_id))->implode(' + ')];
    }
    $usados = [];
    foreach ($lista as $p) {
        $desde = $p['at']->copy()->subDays(3);
        $hasta = $p['at']->copy()->addDays(5);
        $mejor = null; $estado = 'SIN LEAD'; $dist = PHP_INT_MAX;
        $esperado = $p['total'];
        if ($esperado === null && $p['lin'] && collect($p['lin'])->every(fn ($l) => $l[0] && isset($prod[$l[0]]))) {
            $esperado = (float) collect($p['lin'])->sum(fn ($l) => $l[1] * $prod[$l[0]]->price);
        }
        foreach ($info as $id => $x) {
            if (isset($usados[$id])) continue;
            $c = Carbon::parse($x['l']->created_at);
            if ($c->lt($desde) || $c->gt($hasta)) continue;
            $okSig = $x['sig'] !== '' && $x['sig'] === $sig($p['lin']);
            $okVal = $esperado !== null && (abs((float) $x['l']->lead_value - $esperado) < 1 || abs($x['li'] - $esperado) < 1);
            $d = abs($c->diffInMinutes($p['at'])) + (($okSig || $okVal) ? 0 : 10000000);
            if ($d < $dist) { $dist = $d; $mejor = $id; $estado = ($okSig || $okVal) ? 'OK' : 'DISTINTO'; }
        }
        if ($mejor) $usados[$mejor] = true;
        $res[] = $p + ['estado' => $estado, 'lead' => $mejor ? $info[$mejor] : null, 'pids' => $pids];
    }
}

/* ---------- salida ---------- */
$stage = DB::table('lead_pipeline_stages')->pluck('name', 'id');
$resumen = [];
foreach ($res as $r) {
    $mes = $r['at']->format('Y-m');
    $resumen[$mes][$r['estado']] = ($resumen[$mes][$r['estado']] ?? 0) + 1;
}
ksort($resumen);
foreach ($resumen as $mes => $e) echo "{$mes}: ".json_encode($e)."\n";

$desde = $argv[1] ?? '2026-05-01'; // (tinker no pasa argv; se filtra abajo)
echo "\n";
$ver = [1499];
foreach ($res as &$r) {
    if ($r['estado'] !== 'SIN LEAD' || ! $r['pids']) continue;
    // ¿había un lead del contacto todavía sin entregar cuando volvió a confirmar? → mismo pedido repetido
    $pend = DB::table('leads')->whereIn('person_id', $r['pids'])->where('created_at', '<', $r['at'])
        ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $r['at']))->orderByDesc('created_at')->first();
    if ($pend) { $r['estado'] = 'REPETIDO'; $r['pend'] = $pend->id; }
    else {
        $prev = DB::table('leads')->whereIn('person_id', $r['pids'])->where('created_at', '<', $r['at'])->orderByDesc('created_at')->first();
        $r['prev'] = $prev ? "#{$prev->id} cerrado {$prev->closed_at}" : null;
    }
}
unset($r);
$resumen = [];
foreach ($res as $r) { $mes = $r['at']->format('Y-m'); $resumen[$mes][$r['estado']] = ($resumen[$mes][$r['estado']] ?? 0) + 1; }
ksort($resumen);
echo "--- con repetidos ---\n"; foreach ($resumen as $mes => $e) echo "{$mes}: ".json_encode($e)."\n";
foreach ($res as $r) {
    if ($r['estado'] === 'OK' && ! array_intersect($r['pids'], $ver)) continue;
    $pl = collect($r['lin'])->map(fn ($l) => "{$l[1]}x {$l[2]}")->implode(' + ');
    $per = $r['pids'] ? implode(',', array_map(fn ($id, $n) => "#{$id} {$n}", array_keys($r['persons']), $r['persons'])) : "SIN CONTACTO tel {$r['tel']}";
    $ld = $r['lead'] ? '#'.$r['lead']['l']->id.' '.substr($r['lead']['l']->created_at, 0, 10).' '.$stage[$r['lead']['l']->lead_pipeline_stage_id].' Bs '.round($r['lead']['l']->lead_value).' ['.$r['lead']['txt'].']' : '—';
    $todos = $r['pids'] ? DB::table('leads')->whereIn('person_id', $r['pids'])->orderBy('id')->get()
        ->map(fn ($l) => '#'.$l->id.' '.substr($l->created_at, 0, 10).' '.mb_substr($stage[$l->lead_pipeline_stage_id], 0, 14).' '.round($l->lead_value))->implode(' | ') : '';
    $extra = isset($r['pend']) ? " → pendiente en #{$r['pend']}" : (array_key_exists('prev', $r) ? ' → anterior: '.($r['prev'] ?? 'ninguno') : '');
    echo "{$r['estado']}{$extra} | {$r['at']->format('Y-m-d H:i')} {$r['src']} ref {$r['ref']}".(isset($r['veces']) ? " ({$r['veces']} conf.)" : '')." | {$per}\n";
    echo "   pedido: {$pl} | total ".($r['total'] ?? '?')."\n   lead cercano: {$ld}\n   leads del contacto: {$todos}\n";
}
