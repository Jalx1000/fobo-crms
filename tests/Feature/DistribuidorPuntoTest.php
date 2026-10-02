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

    // Nace confirmado: en el Punto lo carga alguien con el cliente delante.
    expect($pedido['etapa_code'])->toBe(config('distribuidor.etapas.al_crear'))
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

// ---------------------------------------------------------------------------
// La plantilla del componente
// ---------------------------------------------------------------------------

it('la plantilla de Vue está completa y sin condicionales huérfanos', function () {
    /*
     * Un `v-else-if` sin su `v-if` delante es error de compilación de plantilla
     * en Vue 3, y no rompe sólo esa burbuja: tumba el componente entero y la
     * pantalla queda en blanco. El servidor devuelve 200 igual —el error ocurre
     * en el navegador— así que un assertOk() no lo detecta.
     *
     * Pasó: al sacar la fila de chips de ciudad se consumió de más el
     * `<div v-if="cargando">` que venía justo después.
     */
    $html = test()->actingAs(distribuidor(), 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertOk()
        ->getContent();

    $i = strpos($html, 'id="v-punto-template"');
    $tpl = substr($html, $i, strpos($html, '</script>', $i) - $i);

    // Cada v-else/v-else-if tiene que venir después de un v-if.
    $sinIf = [];
    $previa = false;

    foreach (explode("\n", $tpl) as $n => $linea) {
        if (str_contains($linea, 'v-if=')) {
            $previa = true;

            continue;
        }

        if (preg_match('/\bv-else(-if)?[=>\s]/', $linea)) {
            if (! $previa) {
                $sinIf[] = ($n + 1).': '.trim($linea);
            }

            continue;
        }

        if (preg_match('/<(div|template|span|button|a)\b/', $linea)) {
            $previa = false;
        }
    }

    expect($sinIf)->toBe([], "v-else sin v-if:\n".implode("\n", $sinIf));

    // Y las etiquetas cierran: un corte de más desbalancea el árbol.
    foreach (['div', 'button', 'section', 'label'] as $tag) {
        $abre = preg_match_all('/<'.$tag.'[\s>]/', $tpl);
        $cierra = preg_match_all('#</'.$tag.'>#', $tpl);

        expect($abre)->toBe($cierra, "<$tag> desbalanceado: $abre abre, $cierra cierra");
    }

    // Los estados que el usuario tiene que poder ver.
    foreach (['Cargando…', 'Dónde estás trabajando', 'Abrir pedido', 'Confirmar entrega'] as $marca) {
        expect($tpl)->toContain($marca);
    }
});

// ---------------------------------------------------------------------------
// Atajos: recientes, repetir, caja
// ---------------------------------------------------------------------------

it('los recientes no incluyen contactos sin teléfono', function () {
    $recientes = test()->actingAs(distribuidor(), 'user')
        ->getJson(route('admin.distribuidor.recientes'))
        ->assertOk()
        ->json('recientes');

    /*
     * Todo el Punto identifica al cliente por su número: una tarjeta sin teléfono
     * no se puede usar y sólo ocupa un lugar en una fila de ocho.
     */
    foreach ($recientes as $r) {
        expect($r['telefono'])->not->toBeNull()
            ->and(trim($r['telefono']))->not->toBe('');
    }
});

it('un cliente aparece una sola vez en recientes, aunque haya comprado varias', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente repetidor '.uniqid(),
        ])->json('persona');

    $ids = [];

    foreach (range(1, 3) as $i) {
        $ids[] = test()->actingAs($user, 'user')
            ->postJson(route('admin.distribuidor.pedido.store'), [
                'person_id' => $contacto['id'],
                'ciudad_id' => $sucursal['ciudad_id'],
                'productos' => [['product_id' => $producto['id'], 'quantity' => $i, 'price' => 100]],
            ])->assertCreated()->json('pedido.id');
    }

    $recientes = test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.recientes'))
        ->json('recientes');

    // Sin el groupBy, quien compró tres veces llenaría la fila y taparía al resto.
    $apariciones = collect($recientes)->where('id', $contacto['id'])->count();

    expect($apariciones)->toBe(1)
        // Y apunta al último, no al primero.
        ->and(collect($recientes)->firstWhere('id', $contacto['id'])['ultimo_pedido_id'])->toBe(max($ids));

    DB::table('lead_products')->whereIn('lead_id', $ids)->delete();
    DB::table('leads')->whereIn('id', $ids)->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('repetir trae las cantidades del pedido anterior pero los precios de hoy', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente a repetir '.uniqid(),
        ])->json('persona');

    // Se vendió con un precio negociado, distinto al de lista.
    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 4, 'price' => 1]],
        ])->assertCreated()->json('pedido');

    $lineas = test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.repetir', $contacto['id']).'?sucursal='.$sucursal['id'])
        ->assertOk()
        ->json('lineas');

    expect($lineas)->toHaveCount(1)
        ->and($lineas[0]['product_id'])->toBe($producto['id'])
        // La cantidad se repite...
        ->and($lineas[0]['quantity'])->toBe(4)
        /*
         * ...pero el precio sale del catálogo de HOY, no del que se cobró. Si la
         * lista subió, repetir al precio viejo vendería a pérdida sin que nadie lo
         * note. Lo que se repite es QUÉ pidió, no a cuánto.
         */
        ->and((float) $lineas[0]['price'])->toBe($producto['price']);

    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('la caja suma sólo los cobros de hoy del propio distribuidor', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $mio = distribuidor();
    $otro = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $abrir = function ($user) use ($sucursal, $producto) {
        $contacto = test()->actingAs($user, 'user')
            ->postJson(route('admin.distribuidor.contacto.store'), [
                'telefono' => '549388'.random_int(1000000, 9999999),
                'nombre'   => 'Cliente caja '.uniqid(),
            ])->json('persona');

        $pedido = test()->actingAs($user, 'user')
            ->postJson(route('admin.distribuidor.pedido.store'), [
                'person_id' => $contacto['id'],
                'ciudad_id' => $sucursal['ciudad_id'],
                'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 1000]],
            ])->assertCreated()->json('pedido');

        return [$contacto['id'], $pedido['id']];
    };

    [$pMio, $lMio] = $abrir($mio);
    [$pOtro, $lOtro] = $abrir($otro);

    /*
     * Al contado: un solo cobro por el total del pedido. El importe lo calcula el
     * servidor, así que el cliente sólo elige la forma de pago.
     */
    test()->actingAs($mio, 'user')->postJson(route('admin.distribuidor.pedido.cobro', $lMio), [
        'method' => 'efectivo',
    ])->assertOk();

    // Ya cobrado: un segundo intento no suma nada.
    test()->actingAs($mio, 'user')->postJson(route('admin.distribuidor.pedido.cobro', $lMio), [
        'method' => 'qr',
    ])->assertStatus(422);

    // El cobro de OTRO distribuidor no puede entrar en mi caja.
    test()->actingAs($otro, 'user')->postJson(route('admin.distribuidor.pedido.cobro', $lOtro), [
        'method' => 'efectivo',
    ])->assertOk();

    $caja = test()->actingAs($mio, 'user')->getJson(route('admin.distribuidor.caja'))->assertOk();

    // Un pedido de 1000 cobrado al contado: un cobro, el total.
    expect((float) $caja->json('total'))->toBe(1000.0)
        ->and($caja->json('cobros'))->toBe(1);

    $efectivo = collect($caja->json('por_forma'))->firstWhere('key', 'efectivo');
    $qr = collect($caja->json('por_forma'))->firstWhere('key', 'qr');

    // Lo que se rinde en mano es sólo la parte en efectivo.
    expect((float) $efectivo['total'])->toBe(1000.0)
        ->and((float) $qr['total'])->toBe(0.0);

    DB::table('distribuidor_pagos')->whereIn('lead_id', [$lMio, $lOtro])->delete();
    DB::table('lead_products')->whereIn('lead_id', [$lMio, $lOtro])->delete();
    DB::table('leads')->whereIn('id', [$lMio, $lOtro])->delete();
    DB::table('persons')->whereIn('id', [$pMio, $pOtro])->delete();
});

