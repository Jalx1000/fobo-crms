<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Inbox por asesor (spec docs/specs/tablero-por-asesor.md, D8–D11): un
 * ejecutivo ve y atiende solo sus conversaciones —las mismas de «Míos»—;
 * Administrador y Supervisores ven todas las que tienen contacto o pedido, y
 * las que no tienen ninguno de los dos solo el Administrador.
 */
require_once __DIR__.'/helpers.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Bulmaro como ejecutivo común: acá no es encargado nacional (ver EncargadoNacionalTest).
    config(['reporting.encargados_nacionales' => []]);

    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();
    $this->javier = User::findOrFail(1);

    $this->deBulmaro = bandejaConv($this->bulmaro->id);
    $this->deEduardo = bandejaConv($this->eduardo->id);
    $this->sinNada = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5916'.random_int(1000000, 9999999), 'wa_name' => 'Sin nada',
        'ai_enabled' => true, 'status' => 'open', 'last_message_at' => now()->addMinute(),
    ]);
});

it('un ejecutivo ve sus conversaciones y no las de otro', function () {
    $lista = bandeja($this->bulmaro);

    expect($lista)->toHaveKey($this->deBulmaro->id)
        ->not->toHaveKey($this->deEduardo->id)
        ->not->toHaveKey($this->sinNada->id);
});

it('lo que ve un ejecutivo es exactamente su «Míos»', function () {
    bandejaConv($this->eduardo->id, null, [
        'handoff_state' => Conversation::HANDOFF_ASSIGNED, 'handoff_requested_at' => now(), 'assigned_user_id' => $this->bulmaro->id,
    ]);

    expect(array_keys(bandeja($this->bulmaro)))->toEqualCanonicalizing(array_keys(bandeja($this->bulmaro, ['filter' => 'mine'])));
});

it('una derivación abierta a un ejecutivo se la muestra aunque el pedido sea de otro', function () {
    $derivada = bandejaConv($this->eduardo->id, null, [
        'handoff_state' => Conversation::HANDOFF_ASSIGNED, 'handoff_requested_at' => now(), 'assigned_user_id' => $this->bulmaro->id,
    ]);

    expect(bandeja($this->bulmaro))->toHaveKey($derivada->id)
        ->and(bandeja($this->eduardo))->not->toHaveKey($derivada->id);
});

it('el supervisor ve todas las que tienen contacto o pedido, pero no las vacías', function () {
    expect(bandeja($this->mariano))->toHaveKey($this->deBulmaro->id)->toHaveKey($this->deEduardo->id)
        ->not->toHaveKey($this->sinNada->id);
});

it('el administrador lo ve todo, también las sin contacto ni pedido', function () {
    expect(bandeja($this->javier))->toHaveKey($this->deBulmaro->id)->toHaveKey($this->deEduardo->id)
        ->toHaveKey($this->sinNada->id);
});

it('un ejecutivo no ve las de otro filtrando por asesor, y el selector solo lo trae a él', function () {
    expect(bandeja($this->bulmaro, ['asesor' => $this->eduardo->id]))->not->toHaveKey($this->deEduardo->id);

    $asesores = test()->actingAs($this->bulmaro, 'user')->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations'))->json('asesores');

    expect(collect($asesores)->pluck('name')->all())->toBe(['Bulmaro Vaca']);
});

/**
 * B2 (D10, criterio de éxito 4): TODAS las rutas del inbox que reciben una
 * conversación, un contacto o un pedido responden 404 a un ejecutivo con los
 * de otro. Si mañana se agrega una ruta y nadie le pone el control, este test
 * la encuentra (salvo las que no reciben nada de un cliente, listadas abajo).
 */
