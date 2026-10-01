<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Distribuidor\Services\Catalogo;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/*
 * Punto de Distribuidor.
 *
 * La suite corre contra la base REAL (phpunit.xml no sobreescribe DB_*), así que
 * todo va dentro de una transacción y nada se fija a datos de kohlberg: las
 * ciudades y sucursales se toman por índice de lo que la instalación tenga.
 */
uses(DatabaseTransactions::class);

/** Un usuario con el rol Distribuidor recién creado por la migración. */
function distribuidor(): User
{
    $rol = Role::where('name', 'Distribuidor')->firstOrFail();

    return User::create([
        'name'     => 'Distribuidor test '.uniqid(),
        'email'    => 'dist'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => $rol->id,
        'status'   => 1,
    ]);
}

/** Un usuario sin el permiso del Punto. */
function sinPermiso(): User
{
    $rol = Role::create([
        'name'            => 'Sin punto '.uniqid(),
        'permission_type' => 'custom',
        'permissions'     => ['dashboard'],
    ]);

    return User::create([
        'name'     => 'Ajeno '.uniqid(),
        'email'    => 'ajeno'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => $rol->id,
        'status'   => 1,
    ]);
}

function primeraSucursal(): array
{
    $sucursales = app(Catalogo::class)->sucursales();

    if (! $sucursales) {
        test()->markTestSkipped('La instalación no tiene sucursales cargadas.');
    }

    return $sucursales[0];
}

// ---------------------------------------------------------------------------
// Acceso
// ---------------------------------------------------------------------------

it('deja entrar al Punto a quien tiene el permiso', function () {
    test()->actingAs(distribuidor(), 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertOk();
});

it('niega el Punto a quien no tiene el permiso, por las dos capas', function () {
    $ajeno = sinPermiso();

    /*
     * Dos códigos distintos a propósito, porque son dos capas distintas:
     *
     * - `admin.distribuidor.index` está en el mapa del ACL (acl.php declara su
     *   `route`), así que lo niega el Bouncer de Krayin con 401 —su convención—
     *   antes de que corra el middleware del módulo.
     *
     * - Las rutas que ESCRIBEN no están en ese mapa: el Bouncer las deja pasar
     *   y las corta EnsureDistribuidorAccess con 403.
     *
     * Este test existe por la segunda: sin el middleware propio, las rutas de
     * escritura quedaban abiertas a cualquier usuario con sesión, porque el
     * Bouncer sólo valida lo que está en su mapa.
     */
    test()->actingAs($ajeno, 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertStatus(401);

    test()->actingAs($ajeno, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [])
        ->assertForbidden();

    test()->actingAs($ajeno, 'user')
        ->postJson(route('admin.distribuidor.pedido.cobro', 1), [])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// El catálogo por sucursal: el precio depende de dónde estás parado
// ---------------------------------------------------------------------------

it('el catálogo de una sucursal trae el precio de ESA sucursal', function () {
    $sucursal = primeraSucursal();

    $productos = test()->actingAs(distribuidor(), 'user')
        ->getJson(route('admin.distribuidor.productos', ['sucursal' => $sucursal['id']]))
        ->assertOk()
        ->json('productos');

    expect($productos)->not->toBeEmpty();

    /*
     * El precio que devuelve el Punto es el de la fila de `products` habilitada
     * para esa sucursal. Es lo que impide cobrar el precio de otra ciudad: hay
     * dos productos con el mismo nombre y distinto precio según la sucursal.
     */
    foreach ($productos as $p) {
        $real = (float) DB::table('products')->where('id', $p['id'])->value('price');

        expect((float) $p['price'])->toBe($real);
    }
});

it('la ciudad de una sucursal se resuelve por nombre, porque los ids se contradicen', function () {
    $catalogo = app(Catalogo::class);

    foreach ($catalogo->sucursales() as $sucursal) {
        if ($sucursal['ciudad_id'] === null) {
            continue;
        }

        $ciudad = DB::table('lead_pipelines')->where('id', $sucursal['ciudad_id'])->value('name');

        // Mismo nombre, aunque el id no coincida: warehouse 1 es "Barrio Alto
        // Comedero" y pipeline 1 es "Palpalá".
        expect(mb_strtolower(trim($ciudad)))->toBe(mb_strtolower(trim($sucursal['name'])));
    }
});

// ---------------------------------------------------------------------------
// Tomar un pedido
// ---------------------------------------------------------------------------

it('abre el pedido en la etapa inicial de la ciudad de la sucursal', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente punto '.uniqid(),
        ])->assertOk()->json('persona');

    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [[
                'product_id' => $producto['id'],
                'quantity'   => 3,
                'price'      => $producto['price'],
            ]],
        ])->assertCreated()->json('pedido');

    expect($pedido['etapa_code'])->toBe(config('distribuidor.etapas.inicial'))
        ->and($pedido['ciudad_id'])->toBe($sucursal['ciudad_id'])
        // (float): el JSON devuelve 42000 y no 42000.0 cuando no hay decimales.
        ->and((float) $pedido['total'])->toBe(round($producto['price'] * 3, 2))
        ->and((float) $pedido['saldo'])->toBe(round($producto['price'] * 3, 2));

    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('rechaza cantidades fraccionarias: los productos no se parten', function () {
    $sucursal = primeraSucursal();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    /*
     * `lead_products.quantity` es int: una cantidad decimal se truncaba en
     * silencio y el total quedaba calculado sobre un número que no se entregó.
     */
    test()->actingAs(distribuidor(), 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => 1,
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 2.5, 'price' => 100]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('productos.0.quantity');
});

it('acepta un precio distinto al de lista: el distribuidor negocia', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente precio '.uniqid(),
        ])->json('persona');

    $acordado = round($producto['price'] * 0.9, 2);

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => $acordado]],
        ])->assertCreated()->json('pedido');

    expect((float) $pedido['total'])->toBe($acordado);

    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

