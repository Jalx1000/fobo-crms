<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Models\ConversationProxy;

/*
 * Cada usuario ve en WhatsApp lo que su view_permission le deja ver.
 *
 * Krayin ya limitaba los leads por `view_permission`, pero la bandeja no: un
 * asesor "individual" veía en WhatsApp las conversaciones de leads que en el
 * listado de leads no podía abrir. Decidido el 2026-10-10: la limitación es
 * del usuario, así que vale igual en la web y en la app.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 * Spec: tasks/spec-api-mobile-whatsapp.md, "Visibilidad".
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

/** Un lead abierto de `$ownerId` para `$personId`. */
function leadDe(int $ownerId, int $personId, int $status = 1): int
{
    $etapa = DB::table('lead_pipeline_stages')->orderBy('id')->first();

    return (int) DB::table('leads')->insertGetId([
        'title'                  => 'Visibilidad (test)',
        'user_id'                => $ownerId,
        'person_id'              => $personId,
        'lead_pipeline_id'       => $etapa->lead_pipeline_id,
        'lead_pipeline_stage_id' => $etapa->id,
        'status'                 => $status,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
}

function personaDePrueba(): int
{
    return (int) DB::table('persons')->insertGetId([
        'name'       => 'Contacto visibilidad '.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function conversacionVisibilidad(array $attrs = []): object
{
    return ConversationProxy::modelClass()::create(array_merge([
        'gateway'    => config('whatsapp.gateway'),
        'wa_phone'   => '+5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Visibilidad '.uniqid(),
        'ai_enabled' => false,
    ], $attrs));
}

/**
 * Un escenario con una conversación de cada tipo, vista por un asesor
 * "individual". Devuelve ids para comparar.
 */
function escenarioVisibilidad(): array
{
    $yo = usuarioMobile(['whatsapp'], 'individual');
    $otro = usuarioMobile(['whatsapp'], 'individual');

    $miPersona = personaDePrueba();
    leadDe($yo->id, $miPersona);

    $personaAjena = personaDePrueba();
    leadDe($otro->id, $personaAjena);

    return [
        'yo'          => $yo,
        'otro'        => $otro,
        // (b) por person_id: el contacto tiene un lead mío.
        'mia'         => conversacionVisibilidad(['person_id' => $miPersona])->id,
        // (b) por lead_id, aunque el contacto no esté vinculado.
        'mia_lead'    => conversacionVisibilidad(['lead_id' => leadDe($yo->id, personaDePrueba())])->id,
        // (a) asignada a mí aunque el lead sea de otro.
        'asignada'    => conversacionVisibilidad([
            'person_id'        => $personaAjena,
            'handoff_state'    => 'assigned',
            'assigned_user_id' => $yo->id,
        ])->id,
        // (c) en la bolsa: derivada y sin dueño.
        'bolsa'       => conversacionVisibilidad(['handoff_state' => 'requested'])->id,
        // No la puedo ver: el lead es de otro.
        'ajena'       => conversacionVisibilidad(['person_id' => $personaAjena])->id,
        // No la puedo ver: sin lead ni derivación.
        'sin_dueno'   => conversacionVisibilidad()->id,
        // Asignada a otro: tampoco.
        'de_otro'     => conversacionVisibilidad([
            'handoff_state'    => 'assigned',
            'assigned_user_id' => $otro->id,
        ])->id,
    ];
}

function visiblesPara(array $userIds, array $ids): array
{
    return ConversationProxy::modelClass()::query()
        ->visibleTo($userIds)
        ->whereIn('id', $ids)
        ->pluck('id')
        ->sort()->values()->all();
}

/*
|--------------------------------------------------------------------------
| La regla
|--------------------------------------------------------------------------
*/

it('un usuario restringido ve las suyas, las asignadas y la bolsa, y nada más', function () {
    $e = escenarioVisibilidad();

    $todas = array_values(array_filter($e, 'is_int'));

    expect(visiblesPara([$e['yo']->id], $todas))->toBe(
        collect([$e['mia'], $e['mia_lead'], $e['asignada'], $e['bolsa']])->sort()->values()->all()
    );
});

it('un usuario global ve todas', function () {
    $e = escenarioVisibilidad();

    $todas = array_values(array_filter($e, 'is_int'));

    $visibles = ConversationProxy::modelClass()::query()->visibleTo(null)->whereIn('id', $todas)->count();

    expect($visibles)->toBe(count($todas));
});

it('un "group" sin grupos ve sólo la bolsa, no todo', function () {
    $e = escenarioVisibilidad();

    expect(visiblesPara([], array_values(array_filter($e, 'is_int'))))->toBe([$e['bolsa']]);
});

/*
|--------------------------------------------------------------------------
| La bandeja web la respeta
|--------------------------------------------------------------------------
*/

it('el listado de la bandeja no trae conversaciones ajenas', function () {
    $e = escenarioVisibilidad();

    $ids = collect(
        test()->actingAs($e['yo'], 'user')
            ->getJson(route('admin.whatsapp.conversations', ['search' => 'Visibilidad ', 'per_page' => 100]))
            ->assertOk()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($e['mia'], $e['asignada'], $e['bolsa'])
        ->not->toContain($e['ajena'], $e['sin_dueno'], $e['de_otro']);
});

it('pedir por id una conversación ajena da 404', function () {
    $e = escenarioVisibilidad();

    $web = test()->actingAs($e['yo'], 'user');

    $web->getJson(route('admin.whatsapp.thread', ['conversation_id' => $e['ajena']]))->assertNotFound();
    $web->patchJson(route('admin.whatsapp.agent', $e['ajena']), ['enabled' => true])->assertNotFound();
    $web->postJson(route('admin.whatsapp.handoff.claim', $e['de_otro']))->assertNotFound();
    $web->postJson(route('admin.whatsapp.notes.store', $e['ajena']), ['comment' => 'x'])->assertNotFound();

    // Y la suya, sí.
    $web->getJson(route('admin.whatsapp.thread', ['conversation_id' => $e['mia']]))->assertOk();
});

it('escribirle a un cliente ajeno da 404 y no abre un hilo paralelo', function () {
    $e = escenarioVisibilidad();
    $ajena = ConversationProxy::modelClass()::find($e['ajena']);

    $antes = ConversationProxy::modelClass()::count();

    test()->actingAs($e['yo'], 'user')
        ->postJson(route('admin.whatsapp.send'), [
            'conversation_id' => $ajena->id,
            'phone'           => $ajena->wa_phone,
            'text'            => 'Hola',
        ])
        ->assertNotFound();

    // Por teléfono tampoco: es el camino que crearía una conversación nueva.
    test()->actingAs($e['yo'], 'user')
        ->postJson(route('admin.whatsapp.send'), ['phone' => $ajena->wa_phone, 'text' => 'Hola'])
        ->assertNotFound();

    expect(ConversationProxy::modelClass()::count())->toBe($antes);
});

it('un lead cerrado también cuenta, como en el listado de leads', function () {
    $yo = usuarioMobile(['whatsapp'], 'individual');
    $persona = personaDePrueba();
    leadDe($yo->id, $persona, status: 0);

    $conversacion = conversacionVisibilidad(['person_id' => $persona])->id;

    expect(visiblesPara([$yo->id], [$conversacion]))->toBe([$conversacion]);
});
