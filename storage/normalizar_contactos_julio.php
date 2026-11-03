<?php

/*
 * Contactos con el 0 de más en el teléfono (julio 2026) — 31/10/2026.
 *
 * Mismo problema que junio (ver normalizar_contactos_junio.php): n8n guardaba
 * 591XXXXXXXX0 y PhoneNumber::same() no lo reconoce cuando el cliente vuelve.
 *
 *   1. respaldo en docs/normalizacion_contactos_julio_respaldo.json;
 *   2. une cada par cuyo número es EXACTAMENTE el mismo (verificado dígito a
 *      dígito, no por "contiene"). Queda el contacto con conversación de
 *      WhatsApp; si ninguno tiene, el de julio. Pasan leads, actividades,
 *      etiquetas y atributos que al que queda le falten;
 *   3. saca el 0 final a todos los demás.
 *
 * Directo a la base y en una transacción, sin eventos.
 *
 * Correr: docker exec -u www-data -e HOME=/tmp -w /code <contenedor> php artisan tinker storage/normalizar_contactos_julio.php
 */

use Illuminate\Support\Facades\DB;
use Webkul\Core\Services\PhoneNumber;

$mal = collect(DB::select("select id, name, contact_numbers, unique_id, created_at,
        json_unquote(json_extract(contact_numbers, '$[0].value')) tel
    from kl_persons where json_unquote(json_extract(contact_numbers, '$[0].value')) regexp '^591[0-9]{8}0$'
      and json_length(contact_numbers) = 1 order by id"));

if ($mal->isEmpty()) {
    exit("Ya aplicado: no se hace nada.\n");
}

// Pares exactos.
$pares = [];
foreach ($mal as $p) {
    $bien = substr($p->tel, 0, -1);
    $core = substr($bien, 3);
    foreach (DB::table('persons')->where('id', '<>', $p->id)->where('contact_numbers', 'like', "%{$core}%")->get() as $o) {
        $exacto = collect(json_decode($o->contact_numbers, true))->pluck('value')
            ->contains(fn ($n) => in_array(PhoneNumber::digits($n), [$bien, $core], true));
        if (! $exacto) {
            continue;
        }
        $convP = DB::table('whatsapp_conversations')->where('person_id', $p->id)->exists();
        $convO = DB::table('whatsapp_conversations')->where('person_id', $o->id)->exists();
        [$from, $to] = ($convO && ! $convP) ? [$p->id, $o->id] : [$o->id, $p->id];
        $pares[] = [$from, $to, $p->id];
    }
}

$ids = $mal->pluck('id')->merge(collect($pares)->flatten())->unique()->values()->all();
file_put_contents('/code/docs/normalizacion_contactos_julio_respaldo.json', json_encode([
    'pares'            => $pares,
    'persons'          => DB::table('persons')->whereIn('id', $ids)->get()->toArray(),
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', $ids)->get()->toArray(),
    'leads'            => DB::table('leads')->whereIn('person_id', $ids)->get(['id', 'person_id'])->toArray(),
    'person_activities'=> DB::table('person_activities')->whereIn('person_id', $ids)->get()->toArray(),
    'conversations'    => DB::table('whatsapp_conversations')->whereIn('person_id', $ids)->get(['id', 'person_id'])->toArray(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$attr = DB::table('attributes')->where('entity_type', 'persons')->pluck('id', 'code');

// Corrige el teléfono de un contacto (columna, attribute_value y unique_id).
$arreglar = function ($p) use ($attr) {
    $p = DB::table('persons')->find($p->id ?? $p);
    $tel = json_decode($p->contact_numbers, true)[0]['value'];
    if (! preg_match('/^591\d{8}0$/', $tel)) {
        return false;
    }
    $bien = substr($tel, 0, -1);
    $numeros = str_replace("\"{$tel}\"", "\"{$bien}\"", $p->contact_numbers);
    DB::table('persons')->where('id', $p->id)->update([
        'contact_numbers' => $numeros,
        'unique_id'       => $p->unique_id ? str_replace($tel, $bien, $p->unique_id) : $p->unique_id,
        'updated_at'      => now(),
    ]);
    DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $p->id)
        ->where('attribute_id', $attr['contact_numbers'])->update(['json_value' => $numeros]);

    return true;
};

$unidos = [];
$corregidos = 0;

DB::transaction(function () use ($pares, $mal, $attr, $arreglar, &$unidos, &$corregidos) {
    $now = now();
    $borrados = [];

    foreach ($pares as [$from, $to, $deJulio]) {
        $viejo = DB::table('persons')->find($from);
        $julio = DB::table('persons')->find($deJulio);

        // Nombre: la parte real del nombre de julio ("WhatsApp - Nombre real"), si la tiene.
        $partes = array_map('trim', explode(' - ', $julio->name, 2));
        $nombre = (isset($partes[1]) && $partes[1] !== '') ? $partes[1] : DB::table('persons')->where('id', $to)->value('name');

        DB::table('leads')->where('person_id', $from)->update(['person_id' => $to]);
        DB::table('whatsapp_conversations')->where('person_id', $from)->update(['person_id' => $to]);

        $ya = DB::table('person_activities')->where('person_id', $to)->pluck('activity_id')->all();
        DB::table('person_activities')->where('person_id', $from)->whereIn('activity_id', $ya)->delete();
        DB::table('person_activities')->where('person_id', $from)->update(['person_id' => $to]);

        $tags = DB::table('person_tags')->where('person_id', $to)->pluck('tag_id')->all();
        DB::table('person_tags')->where('person_id', $from)->whereNotIn('tag_id', $tags)->update(['person_id' => $to]);

        // Atributos que al que queda le faltan (edad, ciudad…), menos los de identidad.
        foreach (DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $from)
            ->whereNotIn('attribute_id', [$attr['name'], $attr['contact_numbers'], $attr['emails']])->get() as $av) {
            $q = DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $to)->where('attribute_id', $av->attribute_id);
            $tiene = (clone $q)->where(fn ($w) => $w->whereNotNull('text_value')->orWhereNotNull('integer_value')->orWhereNotNull('json_value'))->exists();
            $vale = $av->text_value !== null || $av->integer_value !== null || $av->json_value !== null;
            if (! $tiene && $vale) {
                $q->exists()
                    ? $q->update(['text_value' => $av->text_value, 'integer_value' => $av->integer_value, 'json_value' => $av->json_value])
                    : DB::table('attribute_values')->insert(['entity_type' => 'persons', 'entity_id' => $to, 'attribute_id' => $av->attribute_id,
                        'text_value' => $av->text_value, 'integer_value' => $av->integer_value, 'json_value' => $av->json_value]);
            }
        }

        DB::table('persons')->where('id', $to)->update(['name' => $nombre, 'updated_at' => $now]);
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $to)
            ->where('attribute_id', $attr['name'])->update(['text_value' => $nombre]);

        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $from)->delete();
        DB::table('persons')->where('id', $from)->delete();
        $borrados[] = $from;

        // Si el que queda es el de julio, su teléfono también se corrige.
        $arreglar($to);

        $activityId = DB::table('activities')->insertGetId([
            'type' => 'note', 'is_done' => 1, 'user_id' => null, 'created_at' => $now, 'updated_at' => $now,
            'comment' => "Unido el 31/10/2026 con el contacto #{$from} (\"{$viejo->name}\", ".substr($viejo->created_at, 0, 10).'), que era la misma persona: en julio el teléfono se guardó con un 0 de más al final y al volver a escribir no se lo reconoció. Sus leads y su historial pasaron a este contacto.',
        ]);
        DB::table('person_activities')->insert(['activity_id' => $activityId, 'person_id' => $to]);
        $unidos[] = "#{$from} → #{$to} {$nombre}";
    }

    foreach ($mal as $p) {
        if (! in_array($p->id, $borrados) && $arreglar($p)) {
            $corregidos++;
        }
    }
});

echo 'Unidos ('.count($unidos)."):\n  ".implode("\n  ", $unidos)."\n";
echo "Teléfonos corregidos fuera de los pares: {$corregidos}\n";
echo 'Quedan con 0 de más: '.DB::selectOne("select count(*) n from kl_persons where json_unquote(json_extract(contact_numbers, '$[0].value')) regexp '^591[0-9]{8}0$'")->n."\n";
