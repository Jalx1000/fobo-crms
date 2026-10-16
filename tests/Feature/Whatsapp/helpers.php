<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;

/*
| Helpers de los tests de la bandeja de WhatsApp: una conversación con su
| contacto y su pedido (de los dueños que se pidan), y la lista como un usuario.
*/

function bandejaConv(?int $leadOwner, ?int $personOwner = null, array $extra = []): Conversation
{
    $person = DB::table('persons')->insertGetId(['name' => 'Bandeja '.uniqid(), 'user_id' => $personOwner, 'created_at' => now(), 'updated_at' => now()]);

    $lead = $leadOwner === null ? null : DB::table('leads')->insertGetId([
        'title'            => 'Bandeja '.uniqid(), 'person_id' => $person, 'user_id' => $leadOwner, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'       => now(), 'updated_at' => now(),
    ]);

    return Conversation::create(array_merge([
        'gateway'         => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Bandeja',
        'ai_enabled'      => true, 'status' => 'open', 'person_id' => $person, 'lead_id' => $lead,
        'last_message_at' => now()->addMinute(),
    ], $extra));
}

function bandeja(User $como, array $params = []): array
{
    return collect(test()->actingAs($como, 'user')
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations', array_merge(['per_page' => 300], $params)))
        ->assertOk()
        ->json('data'))->keyBy('id')->all();
}