// ---------------------------------------------------------------------------
// Contactos: no duplicar al mismo cliente
// ---------------------------------------------------------------------------

it('encuentra al contacto aunque el número esté guardado con otro formato', function () {
    $user = distribuidor();

    $telefono = '549388'.random_int(1000000, 9999999);

    $creado = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => $telefono,
            'nombre'   => 'Cliente dedup '.uniqid(),
        ])->json('persona');

    // El mismo número escrito como lo escribiría otra persona.
    $encontrado = test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.contacto.buscar', ['telefono' => '+'.$telefono]))
        ->assertOk();

    expect($encontrado->json('encontrado'))->toBeTrue()
        ->and($encontrado->json('persona.id'))->toBe($creado['id']);

    DB::table('persons')->where('id', $creado['id'])->delete();
});

// ---------------------------------------------------------------------------
// Tipo de cliente: se deduce, no se fija
// ---------------------------------------------------------------------------

it('marca Cliente Antiguo en la segunda compra del mismo cliente', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente recurrente '.uniqid(),
        ])->json('persona');

    $abrir = fn () => test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 100]],
        ])->assertCreated()->json('pedido.id');

    $primero = $abrir();
    $segundo = $abrir();

    /*
     * El Punto fijaba siempre "Nuevo Cliente", así que la segunda compra volvía a
     * contar al mismo cliente como nuevo y el reporte de nuevos vs recurrentes
     * salía inservible.
     */
    expect((int) DB::table('leads')->where('id', $primero)->value('lead_type_id'))
        ->toBe((int) config('distribuidor.tipo_nuevo'))
        ->and((int) DB::table('leads')->where('id', $segundo)->value('lead_type_id'))
        ->toBe((int) config('distribuidor.tipo_antiguo'));

    DB::table('leads')->whereIn('id', [$primero, $segundo])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

// ---------------------------------------------------------------------------
// Entrega: la foto es obligatoria
// ---------------------------------------------------------------------------

it('no deja marcar entregado sin la foto', function () {
    test()->actingAs(distribuidor(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', 1), ['etapa' => 'entregado'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('foto');
});

it('guarda la foto, la fecha y quién entregó', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente entrega '.uniqid(),
        ])->json('persona');

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 100]],
        ])->json('pedido');

    $r = test()->actingAs($user, 'user')->patch(
        route('admin.distribuidor.pedido.etapa', $pedido['id']),
        [
            'etapa' => 'entregado',
            'foto'  => \Illuminate\Http\UploadedFile::fake()->image('entrega.jpg', 400, 300),
        ],
    )->assertOk();

    expect($r->json('pedido.etapa_code'))->toBe(config('distribuidor.etapas.entregado'))
        ->and($r->json('pedido.entregado_at'))->not->toBeNull()
        ->and($r->json('pedido.entregado_por'))->toBe($user->name)
        ->and($r->json('pedido.entrega_foto'))->not->toBeNull();

    $entrega = DB::table('distribuidor_entregas')->where('lead_id', $pedido['id'])->first();

    expect($entrega->foto_path)->toContain('distribuidor/entregas');

    \Illuminate\Support\Facades\Storage::disk('public')->delete($entrega->foto_path);
    DB::table('distribuidor_entregas')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

// ---------------------------------------------------------------------------
// Alcance por ciudad
// ---------------------------------------------------------------------------

it('sin ciudades asignadas ve todas', function () {
    $user = distribuidor();

    test()->actingAs($user, 'user');

    expect(app(\Webkul\Distribuidor\Services\Alcance::class)->ciudadesPermitidas($user->id))->toBeNull();
});

it('con ciudades asignadas sólo ve esas, y le corta el acceso a las demás', function () {
    $user = distribuidor();

    /*
     * Se autentica ANTES de leer las ciudades: `Alcance` resuelve el alcance del
     * usuario de la sesión, y sin sesión devuelve [] —deniega, que es la dirección
     * segura— así que preguntarle antes daría una lista vacía.
     */
    test()->actingAs($user, 'user');

    $ciudades = app(\Webkul\Distribuidor\Services\Alcance::class)->ciudades();

    if (count($ciudades) < 2) {
        test()->markTestSkipped('Hacen falta al menos dos ciudades para probar el alcance.');
    }

    [$suya, $ajena] = [$ciudades[0], $ciudades[1]];

    DB::table('distribuidor_ciudades')->insert([
        'user_id'    => $user->id, 'lead_pipeline_id' => $suya['id'],
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $alcance = app(\Webkul\Distribuidor\Services\Alcance::class);

    expect($alcance->ciudadesPermitidas())->toBe([$suya['id']])
        ->and($alcance->puedeVerCiudad($suya['id']))->toBeTrue()
        ->and($alcance->puedeVerCiudad($ajena['id']))->toBeFalse();

    // Y el servidor lo hace cumplir: filtrar sólo en la UI dejaría los pedidos
    // de otra ciudad a un request de distancia.
    test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $ajena['id']]))
        ->assertForbidden();

    test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $suya['id']]))
        ->assertOk();
});