// ---------------------------------------------------------------------------
// Ubicación del pedido
// ---------------------------------------------------------------------------

it('recupera las coordenadas que n8n guardó sin punto decimal ni signo', function () {
    $u = app(\Webkul\Distribuidor\Services\Ubicacion::class);

    /*
     * El formato real encontrado en producción: n8n escribe el atributo
     * `ubicacion_lead` y guardó "latitude24193000longitude65297000". El punto
     * decimal y el menos se pierden, y las etiquetas quedan pegadas al número, así
     * que el valor no se abre en ningún mapa.
     */
    expect($u->coordenadasEn('Av. Bolivia 2335 | latitude24193000longitude65297000'))
        ->toBe([-24.193, -65.297]);
});

it('descarta coordenadas fuera de la zona de reparto', function () {
    $u = app(\Webkul\Distribuidor\Services\Ubicacion::class);

    /*
     * Se reparte sólo en Jujuy. El pedido 91 de producción tenía coordenadas de
     * Santa Cruz de la Sierra (-17.75, -63.19), probablemente datos de prueba de
     * kohlberg: un pin ahí no es una dirección entregable, así que se descarta y el
     * distribuidor se guía por la dirección escrita.
     *
     * Acotar a la provincia —y no al país— es además lo que hace confiable la
     * reconstrucción: con un recuadro chico, un parseo que salió mal cae afuera.
     */
    expect($u->coordenadasEn('latitude17754821longitude631973421'))->toBeNull()
        // Buenos Aires: Argentina, pero no es zona de reparto.
        ->and($u->coordenadasEn('-34.603722,-58.381592'))->toBeNull();
});

