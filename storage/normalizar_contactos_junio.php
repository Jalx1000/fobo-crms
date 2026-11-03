<?php

/*
 * Contactos de junio 2026 (31/10/2026).
 *
 * En junio n8n guardaba el teléfono con un 0 de más al final (591XXXXXXXX0).
 * PhoneNumber::same() exige que un número sea sufijo del otro, así que cuando
 * el cliente vuelve a escribir no se lo reconoce y se crea un contacto nuevo.
 *
 *   1. respaldo en docs/normalizacion_contactos_junio_respaldo.json;
 *   2. une los 3 que ya se duplicaron en el contacto que tiene la conversación
 *      de WhatsApp (leads y actividades pasan; el de junio se borra);
 *   3. saca el 0 final a los demás contactos de junio con 591 + 9 dígitos.
 *
 * Directo a la base, en una transacción y sin eventos (person.update.after
 * dispara la sincronización de ciudad sobre los leads).
 *
 * Correr: docker exec -u www-data -e HOME=/tmp -w /code <contenedor> php artisan tinker storage/normalizar_contactos_junio.php
 */

use Illuminate\Support\Facades\DB;

// [contacto de junio que se borra, contacto que queda, nombre final]
$uniones = [
    [84, 7992, 'Mario'],
    [146, 1215, 'Sarah Ayad'],
    [167, 1519, 'Camilo Haibara'],
];

if (! DB::table('persons')->whereIn('id', array_column($uniones, 0))->exists()) {
    exit("Ya aplicado: no se hace nada.\n");
}

$mal = collect(DB::select("select id, contact_numbers, unique_id from kl_persons
    where created_at >= '2026-06-01' and created_at < '2026-07-01'
      and json_unquote(json_extract(contact_numbers, '$[0].value')) regexp '^591[0-9]{8}0$'"))
    ->reject(fn ($p) => in_array($p->id, array_column($uniones, 0)));

$ids = array_merge(array_column($uniones, 0), array_column($uniones, 1), $mal->pluck('id')->all());
file_put_contents('/code/docs/normalizacion_contactos_junio_respaldo.json', json_encode([
    'persons'          => DB::table('persons')->whereIn('id', $ids)->get()->toArray(),
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', $ids)->get()->toArray(),
    'leads'            => DB::table('leads')->whereIn('person_id', array_column($uniones, 0))->get(['id', 'person_id'])->toArray(),
    'activities'       => DB::table('person_activities')->whereIn('person_id', array_column($uniones, 0))->get()->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$attr = DB::table('attributes')->where('entity_type', 'persons')->pluck('id', 'code');

DB::transaction(function () use ($uniones, $mal, $attr) {
    $now = now();

    foreach ($uniones as [$from, $to, $nombre]) {
        $viejo = DB::table('persons')->find($from);

        DB::table('leads')->where('person_id', $from)->update(['person_id' => $to]);

        $ya = DB::table('person_activities')->where('person_id', $to)->pluck('activity_id')->all();
        DB::table('person_activities')->where('person_id', $from)->whereIn('activity_id', $ya)->delete();
        DB::table('person_activities')->where('person_id', $from)->update(['person_id' => $to]);

        DB::table('persons')->where('id', $to)->update(['name' => $nombre, 'updated_at' => $now]);
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $to)
            ->where('attribute_id', $attr['name'])->update(['text_value' => $nombre]);

        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $from)->delete();
        DB::table('persons')->where('id', $from)->delete();

        $activityId = DB::table('activities')->insertGetId([
            'type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => $now, 'updated_at' => $now,
            'comment' => "Unido el 31/10/2026 con el contacto #{$from} (\"{$viejo->name}\", ".substr($viejo->created_at, 0, 10).'), que era la misma persona: en junio el teléfono se guardó con un 0 de más al final y al volver a escribir no se lo reconoció. Sus leads y su historial pasaron a este contacto.',
        ]);
        DB::table('person_activities')->insert(['activity_id' => $activityId, 'person_id' => $to]);
    }

    foreach ($mal as $p) {
        $tel = json_decode($p->contact_numbers, true)[0]['value'];
        $bien = substr($tel, 0, -1);
        $numeros = str_replace("\"{$tel}\"", "\"{$bien}\"", $p->contact_numbers);

        DB::table('persons')->where('id', $p->id)->update([
            'contact_numbers' => $numeros,
            'unique_id'       => $p->unique_id ? str_replace($tel, $bien, $p->unique_id) : $p->unique_id,
            'updated_at'      => $now,
        ]);
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $p->id)
            ->where('attribute_id', $attr['contact_numbers'])->update(['json_value' => $numeros]);
    }

    echo 'Unidos: '.count($uniones).' | teléfonos corregidos: '.$mal->count()."\n";
});

foreach ($uniones as [$from, $to]) {
    $p = DB::table('persons')->find($to);
    echo "#{$to} {$p->name} {$p->contact_numbers} | leads ".DB::table('leads')->where('person_id', $to)->pluck('id')->implode(',')
        .' | #'.$from.(DB::table('persons')->where('id', $from)->exists() ? ' sigue' : ' borrado')."\n";
}
$quedan = DB::selectOne("select count(*) n from kl_persons where created_at >= '2026-06-01' and created_at < '2026-07-01'
    and json_unquote(json_extract(contact_numbers, '$[0].value')) regexp '^591[0-9]{9}$'")->n;
echo "Junio con 591 + 9 dígitos que quedan: {$quedan}\n";
