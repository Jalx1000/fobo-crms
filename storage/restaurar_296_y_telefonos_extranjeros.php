<?php

/*
 * 31/10/2026.
 *
 * 1. Restaura el lead #296 (José Luis Zeballos, 04/07, entregado 06/07, Bs 239)
 *    desde las tablas kl_dup_bkp_*: la limpieza de duplicados del 28/09 lo
 *    borró aunque era una segunda compra (el #229 es del 02/07, otro chat).
 *    Se restaura con su id, sus líneas, sus atributos y su historial. Su
 *    contacto #211 se unió hoy al #1499, así que queda en el #1499.
 *
 * 2. Saca el 0 de más a los teléfonos extranjeros, sólo cuando el número
 *    guardado es exactamente el real + "0". El real sale del correo que n8n
 *    le puso al contacto (<telefono>@whatsapp.sofopolis.com).
 *
 * Directo a la base y en una transacción, sin eventos.
 */

use Illuminate\Support\Facades\DB;

$hecho296 = DB::table('leads')->where('id', 296)->exists();

// --- teléfonos extranjeros: verificar primero ---
$tels = [];
foreach (DB::select("select id, name, contact_numbers, emails, unique_id,
        json_unquote(json_extract(contact_numbers, '$[0].value')) t
    from kl_persons where json_unquote(json_extract(contact_numbers, '$[0].value')) not like '591%'
      and json_unquote(json_extract(contact_numbers, '$[0].value')) regexp '^[0-9]+0$' and json_length(contact_numbers) = 1") as $p) {
    $real = null;
    foreach ((array) json_decode((string) $p->emails, true) as $e) {
        if (preg_match('/^(\d+)@whatsapp\.sofopolis\.com$/', $e['value'] ?? '', $m)) {
            $real = $m[1];
        }
    }
    $ok = $real && $p->t === $real.'0';
    $dup = $ok ? DB::table('persons')->where('id', '<>', $p->id)->where('contact_numbers', 'like', '%'.substr($real, -8).'%')->pluck('id')->all() : [];
    $tels[] = ['p' => $p, 'real' => $real, 'ok' => $ok && ! $dup, 'dup' => $dup];
}

file_put_contents('/code/docs/restauracion_296_y_telefonos_respaldo.json', json_encode([
    'persons' => array_map(fn ($x) => $x['p'], $tels),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$attrTel = DB::table('attributes')->where('entity_type', 'persons')->where('code', 'contact_numbers')->value('id');

DB::transaction(function () use ($hecho296, $tels, $attrTel) {
    if (! $hecho296) {
        $l = (array) DB::table('dup_bkp_leads')->where('id', 296)->first();
        $l['person_id'] = 1499;
        DB::table('leads')->insert($l);

        foreach (DB::table('dup_bkp_attr_values')->where('entity_type', 'leads')->where('entity_id', 296)->get() as $av) {
            $av = (array) $av;
            unset($av['id']);
            DB::table('attribute_values')->insert($av);
        }
        foreach (DB::table('dup_bkp_products')->where('lead_id', 296)->get() as $lp) {
            DB::table('lead_products')->insert((array) $lp);
        }
        $acts = DB::table('dup_bkp_lead_activities')->where('lead_id', 296)->pluck('activity_id');
        foreach (DB::table('dup_bkp_activities')->whereIn('id', $acts)->get() as $a) {
            if (! DB::table('activities')->where('id', $a->id)->exists()) {
                DB::table('activities')->insert((array) $a);
            }
            DB::table('lead_activities')->insert(['activity_id' => $a->id, 'lead_id' => 296]);
        }

        $id = DB::table('activities')->insertGetId(['type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
            'comment' => 'Restaurado el 31/10/2026 desde el respaldo de la limpieza de duplicados del 28/09, que lo había borrado. No era un duplicado: es una segunda compra (chat del 04/07, entregada por Liliana el 06/07); la primera es el #229 (02/07, entregada el 03/07).']);
        DB::table('lead_activities')->insert(['activity_id' => $id, 'lead_id' => 296]);
    }

    foreach ($tels as $x) {
        if (! $x['ok']) {
            continue;
        }
        $p = $x['p'];
        $numeros = str_replace("\"{$p->t}\"", "\"{$x['real']}\"", $p->contact_numbers);
        DB::table('persons')->where('id', $p->id)->update([
            'contact_numbers' => $numeros,
            'unique_id'       => $p->unique_id ? str_replace($p->t, $x['real'], $p->unique_id) : $p->unique_id,
            'updated_at'      => now(),
        ]);
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $p->id)
            ->where('attribute_id', $attrTel)->update(['json_value' => $numeros]);
    }
});

$r = DB::selectOne('select l.id, l.title, round(l.lead_value) v, s.name e, l.closed_at, p.name, u.name a,
    (select group_concat(concat(quantity, "x #", product_id, " @", round(price))) from kl_lead_products where lead_id = l.id) pr,
    (select count(*) from kl_lead_activities where lead_id = l.id) acts
    from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id join kl_persons p on p.id = l.person_id left join kl_users u on u.id = l.user_id where l.id = 296');
echo "#296 {$r->title} | {$r->e} | Bs {$r->v} | {$r->pr} | contacto {$r->name} | {$r->a} | cerrado {$r->closed_at} | {$r->acts} actividades\n";
echo 'Leads de José Luis: '.json_encode(DB::table('leads')->where('person_id', 1499)->pluck('id'))."\n";
foreach ($tels as $x) {
    echo '#'.$x['p']->id.' '.$x['p']->name.' | '.$x['p']->t.' → '.($x['ok'] ? $x['real'].' ✔' : 'NO se toca (real: '.($x['real'] ?? '?').($x['dup'] ? ', choca con #'.implode(',', $x['dup']) : '').')')."\n";
}