it('lee también el formato sano de coordenadas', function () {
    $u = app(\Webkul\Distribuidor\Services\Ubicacion::class);

    expect($u->coordenadasEn('Portón verde | -24.193000,-65.297000'))
        ->toBe([-24.193, -65.297]);
});

it('devuelve null antes que un pin equivocado', function () {
    $u = app(\Webkul\Distribuidor\Services\Ubicacion::class);

    /*
     * La reconstrucción asume dos dígitos de parte entera y signo negativo, que
     * vale para Argentina y Bolivia pero no es una verdad universal. Si el
     * resultado cae fuera del cono sur es un parseo fallido: mandar al
     * distribuidor a otro continente es peor que no darle pin.
     */
    expect($u->coordenadasEn('latitude99999999longitude99999999'))->toBeNull()
        ->and($u->coordenadasEn('Portón verde y un árbol afuera'))->toBeNull()
        ->and($u->coordenadasEn(null))->toBeNull()
        // Madrid: sintaxis válida, región equivocada.
        ->and($u->coordenadasEn('40.416775,-3.703790'))->toBeNull();
});

it('separa la referencia escrita de las coordenadas', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente ubicación '.uniqid(),
        ])->json('persona');

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 100]],
        ])->assertCreated()->json('pedido');

    $attributeId = DB::table('attributes')->where('code', 'ubicacion_lead')->value('id');

    DB::table('attribute_values')->insert([
        'entity_id'    => $pedido['id'],
        'entity_type'  => 'leads',
        'attribute_id' => $attributeId,
        'text_value'   => 'Portón verde, timbre roto | latitude24193000longitude65297000',
    ]);

    $ubicacion = app(\Webkul\Distribuidor\Services\Ubicacion::class)->dePedido($pedido['id']);

    // La referencia que lee un humano va sin el ruido de los números.
    expect($ubicacion['referencia'])->toBe('Portón verde, timbre roto')
        ->and($ubicacion['lat'])->toBe(-24.193)
        ->and($ubicacion['modo'])->toBe('pin')
        ->and($ubicacion['url'])->toContain('google.com/maps');

    DB::table('attribute_values')->where('entity_id', $pedido['id'])->where('attribute_id', $attributeId)->delete();
    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('con sólo dirección escrita arma una búsqueda con la ciudad y el país', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente dirección '.uniqid(),
        ])->json('persona');

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 100]],
        ])->assertCreated()->json('pedido');

    $attributeId = DB::table('attributes')->where('code', 'ubicacion_lead')->value('id');

    DB::table('attribute_values')->insert([
        'entity_id'    => $pedido['id'],
        'entity_type'  => 'leads',
        'attribute_id' => $attributeId,
        'text_value'   => 'Calle 6 nro 234',
    ]);

    $u = app(\Webkul\Distribuidor\Services\Ubicacion::class)->dePedido($pedido['id']);

    /*
     * Sin coordenadas no se geocodifica del lado del servidor: se delega la
     * búsqueda al mapa del dispositivo, que tiene un geocodificador mejor que
     * cualquiera que escribamos y le muestra la ambigüedad al distribuidor en vez
     * de que elijamos mal por él.
     *
     * El sufijo no es opcional: "calle 6 nro 234" matchea en cualquier ciudad del
     * país y el mapa abriría en el lugar equivocado.
     */
    expect($u['modo'])->toBe('busqueda')
        ->and($u['lat'])->toBeNull()
        ->and($u['url'])->toContain('maps/search/')
        ->and(urldecode($u['url']))->toContain('Calle 6 nro 234, Jujuy, Argentina');

    DB::table('attribute_values')->where('entity_id', $pedido['id'])->where('attribute_id', $attributeId)->delete();
    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('"Para entregar" muestra sólo los confirmados, no los prospectos', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $abrir = function () use ($user, $sucursal, $producto) {
        $contacto = test()->actingAs($user, 'user')
            ->postJson(route('admin.distribuidor.contacto.store'), [
                'telefono' => '549388'.random_int(1000000, 9999999),
                'nombre'   => 'Cliente etapa '.uniqid(),
            ])->json('persona');

        $pedido = test()->actingAs($user, 'user')
            ->postJson(route('admin.distribuidor.pedido.store'), [
                'person_id' => $contacto['id'],
                'ciudad_id' => $sucursal['ciudad_id'],
                'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 100]],
            ])->assertCreated()->json('pedido');

        return [$contacto['id'], $pedido['id']];
    };

    [$pProspecto, $lProspecto] = $abrir();
    [$pConfirmado, $lConfirmado] = $abrir();

    /*
     * El Punto crea los pedidos ya confirmados, así que para tener un prospecto
     * —como los que entran por el agente de WhatsApp o la web— hay que ponerlo en
     * esa etapa a mano.
     */
    DB::table('leads')->where('id', $lProspecto)->update([
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $sucursal['ciudad_id'])
            ->where('code', config('distribuidor.etapas.inicial'))
            ->value('id'),
    ]);

    $ids = collect(
        test()->actingAs($user, 'user')
            ->getJson(route('admin.distribuidor.pedidos', [
                'filtro' => 'pendientes', 'ciudad' => $sucursal['ciudad_id'],
            ]))
            ->assertOk()
            ->json('pedidos')
    )->pluck('id');

    /*
     * Un prospecto no es trabajo de reparto: nadie lo confirmó todavía, y mezclarlo
     * con la ruta del día obliga a decidir en cada tarjeta si corresponde salir con
     * ella. El que sale a la calle quiere una lista de cosas que hay que llevar.
     */
    expect($ids)->toContain($lConfirmado)
        ->and($ids)->not->toContain($lProspecto);

    // Y el prospecto sigue siendo visible donde se lo confirma.
    $hoy = collect(
        test()->actingAs($user, 'user')
            ->getJson(route('admin.distribuidor.pedidos', [
                'filtro' => 'hoy', 'ciudad' => $sucursal['ciudad_id'],
            ]))
            ->json('pedidos')
    )->pluck('id');

    expect($hoy)->toContain($lProspecto);

    DB::table('lead_products')->whereIn('lead_id', [$lProspecto, $lConfirmado])->delete();
    DB::table('leads')->whereIn('id', [$lProspecto, $lConfirmado])->delete();
    DB::table('persons')->whereIn('id', [$pProspecto, $pConfirmado])->delete();
});