it('cada ruta del inbox le responde 404 a un ejecutivo con la conversación de otro', function () {
    $sinCliente = [
        'admin.whatsapp.index', 'admin.whatsapp.conversations', 'admin.whatsapp.products',
        'admin.whatsapp.stages', 'admin.whatsapp.templates',
        'admin.whatsapp.quick-replies.index', 'admin.whatsapp.quick-replies.store',
        'admin.whatsapp.quick-replies.update', 'admin.whatsapp.quick-replies.destroy',
    ];

    $ajena = $this->deEduardo;
    $cuerpo = ['conversation_id' => $ajena->id, 'text' => 'hola', 'comment' => 'nota', 'enabled' => true,
        'lead_id'                => $ajena->lead_id, 'user_id' => $this->bulmaro->id, 'lead_pipeline_stage_id' => 1,
        'phone'                  => $ajena->wa_phone, 'latitude' => -17.78, 'longitude' => -63.18, 'name' => 'x', 'title' => 'x',
        // send-media y send-template validan antes de buscar la conversación:
        // con un payload válido se llega al control de acceso.
        'type'     => array_key_first((array) config('whatsapp.media')),
        'file'     => \Illuminate\Http\UploadedFile::fake()->image('a.jpg'),
        'template' => 'cotizacion_documento_v2', 'language' => 'es', 'variables' => ['a', 'b']];

    $probadas = [];

    foreach (app('router')->getRoutes() as $ruta) {
        $nombre = (string) $ruta->getName();

        if (! str_starts_with($nombre, 'admin.whatsapp.') || in_array($nombre, $sinCliente, true)) {
            continue;
        }

        $id = match (true) {
            str_contains($ruta->uri(), 'persons/{id}') => $ajena->person_id,
            str_contains($ruta->uri(), 'leads/{id}')   => $ajena->lead_id,
            default                                    => $ajena->id,
        };

        $url = str_contains($ruta->uri(), '{id}') ? route($nombre, ['id' => $id]) : route($nombre);
        $metodo = collect($ruta->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        $respuesta = test()->actingAs($this->bulmaro, 'user')
            ->withHeaders(['Accept' => 'application/json'])
            ->call($metodo, $url, $metodo === 'GET' ? [] : collect($cuerpo)->except('file')->all(), [], $metodo === 'GET' ? [] : ['file' => $cuerpo['file']]);

        $thread = $nombre === 'admin.whatsapp.thread';
        $status = $thread
            ? test()->actingAs($this->bulmaro, 'user')->withHeaders(['Accept' => 'application/json'])
                ->get(route($nombre, ['conversation_id' => $ajena->id]))->status()
            : $respuesta->status();

        $probadas[$nombre] = $status;
    }

    expect($probadas)->not->toBeEmpty()
        ->and(array_filter($probadas, fn ($s) => $s !== 404))->toBe([], 'Rutas que no devolvieron 404');
});

it('con su propia conversación, un ejecutivo sí la abre', function () {
    test()->actingAs($this->bulmaro, 'user')->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.thread', ['conversation_id' => $this->deBulmaro->id]))
        ->assertOk();

    test()->actingAs($this->bulmaro, 'user')->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.person', ['id' => $this->deBulmaro->person_id]))
        ->assertOk();
});

it('un ejecutivo no abre una conversación nueva a un número suelto; el supervisor sí puede', function () {
    $numero = ['phone' => '+5917'.random_int(1000000, 9999999)];

    test()->actingAs($this->bulmaro, 'user')->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.thread', $numero))->assertNotFound();

    expect(test()->actingAs($this->mariano, 'user')->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.thread', $numero))->status())->not->toBe(404);
});

/**
 * B3 (D12): las derivaciones sin asignar no las toman ni reasignan los
 * ejecutivos; las ven y asignan Administrador y Supervisores. Y al volver a
 * pedir una derivación que quedó sin asignar, se reintenta el turno.
 */
it('un ejecutivo no puede tomar ni reasignar derivaciones; el supervisor sí', function () {
    $pool = bandejaConv($this->bulmaro->id, null, [
        'handoff_state' => Conversation::HANDOFF_REQUESTED, 'handoff_requested_at' => now(), 'assigned_user_id' => null,
    ]);

    test()->actingAs($this->bulmaro, 'user')->postJson(route('admin.whatsapp.handoff.claim', ['id' => $pool->id]))->assertForbidden();
    test()->actingAs($this->bulmaro, 'user')->postJson(route('admin.whatsapp.handoff.reassign', ['id' => $pool->id]), ['user_id' => $this->bulmaro->id])->assertForbidden();

    test()->actingAs($this->mariano, 'user')->postJson(route('admin.whatsapp.handoff.reassign', ['id' => $pool->id]), ['user_id' => $this->bulmaro->id])->assertOk();
    expect($pool->fresh()->assigned_user_id)->toBe($this->bulmaro->id);
});

it('los filtros de derivaciones de todo el equipo no le traen nada ajeno a un ejecutivo', function () {
    $ajenaEnPool = bandejaConv($this->eduardo->id, null, [
        'handoff_state' => Conversation::HANDOFF_REQUESTED, 'handoff_requested_at' => now(), 'assigned_user_id' => null,
    ]);

    expect(bandeja($this->bulmaro, ['filter' => 'pool']))->not->toHaveKey($ajenaEnPool->id)
        ->and(bandeja($this->bulmaro, ['filter' => 'handoff']))->not->toHaveKey($ajenaEnPool->id)
        ->and(bandeja($this->mariano, ['filter' => 'pool']))->toHaveKey($ajenaEnPool->id);
});

it('volver a pedir una derivación que quedó sin asignar reintenta el turno', function () {
    $conv = bandejaConv($this->bulmaro->id, null, [
        'handoff_state' => Conversation::HANDOFF_REQUESTED, 'handoff_requested_at' => now(), 'assigned_user_id' => null, 'ai_enabled' => false,
    ]);

    app(\Webkul\Whatsapp\Services\Handoff::class)->request($conv, 'otra vez');

    expect($conv->fresh()->assigned_user_id)->toBe($this->bulmaro->id)
        ->and($conv->fresh()->handoff_state)->toBe(Conversation::HANDOFF_ASSIGNED);
});

it('el inbox no le muestra a un ejecutivo los filtros «Sin asesor» ni «Sin contacto»', function () {
    $deBulmaro = test()->actingAs($this->bulmaro, 'user')->get(route('admin.whatsapp.index'))->assertOk()->getContent();
    $deMariano = test()->actingAs($this->mariano, 'user')->get(route('admin.whatsapp.index'))->assertOk()->getContent();
    $deJavier = test()->actingAs($this->javier, 'user')->get(route('admin.whatsapp.index'))->assertOk()->getContent();

    expect($deBulmaro)->not->toContain("value: 'pool'")->not->toContain("value: 'unassigned'")
        ->and($deMariano)->toContain("value: 'pool'")->not->toContain("value: 'unassigned'")
        ->and($deJavier)->toContain("value: 'pool'")->toContain("value: 'unassigned'");
});

// «Pasar derivación» (21/10/2026): el botón lo ven solo Administrador y
// Supervisores, y pasa la derivación a otro ejecutivo (WhatsApp y Messenger).
it('el botón «Pasar derivación» lo ven solo Administrador y Supervisores, y pasa la derivación', function () {
    expect(test()->actingAs($this->javier, 'user')->get(route('admin.whatsapp.index'))->assertOk()->getContent())->toContain('Elegí un ejecutivo')
        ->and(test()->actingAs($this->mariano, 'user')->get(route('admin.whatsapp.index'))->getContent())->toContain('Elegí un ejecutivo')
        ->and(test()->actingAs($this->eduardo, 'user')->get(route('admin.whatsapp.index'))->getContent())->not->toContain('Elegí un ejecutivo');

    $messenger = bandejaConv($this->bulmaro->id);
    $messenger->forceFill(['gateway' => 'messenger', 'handoff_state' => Conversation::HANDOFF_ASSIGNED, 'assigned_user_id' => $this->bulmaro->id, 'handoff_requested_at' => now()])->save();

    \Illuminate\Support\Facades\Mail::fake();
    test()->actingAs($this->mariano, 'user')
        ->postJson(route('admin.whatsapp.handoff.reassign', ['id' => $messenger->id]), ['user_id' => $this->eduardo->id])
        ->assertOk()->assertJsonPath('handoff.assigned_user.id', $this->eduardo->id);
});
