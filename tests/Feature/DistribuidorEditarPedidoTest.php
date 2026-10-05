<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Distribuidor\Services\Catalogo;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/**
 * Editar los productos de un pedido, con registro.
 *
 * El negocio lo permite incluso sobre un pedido ya cobrado y entregado, con una sola
 * condición: que quede rastro. El caso real es el agente de WhatsApp, que arma pedidos
 * solo: la corrección aparece cuando el distribuidor llega a la puerta y el cliente le
 * dice que pidió otra cosa. Negarlo ahí obliga a cancelar y recargar, que pierde el
 * historial y el cobro.
 */
uses(DatabaseTransactions::class);

function distribuidorQueEdita(): User
{
    $rol = Role::where('name', 'Distribuidor')->firstOrFail();

    return User::create([
        'name'     => 'Distribuidor edita '.uniqid(),
        'email'    => 'edita'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => $rol->id,
        'status'   => 1,
    ]);
}

/** Un pedido con dos líneas, en una ciudad con catálogo. */
function pedidoConLineas(): array
{
    $sucursales = app(Catalogo::class)->sucursales();
    $sucursal = collect($sucursales)->first(fn ($s) => $s['ciudad_id'] !== null);

    if (! $sucursal) {
        test()->markTestSkipped('Ninguna sucursal resuelve su ciudad.');
    }

    $productos = app(Catalogo::class)->productosDe($sucursal['id']);

    if (count($productos) < 2) {
        test()->markTestSkipped('La sucursal no tiene dos productos para editar.');
    }

    $personId = (int) DB::table('persons')->insertGetId([
        'name'       => 'Cliente edita '.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lead = app(\Webkul\Distribuidor\Services\PuntoPedido::class)->crear(
        $personId,
        (int) $sucursal['ciudad_id'],
        [
            ['product_id' => $productos[0]['id'], 'quantity' => 4, 'price' => 1000.0],
            ['product_id' => $productos[1]['id'], 'quantity' => 2, 'price' => 500.0],
        ],
        null,
    );

    return [
        'lead_id'   => (int) $lead->id,
        'person_id' => $personId,
        'productos' => $productos,
    ];
}

/** El texto de la última actividad del lead. */
function ultimoRegistroDe(int $leadId): ?string
{
    return DB::table('activities')
        ->join('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
        ->where('lead_activities.lead_id', $leadId)
        ->orderByDesc('activities.id')
        ->value('activities.comment');
}

it('cambia las cantidades y recalcula el total', function () {
    $p = pedidoConLineas();

    // 4 × 1000 + 2 × 500 = 5000
    expect(round((float) DB::table('leads')->where('id', $p['lead_id'])->value('lead_value'), 2))->toBe(5000.0);

    $r = $this->actingAs(distribuidorQueEdita(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), [
            'productos' => [
                ['product_id' => $p['productos'][0]['id'], 'quantity' => 3, 'price' => 1000.0],
            ],
        ])
        ->assertOk()
        ->json('pedido');

    expect((float) $r['total'])->toBe(3000.0)
        ->and($r['productos'])->toHaveCount(1);
});

it('deja registro de la edicion en la linea de tiempo del pedido', function () {
    $p = pedidoConLineas();

    $this->actingAs(distribuidorQueEdita(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), [
            'productos' => [
                ['product_id' => $p['productos'][0]['id'], 'quantity' => 3, 'price' => 1000.0],
            ],
        ])
        ->assertOk();

    $registro = ultimoRegistroDe($p['lead_id']);

    /*
     * El registro es la condición que puso el negocio para permitir editar. Sin el total
     * viejo no se puede auditar un cobro que ya no coincide.
     */
    expect($registro)->not->toBeNull()
        ->and($registro)->toContain('Pedido editado')
        ->and($registro)->toContain('5.000')   // el total de antes
        ->and($registro)->toContain('3.000');  // el de después
});

it('el registro nombra lo que se quito y lo que cambio', function () {
    $p = pedidoConLineas();
    $nombres = [$p['productos'][0]['name'], $p['productos'][1]['name']];

    $this->actingAs(distribuidorQueEdita(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), [
            'productos' => [
                ['product_id' => $p['productos'][0]['id'], 'quantity' => 1, 'price' => 1000.0],
            ],
        ])
        ->assertOk();

    $registro = ultimoRegistroDe($p['lead_id']);

    // Por NOMBRE y no por id: el registro se lee dentro de un año, cuando ese producto
    // quizás ya no esté en el catálogo y un id suelto no le diga nada a nadie.
    expect($registro)->toContain($nombres[0])
        ->and($registro)->toContain($nombres[1])
        ->and($registro)->toContain('quitado');
});

it('avisa en el registro cuando se cobro mas de lo que el pedido vale ahora', function () {
    $p = pedidoConLineas();
    $user = distribuidorQueEdita();

    // Se cobra el total completo (el Punto cobra siempre al contado, por el total).
    $this->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.cobro', $p['lead_id']), ['method' => 'efectivo'])
        ->assertOk();

    // Y después se edita a la baja.
    $this->actingAs($user, 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), [
            'productos' => [
                ['product_id' => $p['productos'][0]['id'], 'quantity' => 1, 'price' => 1000.0],
            ],
        ])
        ->assertOk();

    /*
     * Es el dato que convierte el registro en algo accionable: alguien tiene que
     * devolver la diferencia, y si no está escrito acá no se entera nadie.
     */
    expect(ultimoRegistroDe($p['lead_id']))->toContain('hay que devolver');
});

it('el saldo queda negativo y el pedido lo expone', function () {
    $p = pedidoConLineas();
    $user = distribuidorQueEdita();

    $this->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.cobro', $p['lead_id']), ['method' => 'efectivo'])
        ->assertOk();

    $pedido = $this->actingAs($user, 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), [
            'productos' => [
                ['product_id' => $p['productos'][0]['id'], 'quantity' => 1, 'price' => 1000.0],
            ],
        ])
        ->assertOk()
        ->json('pedido');

    // Cobró 5000, ahora vale 1000: 4000 a devolver.
    // Casteado: el JSON devuelve 5000 entero cuando el float no tiene decimales, y
    // toBe() es estricto con el tipo.
    expect((float) $pedido['cobrado'])->toBe(5000.0)
        ->and((float) $pedido['total'])->toBe(1000.0)
        ->and((float) $pedido['saldo'])->toBe(-4000.0);
});

it('no deja editar un pedido cancelado', function () {
    $p = pedidoConLineas();
    $user = distribuidorQueEdita();

    $this->actingAs($user, 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', $p['lead_id']), ['etapa' => 'cancelado'])
        ->assertOk();

    // No hay nada que entregar: editarlo lo dejaría con líneas nuevas y sin destino.
    $this->actingAs($user, 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), [
            'productos' => [['product_id' => $p['productos'][0]['id'], 'quantity' => 1, 'price' => 100.0]],
        ])
        ->assertStatus(422);
});

it('no deja editar un pedido de otra ciudad', function () {
    $p = pedidoConLineas();
    $pipelines = DB::table('lead_pipelines')->orderBy('id')->pluck('id')->all();
    $ciudadDelPedido = (int) DB::table('leads')->where('id', $p['lead_id'])->value('lead_pipeline_id');
    $otra = collect($pipelines)->first(fn ($id) => (int) $id !== $ciudadDelPedido);

    if (! $otra) {
        $this->markTestSkipped('Hace falta una segunda ciudad.');
    }

    $user = distribuidorQueEdita();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);
    $nombre = (string) DB::table('lead_pipelines')->where('id', $otra)->value('name');
    $groupId = (int) (DB::table('groups')->where('name', $nombre)->value('id')
        ?: DB::table('groups')->insertGetId(['name' => $nombre, 'description' => 'Temporal para tests']));
    DB::table('user_groups')->insert(['user_id' => $user->id, 'group_id' => $groupId]);

    $this->actingAs($user->refresh(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), [
            'productos' => [['product_id' => $p['productos'][0]['id'], 'quantity' => 1, 'price' => 100.0]],
        ])
        ->assertStatus(403);
});

it('rechaza un pedido sin lineas', function () {
    $p = pedidoConLineas();

    // Un pedido sin productos no es un pedido: para eso está cancelar.
    $this->actingAs(distribuidorQueEdita(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.productos', $p['lead_id']), ['productos' => []])
        ->assertStatus(422);
});
