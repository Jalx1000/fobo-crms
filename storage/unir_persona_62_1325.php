<?php

/*
 * Unión de contactos duplicados de Enrique Pomar (31/10/2026).
 *
 * #62 (28/05, teléfono mal normalizado 591765627290) y #1325 (09/09,
 * 59176562729) son la misma persona. Queda el #1325 porque es el que tiene la
 * conversación de WhatsApp (#580) que usa el agente.
 *
 *   1. respaldo en docs/union_persona_62_1325_respaldo.json;
 *   2. el lead 70 y las actividades del #62 pasan al #1325;
 *   3. el #1325 pasa a llamarse "Enrique Pomar";
 *   4. se borra el #62 (sus attribute_values a mano: no tienen FK);
 *   5. nota en el #1325 y nota en el lead 70 por los Bs 174 sin líneas.
 *
 * Directo a la base y en una transacción, sin eventos (person.update.after
 * dispara la sincronización de ciudad sobre los leads).
 *
 * Correr: docker exec -u www-data -e HOME=/tmp -w /code <contenedor> php artisan tinker storage/unir_persona_62_1325.php
 */

use Illuminate\Support\Facades\DB;

$from = 62;
$to = 1325;

if (! DB::table('persons')->where('id', $from)->exists()) {
    exit("El #{$from} ya no existe: no se aplica nada.\n");
}

$backup = [
    'persons'          => DB::table('persons')->whereIn('id', [$from, $to])->get()->toArray(),
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'persons')->whereIn('entity_id', [$from, $to])->get()->toArray(),
    'leads'            => DB::table('leads')->where('person_id', $from)->pluck('id')->all(),
    'activities'       => DB::table('person_activities')->where('person_id', $from)->pluck('activity_id')->all(),
];
file_put_contents('/code/docs/union_persona_62_1325_respaldo.json', json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$nameAttr = DB::table('attributes')->where('entity_type', 'persons')->where('code', 'name')->value('id');

DB::transaction(function () use ($from, $to, $nameAttr) {
    $now = now();

    DB::table('leads')->where('person_id', $from)->update(['person_id' => $to]);

    // Las actividades del #62 se mueven; si alguna ya estuviera en el #1325 se descarta el duplicado.
    $ya = DB::table('person_activities')->where('person_id', $to)->pluck('activity_id')->all();
    DB::table('person_activities')->where('person_id', $from)->whereIn('activity_id', $ya)->delete();
    DB::table('person_activities')->where('person_id', $from)->update(['person_id' => $to]);

    DB::table('persons')->where('id', $to)->update(['name' => 'Enrique Pomar', 'updated_at' => $now]);
    DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $to)
        ->where('attribute_id', $nameAttr)->update(['text_value' => 'Enrique Pomar']);

    DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $from)->delete();
    DB::table('persons')->where('id', $from)->delete();

    $notas = [
        ['person_activities', 'person_id', $to, "Unido el 31/10/2026 con el contacto #{$from} (\"Enrique - Enrique Pomar\", 28/05, teléfono mal cargado 591765627290): era la misma persona. Su lead #70 (Promo Mamá, mayo) pasó a este contacto. Según la nota de Stefani del 11/06, también sería el cliente \"Heber\" (#61, tel. 71214179), que se dejó aparte."],
        ['lead_activities', 'lead_id', 70, 'Revisión 31/10/2026: el valor (Bs 594) es mayor que las líneas (Bs 420). Los Bs 174 que faltan son el Pack Red Blend + Copa (#34) y el Pack Tannat + Copa (#26) que figuran en la descripción: esos productos se borraron y sus líneas se borraron con ellos. El valor se mantiene.'],
    ];

    foreach ($notas as [$tabla, $col, $id, $texto]) {
        $activityId = DB::table('activities')->insertGetId([
            'type' => 'note', 'comment' => $texto, 'is_done' => 1, 'user_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table($tabla)->insert(['activity_id' => $activityId, $col => $id]);
    }
});

echo json_encode(DB::table('persons')->find($to), JSON_UNESCAPED_UNICODE)."\n";
foreach (DB::select('select l.id, l.title, l.lead_value, s.name etapa, l.created_at from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id where l.person_id = ? order by l.created_at', [$to]) as $l) {
    echo '  '.json_encode($l, JSON_UNESCAPED_UNICODE)."\n";
}
echo '  actividades: '.DB::table('person_activities')->where('person_id', $to)->count()."\n";
echo '  #62 existe: '.(DB::table('persons')->where('id', $from)->exists() ? 'sí' : 'no')."\n";
