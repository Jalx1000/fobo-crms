<?php

/*
 * Julio 2026: entregados sin líneas y leads abiertos que ya no corresponden (31/10/2026).
 *
 *   C. Carga las líneas a 3 entregados que tenían valor pero no productos
 *      (lo confirmado en la memoria de n8n): #301, #354 → 1× Varietales;
 *      #452 → 4× Varietales.
 *   D. Cierra sólo lo que es claro:
 *      - duplicados de un pedido ENTREGADO (misma persona y mismos productos)
 *        → Pedidos cancelados, con nota "duplicado de #X";
 *      - duplicados entre sí que creó el agente → queda abierto el primero;
 *      - vacíos (Bs 0, sin productos, sin conversación) → Cliente sin interés.
 *
 * Antes de tocar nada verifica cada par. Si uno no coincide, aborta todo.
 * Directo a la base y en una transacción, sin eventos.
 */

use Illuminate\Support\Facades\DB;

$firma = fn (int $lead) => DB::table('lead_products')->where('lead_id', $lead)
    ->orderBy('product_id')->get(['product_id', 'quantity'])->map(fn ($l) => $l->product_id.'x'.$l->quantity)->implode(',');
$lead = fn (int $id) => DB::table('leads')->find($id);

$duplicados = [   // [se cierra, original]
    [516, 548], [506, 539], [438, 452],          // el original está entregado
    [498, 473], [531, 485], [501, 486], [518, 486], // duplicados entre sí: queda el primero
];
$vacios = [449, 471, 500, 536];
$lineas = [301 => [[43, 1, 239]], 354 => [[43, 1, 239]], 452 => [[43, 4, 239]]];

if (DB::table('lead_products')->where('lead_id', 452)->exists()) {
    exit("Ya aplicado: no se hace nada.\n");
}

// ---- verificaciones ----
$errores = [];
foreach ($duplicados as [$dup, $orig]) {
    $a = $lead($dup); $b = $lead($orig);
    $pa = DB::table('persons')->find($a->person_id); $pb = DB::table('persons')->find($b->person_id);
    $mismaPersona = $a->person_id === $b->person_id
        || substr(preg_replace('/\D/', '', json_decode($pa->contact_numbers, true)[0]['value'] ?? ''), -8)
           === substr(preg_replace('/\D/', '', json_decode($pb->contact_numbers, true)[0]['value'] ?? ''), -8);
    // #438/#452: #452 no tenía líneas; se compara contra lo que se le va a cargar.
    $fb = $orig === 452 ? '43x4' : $firma($orig);
    if (! $mismaPersona) $errores[] = "#{$dup}/#{$orig}: personas distintas ({$pa->name} / {$pb->name})";
    if ($firma($dup) !== $fb) $errores[] = "#{$dup}/#{$orig}: productos distintos ({$firma($dup)} / {$fb})";
    if ((float) $a->lead_value !== (float) $b->lead_value) $errores[] = "#{$dup}/#{$orig}: valor distinto";
}
foreach ($vacios as $v) {
    $l = $lead($v);
    if ((float) $l->lead_value > 0 || DB::table('lead_products')->where('lead_id', $v)->exists()
        || DB::table('whatsapp_conversations')->where('lead_id', $v)->exists()) {
        $errores[] = "#{$v} no está vacío";
    }
}
if ($errores) {
    exit("No se aplica nada:\n  ".implode("\n  ", $errores)."\n");
}

$todos = array_merge(array_keys($lineas), array_column($duplicados, 0), $vacios);
file_put_contents('/code/docs/cierre_julio_abiertos_respaldo.json', json_encode([
    'leads'            => DB::table('leads')->whereIn('id', $todos)->get()->toArray(),
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->whereIn('entity_id', $todos)->where('attribute_id', 28)->get()->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$etapa = fn (int $pipeline, string $code) => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->where('code', $code)->value('id');
$note = function (int $lead, string $text) {
    $id = DB::table('activities')->insertGetId(['type' => 'note', 'comment' => $text, 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => $lead]);
};
$cerrar = function (int $id, string $code, string $nota) use ($lead, $etapa, $note) {
    $l = $lead($id);
    $s = $etapa($l->lead_pipeline_id, $code);
    DB::table('leads')->where('id', $id)->update(['lead_pipeline_stage_id' => $s, 'closed_at' => now(), 'updated_at' => now()]);
    DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $id)->where('attribute_id', 28)->update(['integer_value' => $s]);
    $note($id, $nota);
};

DB::transaction(function () use ($lineas, $duplicados, $vacios, $lead, $note, $cerrar) {
    foreach ($lineas as $id => $rows) {
        $l = $lead($id);
        foreach ($rows as [$pid, $qty, $price]) {
            DB::table('lead_products')->insert(['lead_id' => $id, 'product_id' => $pid, 'quantity' => $qty, 'price' => $price,
                'amount' => $qty * $price, 'created_at' => $l->closed_at, 'updated_at' => $l->closed_at]);
        }
        $note($id, '31/10/2026: el pedido estaba entregado con valor pero sin productos. Se cargaron las líneas que el cliente confirmó con Sofía (memoria de n8n).');
    }
    foreach ($duplicados as [$dup, $orig]) {
        $o = $lead($orig);
        $estado = DB::table('lead_pipeline_stages')->where('id', $o->lead_pipeline_stage_id)->value('name');
        $cerrar($dup, 'pedidos-cancelados', "31/10/2026: cerrado por ser duplicado del #{$orig} (misma persona, mismos productos y valor; el #{$orig} está en \"{$estado}\"). No es una venta perdida.");
    }
    foreach ($vacios as $id) {
        $cerrar($id, 'cliente-sin-inters', '31/10/2026: abierto desde julio sin productos, sin valor y sin conversación. Se cierra como sin interés.');
    }
});

foreach ($todos as $id) {
    $l = DB::selectOne('select l.id, s.name e, round(l.lead_value) v, (select coalesce(sum(quantity*price),0) from kl_lead_products where lead_id=l.id) li from kl_leads l join kl_lead_pipeline_stages s on s.id=l.lead_pipeline_stage_id where l.id=?', [$id]);
    echo "#{$l->id} {$l->e} Bs {$l->v} / líneas ".(float) $l->li."\n";
}