it('la tarjeta ofrece cobrar antes de entregar, no al revés', function () {
    /*
     * El orden del negocio es cobrar y después bajar la mercadería: entregar
     * primero deja al distribuidor sin respaldo si el cliente no paga.
     *
     * Se verifica sobre la plantilla porque la secuencia vive en la cadena de
     * v-else-if de la tarjeta, y un reordenamiento accidental de esos bloques
     * invertiría el flujo sin que falle nada más.
     */
    $html = test()->actingAs(distribuidor(), 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertOk()
        ->getContent();

    $i = strpos($html, 'id="v-punto-template"');
    $tpl = substr($html, $i, strpos($html, '</script>', $i) - $i);

    $cobrar = strpos($tpl, "pe.etapa_code === 'pedidos-confirmado' && pe.cobrado <= 0");
    $entregar = strpos($tpl, "pe.etapa_code === 'pedidos-confirmado' && pe.cobrado > 0");

    expect($cobrar)->not->toBeFalse('falta el paso Cobrar sobre un confirmado sin cobros')
        ->and($entregar)->not->toBeFalse('falta el paso Entregar sobre un confirmado ya cobrado')
        // Cobrar va primero en la cadena.
        ->and($cobrar)->toBeLessThan($entregar);

    // Y entregar exige un cobro previo: nunca aparece sin esa condición.
    expect($tpl)->not->toContain("v-else-if=\"pe.etapa_code === 'pedidos-confirmado'\" type=\"button\" @click=\"pedirFoto");
});

it('el pedido expone su ubicación y sus productos por el endpoint', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente payload '.uniqid(),
        ])->json('persona');

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'nota'      => 'Av. Bolivia 2335, portón azul',
            'productos' => [['product_id' => $producto['id'], 'quantity' => 2, 'price' => 500]],
        ])->assertCreated()->json('pedido');

    /*
     * Los dos los encontró un recorrido manual del flujo, no la suite: la clave
     * `ubicacion` no se había llegado a exponer, y la dirección que escribe el
     * distribuidor iba a `description` mientras el bloque "Dónde entregar" leía
     * sólo el atributo `ubicacion_lead`, así que quedaba vacío.
     */
    expect($pedido)->toHaveKey('ubicacion')
        ->and($pedido['ubicacion']['referencia'])->toBe('Av. Bolivia 2335, portón azul')
        ->and($pedido['ubicacion']['modo'])->toBe('busqueda');

    // Y qué hay que entregar: sin esto el reparto sale sin saber qué lleva.
    expect($pedido['productos'])->toHaveCount(1)
        // (float): el JSON devuelve 2 y no 2.0 cuando no hay decimales.
        ->and((float) $pedido['productos'][0]['quantity'])->toBe(2.0)
        ->and($pedido['productos'][0]['name'])->toBe($producto['name']);

    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('la tarjeta renderiza los productos del pedido', function () {
    $html = test()->actingAs(distribuidor(), 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertOk()
        ->getContent();

    $i = strpos($html, 'id="v-punto-template"');
    $tpl = substr($html, $i, strpos($html, '</script>', $i) - $i);

    // El payload traía los productos pero la plantilla no los dibujaba.
    expect($tpl)->toContain('pe.productos')
        ->and($tpl)->toContain('pr.quantity');
});

it('avanzar de etapa no borra los productos del pedido', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente etapas '.uniqid(),
        ])->json('persona');

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 2, 'price' => 500]],
        ])->assertCreated()->json('pedido');

    expect(DB::table('lead_products')->where('lead_id', $pedido['id'])->count())->toBe(1);

    /*
     * El bucle que borra los productos sobrantes de LeadRepository::update()
     * estaba fuera del `isset($data['products'])`, así que cualquier update sin
     * esa clave los borraba todos. Afectaba al Kanban (updateStage manda sólo la
     * etapa), al mass update y a la API del agente: arrastrar un pedido de
     * columna vaciaba su contenido sin error y sin rastro.
     *
     * Por eso `lead_products` tenía 0 filas para 15 pedidos.
     */
    $pedido = test()->actingAs($user, 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', $pedido['id']), ['etapa' => 'confirmado'])
        ->assertOk()->json('pedido');

    expect(DB::table('lead_products')->where('lead_id', $pedido['id'])->count())->toBe(1)
        ->and($pedido['productos'])->toHaveCount(1)
        // Y el total sigue siendo el que se cobró.
        ->and((float) $pedido['total'])->toBe(1000.0);

    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('el pedido del Punto nace confirmado, sin paso de confirmación', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente directo '.uniqid(),
        ])->json('persona');

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 100]],
        ])->assertCreated()->json('pedido');

    /*
     * En el Punto el pedido lo carga una persona con el cliente delante: ya está
     * confirmado. Arrancarlo como prospecto obligaba a cargarlo, cambiar de
     * pestaña, confirmarlo y volver.
     */
    expect($pedido['etapa_code'])->toBe('pedidos-confirmado');

    // Y por eso aparece de una en la lista de reparto, sin pasos intermedios.
    $ids = collect(
        test()->actingAs($user, 'user')
            ->getJson(route('admin.distribuidor.pedidos', [
                'filtro' => 'pendientes', 'ciudad' => $sucursal['ciudad_id'],
            ]))->assertOk()->json('pedidos')
    )->pluck('id');

    expect($ids)->toContain($pedido['id']);

    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('una sucursal no ve los pedidos de otra', function () {
    $user = distribuidor();

    test()->actingAs($user, 'user');

    $ciudades = app(\Webkul\Distribuidor\Services\Alcance::class)->ciudades();

    if (count($ciudades) < 2) {
        test()->markTestSkipped('Hacen falta dos ciudades.');
    }

    [$jujuy, $otra] = [$ciudades[0], $ciudades[1]];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente aislado '.uniqid(),
        ])->json('persona');

    $sucursalDeJujuy = collect(app(Catalogo::class)->sucursales())->firstWhere('ciudad_id', $jujuy['id']);
    $producto = app(Catalogo::class)->productosDe($sucursalDeJujuy['id'])[0];

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $jujuy['id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 1, 'price' => 100]],
        ])->assertCreated()->json('pedido');

    /*
     * Son operaciones distintas, con su catálogo y sus precios. Un pedido de otra
     * ciudad en la lista es ruido que alguien puede llegar a tocar.
     */
    $enSuCiudad = collect(test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $jujuy['id']]))->json('pedidos'))->pluck('id');

    $enLaOtra = collect(test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $otra['id']]))->json('pedidos'))->pluck('id');

    expect($enSuCiudad)->toContain($pedido['id'])
        ->and($enLaOtra)->not->toContain($pedido['id']);

    // Y sin sucursal el backend rechaza: devolvería todas las ciudades.
    test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.pedidos'))
        ->assertStatus(422);

    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});

