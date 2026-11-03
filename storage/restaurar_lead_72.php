<?php

/*
 * Restauración del lead 72 (31/10/2026).
 *
 * El 30/10 el agente reabrió la entrega de junio del #72 y le cargó encima un
 * pedido nuevo (2 LANZAMIENTO QUERKUS, Bs 540). Esto:
 *   1. guarda un respaldo en docs/restauracion_lead_72_respaldo.json;
 *   2. crea un lead nuevo con el pedido de octubre y le mueve los productos y
 *      la conversación #8088;
 *   3. devuelve el #72 a la entrega de junio (Bs 600, entregado, cerrado 19/06);
 *   4. deja una nota en los dos.
 *
 * Va directo a la base y en una transacción: por el repositorio se dispararían
 * lead.create.after / lead.update.after (aviso de pedido confirmado, sync de
 * ciudad, automatizaciones).
 *
 * Correr: docker exec -u www-data -e HOME=/tmp -w /code <contenedor> php artisan tinker storage/restaurar_lead_72.php
 */

use Illuminate\Support\Facades\DB;

$id = 72;

if (DB::table('leads')->where('id', $id)->value('lead_pipeline_stage_id') != 32) {
    exit("El #72 ya no está en Cliente confirmado: no se aplica nada.\n");
}

$backup = [
    'lead'             => (array) DB::table('leads')->find($id),
    'attribute_values' => DB::table('attribute_values')->where('entity_type', 'leads')->where('entity_id', $id)->get()->toArray(),
    'lead_products'    => DB::table('lead_products')->where('lead_id', $id)->get()->toArray(),
    'conversations'    => DB::table('whatsapp_conversations')->where('lead_id', $id)->pluck('id')->all(),
];
file_put_contents('/code/docs/restauracion_lead_72_respaldo.json', json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$attr = DB::table('attributes')->where('entity_type', 'leads')->pluck('id', 'code');

DB::transaction(function () use ($id, $attr, $backup) {
    $cur = (object) $backup['lead'];
    $now = now();

    // 1) Lead nuevo con el pedido de octubre.
    $new = DB::table('leads')->insertGetId([
        'title'                  => $cur->title,
        'description'            => $cur->description,
        'lead_value'             => $cur->lead_value,
        'lead_value_is_manual'   => 0,
        'status'                 => 1,
        'closed_at'              => null,
        'confirmed_at'           => $cur->confirmed_at,
        'first_stage_change_at'  => '2026-10-30 12:46:37',
        'user_id'                => $cur->user_id,
        'person_id'              => $cur->person_id,
        'lead_source_id'         => $cur->lead_source_id,
        'lead_type_id'           => $cur->lead_type_id,
        'lead_pipeline_id'       => $cur->lead_pipeline_id,
        'lead_pipeline_stage_id' => $cur->lead_pipeline_stage_id,
        'expected_close_date'    => null,
        'created_at'             => '2026-10-30 12:44:16',
        'updated_at'             => $now,
    ]);

    foreach ($backup['attribute_values'] as $av) {
        $av = (array) $av;

        // La ubicación es la de mayo (Urquidi): no aplica al pedido nuevo.
        if ($av['attribute_id'] == ($attr['ubicacion_lead'] ?? -1)) {
            continue;
        }

        unset($av['id']);
        $av['entity_id'] = $new;
        $av['unique_id'] = $av['unique_id'] ? str_replace("|{$id}|", "|{$new}|", $av['unique_id']) : null;
        DB::table('attribute_values')->insert($av);
    }

    DB::table('lead_products')->where('lead_id', $id)->update(['lead_id' => $new]);
    DB::table('whatsapp_conversations')->where('lead_id', $id)->update(['lead_id' => $new]);

    // 2) El #72 vuelve a la entrega de junio.
    DB::table('leads')->where('id', $id)->update([
        'title'                  => 'Fer Candia',
        'description'            => 'Promo Mamá Elia Rosa',
        'lead_value'             => 600,
        'lead_pipeline_stage_id' => 33,
        'closed_at'              => '2026-06-19 10:22:41',
        'confirmed_at'           => null,
        'user_id'                => 5,
        'updated_at'             => $now,
    ]);

    $set = fn (string $code, string $col, $val) => DB::table('attribute_values')
        ->where('entity_type', 'leads')->where('entity_id', $id)->where('attribute_id', $attr[$code])
        ->update([$col => $val]);

    $set('title', 'text_value', 'Fer Candia');
    $set('description', 'text_value', 'Promo Mamá Elia Rosa');
    $set('lead_value', 'float_value', 600);
    $set('lead_pipeline_stage_id', 'integer_value', 33);
    $set('user_id', 'integer_value', 5);
    $set('edad_lead', 'text_value', '47');

    // 3) Una nota en cada lead.
    $notas = [
        [$id, "Restaurado el 31/10/2026: el 30/10 el agente reabrió esta entrega de junio y le cargó un pedido nuevo encima. El pedido de octubre (2 LANZAMIENTO QUERKUS, Bs 540) se movió al lead #{$new}."],
        [$new, "Creado el 31/10/2026 al restaurar el lead #{$id}: este pedido de octubre se había registrado encima de la entrega de junio del #{$id}. Fecha de creación = primer mensaje del cliente (30/10 12:44)."],
    ];

    foreach ($notas as [$leadId, $texto]) {
        $activityId = DB::table('activities')->insertGetId([
            'type' => 'note', 'comment' => $texto, 'is_done' => 1, 'user_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('lead_activities')->insert(['activity_id' => $activityId, 'lead_id' => $leadId]);
    }

    echo "Lead nuevo: #{$new}\n";
});

foreach (DB::select("select l.id, l.title, l.lead_value, l.closed_at, l.created_at, s.name etapa, u.name asesor,
    (select count(*) from kl_lead_products p where p.lead_id = l.id) productos,
    (select group_concat(id) from kl_whatsapp_conversations c where c.lead_id = l.id) convs
    from kl_leads l join kl_lead_pipeline_stages s on s.id = l.lead_pipeline_stage_id join kl_users u on u.id = l.user_id
    where l.person_id = 63 order by l.id") as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}
