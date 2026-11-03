<?php
// #952 Manuel Borda — 01/11/2026: la línea 1113 (2× Promo Varietales @239) tenía amount 0. Pasa a 478.
use Illuminate\Support\Facades\DB;
$lp = DB::table('lead_products')->find(1113);
if ((float) $lp->amount === 478.0) exit("Ya aplicado.\n");
if ($lp->lead_id !== 952 || (float) $lp->amount !== 0.0 || (int) $lp->quantity !== 2 || (float) $lp->price !== 239.0) exit("No coincide con lo auditado.\n");
file_put_contents('/code/docs/correccion_952_monto_respaldo.json', json_encode($lp, JSON_PRETTY_PRINT));
DB::transaction(function () {
    DB::table('lead_products')->where('id', 1113)->update(['amount' => 478, 'updated_at' => now()]);
    $id = DB::table('activities')->insertGetId(['type' => 'note', 'comment' => '01/11/2026: la línea de 2× Promo Varietales tenía el monto en Bs 0. Se completó con cantidad × precio: Bs 478 (coincide con el pedido del 07/09 en WhatsApp y con el valor del lead).', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => 952]);
});
echo json_encode(DB::table('lead_products')->find(1113))."\n";