it('los modales son accesibles por teclado y lector de pantalla', function () {
    $html = test()->actingAs(distribuidor(), 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertOk()
        ->getContent();

    $i = strpos($html, 'id="v-punto-template"');
    $tpl = substr($html, $i, strpos($html, '</script>', $i) - $i);

    /*
     * Sin role/aria-modal un lector de pantalla no anuncia que se abrió un diálogo
     * ni lo nombra, y lee el fondo como si siguiera disponible. Y sin cierre por
     * Escape, con teclado un modal es un modal del que no se sale.
     */
    // El marcado del diálogo vive en la plantilla...
    expect(substr_count($tpl, 'role="dialog"'))->toBe(2)
        ->and(substr_count($tpl, 'aria-modal="true"'))->toBe(2)
        ->and($tpl)->toContain('aria-labelledby="titulo-cobro"')
        ->and($tpl)->toContain('aria-labelledby="titulo-entrega"');

    /*
     * ...y el listener en el <script type="module">, que es otro bloque. Va en
     * window y no atado al diálogo: el foco puede estar en el overlay o en ningún
     * lado, y un handler del elemento sólo dispara si el foco está adentro.
     */
    expect($html)->toContain("e.key !== 'Escape'")
        ->and($html)->toContain("window.addEventListener('keydown'")
        // Y se desengancha, para que no se acumule entre montajes.
        ->and($html)->toContain("window.removeEventListener('keydown'");
});

it('ningún gris de texto queda sin su par para modo oscuro', function () {
    $html = test()->actingAs(distribuidor(), 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertOk()
        ->getContent();

    $i = strpos($html, 'id="v-punto-template"');
    $tpl = substr($html, $i, strpos($html, '</script>', $i) - $i);

    /*
     * gray-400 sobre blanco da ~2.8:1 y gray-500 ~4.6:1, al límite del mínimo de
     * 4.5:1. El par `text-gray-600 dark:text-gray-400` pasa en los dos modos.
     */
    preg_match_all('/class="[^"]*"/', $tpl, $clases);

    $flojas = array_values(array_filter(
        $clases[0],
        fn ($c) => preg_match('/\btext-gray-(400|500)\b/', $c) && ! str_contains($c, 'dark:text-gray'),
    ));

    expect($flojas)->toBe([], "grises sin par dark:\n".implode("\n", array_slice($flojas, 0, 5)));
});

it('el cobro es siempre por el total: el importe lo decide el servidor', function () {
    $sucursal = primeraSucursal();

    if (! $sucursal['ciudad_id']) {
        test()->markTestSkipped('La sucursal no tiene una ciudad equivalente.');
    }

    $user = distribuidor();
    $producto = app(Catalogo::class)->productosDe($sucursal['id'])[0];

    $contacto = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => '549388'.random_int(1000000, 9999999),
            'nombre'   => 'Cliente contado '.uniqid(),
        ])->json('persona');

    $pedido = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.store'), [
            'person_id' => $contacto['id'],
            'ciudad_id' => $sucursal['ciudad_id'],
            'productos' => [['product_id' => $producto['id'], 'quantity' => 3, 'price' => 2500]],
        ])->assertCreated()->json('pedido');

    /*
     * Se manda un importe distinto a propósito: el servidor lo IGNORA y cobra el
     * total. En el Punto siempre es al contado, y dejar que el navegador decida
     * la cifra era una forma de equivocarse —o de abusar— sin ninguna ventaja: el
     * precio ya es ajustable al armar el pedido.
     */
    $cobrado = test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.cobro', $pedido['id']), [
            'amount' => 1,
            'method' => 'efectivo',
        ])->assertOk()->json('pedido');

    expect((float) $cobrado['cobrado'])->toBe(7500.0)
        ->and((float) $cobrado['saldo'])->toBe(0.0);

    // Y no se puede cobrar dos veces el mismo pedido.
    test()->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.pedido.cobro', $pedido['id']), ['method' => 'efectivo'])
        ->assertStatus(422);

    DB::table('distribuidor_pagos')->where('lead_id', $pedido['id'])->delete();
    DB::table('lead_products')->where('lead_id', $pedido['id'])->delete();
    DB::table('leads')->where('id', $pedido['id'])->delete();
    DB::table('persons')->where('id', $contacto['id'])->delete();
});
