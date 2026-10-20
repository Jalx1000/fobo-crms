<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Distribuidor\Services\Reparto;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Dos distribuidores en la MISMA ciudad, cada uno de una empresa distinta.
 *
 * Si uno ve el pedido, el contacto o la conversación del otro, se pelean por el
 * cliente. Cada test arma Palpalá con dos distribuidores de prueba (A y B), sacando
 * al real, y comprueba que ninguno alcanza lo del otro por ningún camino: listas,
 * acciones por id, búsqueda por teléfono, WhatsApp.
 *
 * Todo dentro de una transacción: lo que se crea o se borra acá no sobrevive al test.
 */
uses(DatabaseTransactions::class);

/** @return array{ciudad:int, a:User, b:User} */
function aisladoEscenario(): array
{
    $ciudad = (int) DB::table('lead_pipelines')->where('name', 'like', 'Palpal%')->value('id');

    if (! $ciudad) {
        test()->markTestSkipped('No existe la ciudad Palpalá.');
    }

    $rol = Role::where('name', 'Distribuidor')->first();

    if (! $rol) {
        test()->markTestSkipped('No existe el rol Distribuidor.');
    }

    $crear = fn (string $n) => User::create([
        'name'            => "Distribuidor {$n} ".uniqid(),
        'email'           => "aislado-{$n}-".uniqid().'@test.local',
        'password'        => bcrypt('secret'),
        'role_id'         => $rol->id,
        'status'          => 1,
        'view_permission' => 'individual',
    ]);

    $a = $crear('A');
    $b = $crear('B');

    // La ciudad queda sólo con A y B.
    DB::table('distribuidor_zonas')->where('lead_pipeline_id', $ciudad)->delete();
    DB::table('distribuidor_zonas')->insert([
        ['lead_pipeline_id' => $ciudad, 'user_id' => $a->id, 'created_at' => now(), 'updated_at' => now()],
        ['lead_pipeline_id' => $ciudad, 'user_id' => $b->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // El responsable de lo que no tiene distribuidor (en producción, Huascar).
    $responsable = User::create([
        'name'     => 'Responsable '.uniqid(),
        'email'    => 'responsable-'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => Role::where('name', 'Encargado')->value('id') ?? 1,
        'status'   => 1,
    ]);

    config(['distribuidor.responsable_email' => $responsable->email]);

    return ['ciudad' => $ciudad, 'a' => $a, 'b' => $b, 'responsable' => $responsable];
}

function aisladoTelefono(): string
{
    return '+54938'.random_int(10000000, 99999999);
}

function aisladoCliente(?int $dueno = null, ?string $telefono = null): int
{
    return (int) DB::table('persons')->insertGetId([
        'name'            => 'Cliente aislado '.uniqid(),
        'contact_numbers' => json_encode([['value' => $telefono ?? aisladoTelefono(), 'label' => 'work']]),
        'user_id'         => $dueno,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

function aisladoEtapa(int $ciudad, string $code): int
{
    return (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $ciudad)->where('code', $code)->value('id');
}

/** Un pedido como lo crea el agente: con la sesión del administrador dueño del token. */
function aisladoPedidoDelAgente(int $ciudad, int $personId)
{
    auth()->guard('user')->loginUsingId(1);

    $lead = app(LeadRepository::class)->create([
        'entity_type'            => 'leads',
        'title'                  => 'Pedido aislado '.uniqid(),
        'person_id'              => $personId,
        'lead_pipeline_id'       => $ciudad,
        'lead_pipeline_stage_id' => aisladoEtapa($ciudad, 'pedidos-confirmado'),
        'lead_source_id'         => 5,
        'lead_type_id'           => 1,
        'lead_value'             => 1000,
    ]);

    auth()->guard('user')->logout();

    return $lead->refresh();
}

function aisladoConversacion(?int $personId): int
{
    return (int) ConversationProxy::modelClass()::create([
        'gateway'         => config('whatsapp.gateway'),
        'wa_phone'        => aisladoTelefono(),
        'wa_name'         => 'Conv aislada '.uniqid(),
        'person_id'       => $personId,
        'last_message_at' => now(),
    ])->id;
}

// ───────────────── el reparto ─────────────────

it('el pedido del agente para un cliente sin distribuidor va al responsable', function () {
    ['ciudad' => $c, 'responsable' => $r] = aisladoEscenario();

    $pedido = aisladoPedidoDelAgente($c, aisladoCliente());

    // Ni al administrador del token ni a un distribuidor al azar: al responsable,
    // que lo reparte a mano.
    expect((int) $pedido->user_id)->toBe($r->id)->not->toBe(1);
});

it('el pedido del agente para un cliente de un distribuidor va a ese distribuidor', function () {
    ['ciudad' => $c, 'b' => $b] = aisladoEscenario();

    $pedido = aisladoPedidoDelAgente($c, aisladoCliente($b->id));

    expect((int) $pedido->user_id)->toBe($b->id);
});

it('el cliente sin distribuidor no queda a nombre del responsable', function () {
    ['ciudad' => $c] = aisladoEscenario();

    $cliente = aisladoCliente();
    aisladoPedidoDelAgente($c, $cliente);

    // El responsable recibe el PEDIDO; la ficha queda libre hasta que la asigne a un
    // distribuidor.
    expect(DB::table('persons')->where('id', $cliente)->value('user_id'))->toBeNull();
});

it('un pedido de una ciudad sin distribuidores va al responsable', function () {
    ['ciudad' => $c, 'responsable' => $r] = aisladoEscenario();

    // La ciudad se queda sin distribuidores (el caso de "Sin ciudad", que no tiene
    // etapas en esta base y no deja crear un pedido para probarlo directo).
    DB::table('distribuidor_zonas')->where('lead_pipeline_id', $c)->delete();

    $pedido = aisladoPedidoDelAgente($c, aisladoCliente());

    expect((int) $pedido->user_id)->toBe($r->id);
});

it('el cliente que carga un distribuidor en el Punto queda suyo', function () {
    ['ciudad' => $c, 'a' => $a] = aisladoEscenario();

    $telefono = aisladoTelefono();

    test()->actingAs($a, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), ['telefono' => $telefono, 'nombre' => 'Nuevo de A'])
        ->assertOk();

    $personId = app(\Webkul\Contact\Repositories\PersonRepository::class)->findByPhone($telefono)->id;

    expect((int) DB::table('persons')->where('id', $personId)->value('user_id'))->toBe($a->id);
});

it('cuando el responsable asigna el pedido a un distribuidor, el cliente pasa a ese distribuidor', function () {
    ['ciudad' => $c, 'b' => $b, 'responsable' => $r] = aisladoEscenario();

    $cliente = aisladoCliente();
    $pedido = aisladoPedidoDelAgente($c, $cliente);
    $otro = aisladoPedidoDelAgente($c, $cliente);

    // El responsable, desde el CRM, le pone a B como asesor del pedido.
    auth()->guard('user')->login($r);
    app(LeadRepository::class)->update(['entity_type' => 'leads', 'user_id' => $b->id], $pedido->id);

    expect((int) DB::table('persons')->where('id', $cliente)->value('user_id'))->toBe($b->id)
        ->and((int) DB::table('leads')->where('id', $otro->id)->value('user_id'))->toBe($b->id);
});

// ───────────────── el Punto ─────────────────

it('el historial del Punto muestra sólo los pedidos propios', function () {
    ['ciudad' => $c, 'a' => $a, 'b' => $b] = aisladoEscenario();

    $deA = aisladoPedidoDelAgente($c, aisladoCliente($a->id));
    $deB = aisladoPedidoDelAgente($c, aisladoCliente($b->id));

    $ids = collect(
        test()->actingAs($a, 'user')
            ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $c, 'filtro' => 'historial']))
            ->assertOk()
            ->json('pedidos')
    )->pluck('id')->all();

    expect($ids)->toContain($deA->id)->not->toContain($deB->id);
});

it('no deja cobrar, avanzar ni editar el pedido de otro distribuidor', function () {
    ['ciudad' => $c, 'a' => $a, 'b' => $b] = aisladoEscenario();

    $deB = aisladoPedidoDelAgente($c, aisladoCliente($b->id));

    test()->actingAs($a, 'user')
        ->postJson(route('admin.distribuidor.pedido.cobro', $deB->id), ['method' => 'efectivo'])
        ->assertForbidden();

    test()->actingAs($a, 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', $deB->id), ['etapa' => 'entregado'])
        ->assertForbidden();

    // Con un pedido VÁLIDO: con uno inválido la validación responde 422 antes de
    // llegar al control, y el test pasaría sin probar nada.
    $producto = (int) DB::table('products')->value('id');

    test()->actingAs($a, 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $deB->id), [
            'productos' => [['product_id' => $producto, 'quantity' => 1, 'price' => 1]],
        ])
        ->assertForbidden();

    expect(DB::table('distribuidor_pagos')->where('lead_id', $deB->id)->exists())->toBeFalse();
});

it('buscar el teléfono de un cliente ajeno avisa con quién está y no devuelve sus datos', function () {
    ['a' => $a, 'b' => $b] = aisladoEscenario();

    $telefono = aisladoTelefono();
    aisladoCliente($b->id, $telefono);

    $r = test()->actingAs($a, 'user')
        ->getJson(route('admin.distribuidor.contacto.buscar', ['telefono' => $telefono]))
        ->assertOk();

    expect($r->json('ajeno'))->toBeTrue()
        ->and($r->json('persona'))->toBeNull()
        ->and($r->json('aviso'))->toContain($b->name);
});

it('no deja registrar como propio el teléfono de un cliente ajeno', function () {
    ['a' => $a, 'b' => $b] = aisladoEscenario();

    $telefono = aisladoTelefono();
    $cliente = aisladoCliente($b->id, $telefono);

    test()->actingAs($a, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), ['telefono' => $telefono, 'nombre' => 'Otro nombre'])
        ->assertStatus(409)
        ->assertJsonPath('ajeno', true);

    // La ficha sigue siendo de B.
    expect((int) DB::table('persons')->where('id', $cliente)->value('user_id'))->toBe($b->id);
});

it('no deja tocar la ficha de un cliente ajeno', function () {
    ['a' => $a, 'b' => $b] = aisladoEscenario();

    $cliente = aisladoCliente($b->id);

    test()->actingAs($a, 'user')
        ->patchJson(route('admin.distribuidor.cliente.update', $cliente), ['nombre' => 'Pisado', 'telefono' => aisladoTelefono()])
        ->assertForbidden();

    test()->actingAs($a, 'user')
        ->postJson(route('admin.distribuidor.cliente.bidones', $cliente), ['entregados' => 1, 'retirados' => 0])
        ->assertForbidden();

    test()->actingAs($a, 'user')
        ->patchJson(route('admin.distribuidor.cliente.recordatorio', $cliente), ['activo' => false])
        ->assertForbidden();

    expect(DB::table('persons')->where('id', $cliente)->value('name'))->not->toBe('Pisado');
});

it('la agenda del Punto lista sólo los clientes propios', function () {
    ['ciudad' => $c, 'a' => $a, 'b' => $b] = aisladoEscenario();

    $deA = aisladoCliente($a->id);
    $deB = aisladoCliente($b->id);
    aisladoPedidoDelAgente($c, $deA);
    aisladoPedidoDelAgente($c, $deB);

    $ids = collect(
        test()->actingAs($a, 'user')
            ->getJson(route('admin.distribuidor.clientes', ['ciudad' => $c]))
            ->assertOk()
            ->json('clientes')
    )->pluck('person_id')->all();

    expect($ids)->toContain($deA)->not->toContain($deB);
});

// ───────────────── contactos ─────────────────

it('la búsqueda de contactos encuentra sólo los clientes propios', function () {
    ['a' => $a, 'b' => $b] = aisladoEscenario();

    $deA = aisladoCliente($a->id);
    $deB = aisladoCliente($b->id);

    $ids = collect(
        test()->actingAs($a, 'user')
            ->getJson(route('admin.contacts.persons.search', ['search' => 'Cliente aislado', 'searchFields' => 'name:like']))
            ->assertOk()
            ->json('data')
    )->pluck('id')->all();

    expect($ids)->toContain($deA)->not->toContain($deB);
});

// ───────────────── WhatsApp ─────────────────

it('la bandeja muestra sólo las conversaciones de los clientes propios, sin el pool', function () {
    ['a' => $a, 'b' => $b] = aisladoEscenario();

    $deA = aisladoConversacion(aisladoCliente($a->id));
    $deB = aisladoConversacion(aisladoCliente($b->id));
    $pool = aisladoConversacion(null);

    $ids = collect(
        test()->actingAs($a, 'user')
            ->getJson(route('admin.whatsapp.conversations'))
            ->assertOk()
            ->json('data')
    )->pluck('id')->all();

    expect($ids)->toContain($deA)->not->toContain($deB)->not->toContain($pool);
});

it('no deja leer ni escribir la conversación de un cliente ajeno', function () {
    ['a' => $a, 'b' => $b] = aisladoEscenario();

    $deB = aisladoConversacion(aisladoCliente($b->id));

    test()->actingAs($a, 'user')
        ->getJson(route('admin.whatsapp.thread', ['conversation_id' => $deB]))
        ->assertForbidden();

    test()->actingAs($a, 'user')
        ->postJson(route('admin.whatsapp.send'), ['conversation_id' => $deB, 'body' => 'hola'])
        ->assertForbidden();
});

it('no deja abrir la ficha de WhatsApp de un cliente ajeno', function () {
    ['a' => $a, 'b' => $b] = aisladoEscenario();

    test()->actingAs($a, 'user')
        ->getJson(route('admin.whatsapp.person', aisladoCliente($b->id)))
        ->assertForbidden();
});

it('la derivación del bot va al distribuidor dueño del cliente', function () {
    ['b' => $b] = aisladoEscenario();

    $conv = ConversationProxy::modelClass()::find(aisladoConversacion(aisladoCliente($b->id)));

    $r = app(Handoff::class)->request($conv, 'Quiere hablar con una persona');

    expect((int) $r['conversation']->assigned_user_id)->toBe($b->id);
});

// ───────────────── cambios de dueño ─────────────────

it('reasignar un cliente mueve sus pedidos abiertos y su derivación, no los cerrados', function () {
    ['ciudad' => $c, 'a' => $a, 'b' => $b] = aisladoEscenario();

    $cliente = aisladoCliente($a->id);
    $abierto = aisladoPedidoDelAgente($c, $cliente);
    $cerrado = aisladoPedidoDelAgente($c, $cliente);
    DB::table('leads')->where('id', $cerrado->id)
        ->update(['lead_pipeline_stage_id' => aisladoEtapa($c, 'pedido-entregado')]);

    $conv = aisladoConversacion($cliente);
    DB::table('whatsapp_conversations')->where('id', $conv)
        ->update(['handoff_state' => 'assigned', 'assigned_user_id' => $a->id]);

    app(Reparto::class)->asignarCliente($cliente, $b->id);

    expect((int) DB::table('persons')->where('id', $cliente)->value('user_id'))->toBe($b->id)
        ->and((int) DB::table('leads')->where('id', $abierto->id)->value('user_id'))->toBe($b->id)
        ->and((int) DB::table('leads')->where('id', $cerrado->id)->value('user_id'))->toBe($a->id)
        ->and((int) DB::table('whatsapp_conversations')->where('id', $conv)->value('assigned_user_id'))->toBe($b->id);
});

it('dar de baja a un distribuidor pasa sus pedidos abiertos al responsable', function () {
    ['ciudad' => $c, 'a' => $a, 'responsable' => $r] = aisladoEscenario();

    $cliente = aisladoCliente($a->id);
    $pedido = aisladoPedidoDelAgente($c, $cliente);

    $a->forceFill(['status' => 0])->save();
    event('settings.user.update.after', $a);

    expect(DB::table('persons')->where('id', $cliente)->value('user_id'))->toBeNull()
        ->and((int) DB::table('leads')->where('id', $pedido->id)->value('user_id'))->toBe($r->id);
});

// ───────────────── lo que no cambia ─────────────────

it('un encargado sigue viendo los pedidos de toda la ciudad', function () {
    ['ciudad' => $c, 'a' => $a, 'b' => $b] = aisladoEscenario();

    $deA = aisladoPedidoDelAgente($c, aisladoCliente($a->id));
    $deB = aisladoPedidoDelAgente($c, aisladoCliente($b->id));

    $encargado = User::whereHas('role', fn ($q) => $q->where('name', 'Encargado'))->where('status', 1)->first()
        ?? test()->markTestSkipped('No hay un Encargado activo.');

    $ids = collect(
        test()->actingAs($encargado, 'user')
            ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $c, 'filtro' => 'historial']))
            ->assertOk()
            ->json('pedidos')
    )->pluck('id')->all();

    expect($ids)->toContain($deA->id)->toContain($deB->id);
});
