<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Distribuidor\Services\Bidones;
use Webkul\Distribuidor\Services\Clientes;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/**
 * La agenda de clientes del Punto.
 *
 * Reemplaza a los apartados "Caja" y "Bidones". Bidones listaba SÓLO a los que
 * debían envases, y ese recorte dejaba afuera el caso más común de la calle:
 * acordarse del cliente y no del teléfono. Para llegar a su ficha había que pasar
 * por un pedido.
 *
 * Lo que la agenda tiene que garantizar: que no se escape un cliente de otra
 * ciudad, que los que deben envases queden arriba, y que la fecha de "última
 * entrega" sea la de la ENTREGA y no la de la última edición del pedido.
 */
uses(DatabaseTransactions::class);

function distribuidorDeAgenda(): User
{
    $rol = Role::where('name', 'Distribuidor')->firstOrFail();

    return User::create([
        'name'     => 'Distribuidor agenda '.uniqid(),
        'email'    => 'age'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => $rol->id,
        'status'   => 1,
    ]);
}

/**
 * Un distribuidor restringido a UNA ciudad, via grupo de Krayin.
 *
 * Hace falta un usuario restringido para probar el 403: un usuario sin restricción
 * ve todas las ciudades, así que `puedeVerCiudad()` le dice sí incluso a un id que
 * no existe, y el test pasaría sin probar nada.
 */
function distribuidorDeUnaCiudad(int $pipelineId): User
{
    $user = distribuidorDeAgenda();
    DB::table('users')->where('id', $user->id)->update(['view_permission' => 'group']);

    $nombre = (string) DB::table('lead_pipelines')->where('id', $pipelineId)->value('name');
    $groupId = (int) (DB::table('groups')->where('name', $nombre)->value('id')
        ?: DB::table('groups')->insertGetId(['name' => $nombre, 'description' => 'Temporal para tests']));

    DB::table('user_groups')->insert(['user_id' => $user->id, 'group_id' => $groupId]);

    return $user->refresh();
}

/** Dos ciudades distintas que existan de verdad, para probar el aislamiento. */
function dosCiudades(): array
{
    $ids = DB::table('lead_pipelines')->orderBy('id')->limit(2)->pluck('id')->all();

    if (count($ids) < 2) {
        test()->markTestSkipped('Hacen falta dos pipelines (ciudades) para esta prueba.');
    }

    return [(int) $ids[0], (int) $ids[1]];
}

/**
 * Un cliente con un pedido en la ciudad dada.
 *
 * El teléfono se guarda con el formato real del CRM —un JSON de `contact_numbers`—
 * y no como una columna, porque el buscador tiene que funcionar sobre eso.
 */
function clienteDeAgenda(int $pipelineId, ?string $nombre = null, ?string $telefono = null): array
{
    $personId = (int) DB::table('persons')->insertGetId([
        'name'            => $nombre ?? ('Cliente agenda '.uniqid()),
        'contact_numbers' => json_encode($telefono
            ? [['value' => $telefono, 'label' => 'work']]
            : []),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stageId = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->orderBy('sort_order')
        ->value('id');

    $leadId = (int) DB::table('leads')->insertGetId([
        'title'                  => 'Pedido agenda '.uniqid(),
        'person_id'              => $personId,
        'lead_pipeline_id'       => $pipelineId,
        'lead_pipeline_stage_id' => $stageId,
        'lead_value'             => 1000,
        'user_id'                => 1,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    return ['person_id' => $personId, 'lead_id' => $leadId];
}

/** Sólo las filas de los clientes creados por la prueba. */
function soloEstos(array $filas, array $personIds): array
{
    return array_values(array_filter(
        $filas,
        fn ($f) => in_array($f['person_id'], $personIds, true),
    ));
}

// ───────────────────────────── el servicio ─────────────────────────────

it('la agenda trae nombre, telefono y saldo de envases', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad, 'Marcela Quispe '.uniqid(), '+54 388 415 5566');
    app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 4, 1);

    $fila = collect(app(Clientes::class)->listar([$ciudad], null, 500))
        ->firstWhere('person_id', $c['person_id']);

    expect($fila)->not->toBeNull()
        ->and($fila['telefono'])->toBe('+54 388 415 5566')
        ->and($fila['saldo'])->toBe(3);
});

it('la agenda es de la ciudad y no deja pasar a los de otra', function () {
    [$mia, $ajena] = dosCiudades();

    $propio = clienteDeAgenda($mia);
    $otro = clienteDeAgenda($ajena);

    /*
     * Los de Jujuy no reparten en Palpalá. Una agenda que mezcla las dos pone a un
     * cliente de otra ciudad a un toque de que alguien le cargue un pedido con el
     * catálogo —y los precios— equivocados.
     */
    $ids = array_column(app(Clientes::class)->listar([$mia], null, 500), 'person_id');

    expect($ids)->toContain($propio['person_id'])
        ->and($ids)->not->toContain($otro['person_id']);
});

it('los que deben envases van primero', function () {
    [$ciudad] = dosCiudades();

    // El que NO debe nada se crea DESPUÉS, así que por "venta más reciente" iría
    // primero. El saldo tiene que ganarle a la fecha.
    $deudor = clienteDeAgenda($ciudad);
    app(Bidones::class)->registrar($deudor['person_id'], $deudor['lead_id'], 6, 0);

    $alDia = clienteDeAgenda($ciudad);

    $ids = array_column(
        soloEstos(
            app(Clientes::class)->listar([$ciudad], null, 500),
            [$deudor['person_id'], $alDia['person_id']],
        ),
        'person_id',
    );

    expect($ids)->toBe([$deudor['person_id'], $alDia['person_id']]);
});

it('busca por nombre y por la cola del telefono con el mismo campo', function () {
    [$ciudad] = dosCiudades();

    $marca = strtoupper(substr(uniqid(), -6));
    $c = clienteDeAgenda($ciudad, 'Rosalia '.$marca, '+5493884155123');

    $servicio = app(Clientes::class);

    $porNombre = array_column($servicio->listar([$ciudad], $marca, 50), 'person_id');

    /*
     * El distribuidor tipea "4155123" y la ficha guarda "+5493884155123". Buscar el
     * número entero no matchea nunca: se busca la COLA, que es lo que la gente dicta.
     */
    $porTelefono = array_column($servicio->listar([$ciudad], '4155123', 50), 'person_id');

    expect($porNombre)->toContain($c['person_id'])
        ->and($porTelefono)->toContain($c['person_id']);
});

it('la ultima entrega sale de la entrega y no de la ultima edicion del pedido', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad);

    DB::table('distribuidor_entregas')->insert([
        'lead_id'      => $c['lead_id'],
        'user_id'      => null,
        'entregado_at' => now()->subDays(9),
        'created_at'   => now()->subDays(9),
        'updated_at'   => now()->subDays(9),
    ]);

    /*
     * 🔴 El lead se toca DESPUÉS de la entrega, que es lo que pasa siempre: se cobra,
     * se corrige un producto. Si la fecha saliera de `leads.updated_at` diría hoy, y
     * la pantalla mentiría justo en el dato que se consulta cuando un cliente dice
     * que no le llegó.
     */
    DB::table('leads')->where('id', $c['lead_id'])->update(['updated_at' => now()]);

    $fila = collect(app(Clientes::class)->listar([$ciudad], null, 500))
        ->firstWhere('person_id', $c['person_id']);

    expect($fila['ultima_entrega'])->not->toBeNull()
        ->and(substr((string) $fila['ultima_entrega'], 0, 10))
        ->toBe(now()->subDays(9)->toDateString());
});

it('un cliente sin entregas registradas no inventa una fecha', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad);

    /*
     * null tiene DOS significados y la pantalla los distingue: nunca recibió nada, o
     * recibió antes de que existiera el registro de entregas. Rellenarlo con la fecha
     * del pedido tapa el segundo caso con un dato falso.
     */
    $fila = collect(app(Clientes::class)->listar([$ciudad], null, 500))
        ->firstWhere('person_id', $c['person_id']);

    expect($fila['ultima_entrega'])->toBeNull();
});

it('un cliente con varios pedidos aparece una sola vez', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad);

    // Segundo pedido para la MISMA persona: sin el group by, quien compró cinco veces
    // llenaría la primera página y taparía a los demás.
    $stageId = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $ciudad)->orderBy('sort_order')->value('id');

    DB::table('leads')->insert([
        'title'                  => 'Segundo pedido '.uniqid(),
        'person_id'              => $c['person_id'],
        'lead_pipeline_id'       => $ciudad,
        'lead_pipeline_stage_id' => $stageId,
        'lead_value'             => 500,
        'user_id'                => 1,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    $veces = count(array_filter(
        app(Clientes::class)->listar([$ciudad], null, 500),
        fn ($f) => $f['person_id'] === $c['person_id'],
    ));

    expect($veces)->toBe(1);
});

// ───────────────────────────── el endpoint ─────────────────────────────

it('el endpoint pide la sucursal', function () {
    // Sin ciudad devolvería la agenda de todas, que es justo lo que no debe pasar.
    test()->actingAs(distribuidorDeAgenda(), 'user')
        ->getJson(route('admin.distribuidor.clientes'))
        ->assertStatus(422);
});

it('el endpoint rechaza la agenda de una ciudad ajena', function () {
    [$mia, $ajena] = dosCiudades();

    $user = distribuidorDeUnaCiudad($mia);

    /*
     * El filtro se aplica en el SERVIDOR y no en la UI. Filtrar sólo al dibujar deja
     * la agenda de la otra ciudad a un request de distancia, y el 403 es lo que
     * convierte el recorte en una regla.
     */
    test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.clientes', ['ciudad' => $ajena]))
        ->assertStatus(403);

    test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.clientes', ['ciudad' => $mia]))
        ->assertOk();
});

it('el endpoint pagina de 30 y avisa si hay mas', function () {
    [$ciudad] = dosCiudades();

    $respuesta = test()->actingAs(distribuidorDeAgenda(), 'user')
        ->getJson(route('admin.distribuidor.clientes', ['ciudad' => $ciudad]))
        ->assertOk();

    /*
     * `hay_mas` sale de pedir 31 y devolver 30, no de un count(): el count sobre esta
     * consulta —con su group by y su subconsulta de saldo— cuesta casi lo mismo que la
     * consulta entera, y lo único que la pantalla necesita es si pintar "Ver más".
     */
    expect(count($respuesta->json('clientes')))->toBeLessThanOrEqual(30)
        ->and($respuesta->json('pagina'))->toBe(1)
        ->and($respuesta->json('hay_mas'))->toBeBool()
        ->and($respuesta->json('en_la_calle'))->toBeInt();
});

it('la segunda pagina no repite lo de la primera', function () {
    [$ciudad] = dosCiudades();

    /*
     * Los 31 clientes los crea la prueba y no los toma de la base: con los datos de
     * producción este test se salteaba cuando la ciudad tenía menos de 30, y un test
     * que se saltea no cuida nada. 31 es el mínimo que garantiza una segunda página.
     */
    for ($i = 0; $i < 31; $i++) {
        clienteDeAgenda($ciudad);
    }

    $user = distribuidorDeAgenda();

    $primera = test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.clientes', ['ciudad' => $ciudad]))
        ->assertOk();

    $segunda = test()->actingAs($user, 'user')
        ->getJson(route('admin.distribuidor.clientes', ['ciudad' => $ciudad, 'pagina' => 2]))
        ->assertOk();

    expect($primera->json('hay_mas'))->toBeTrue()
        ->and(count($primera->json('clientes')))->toBe(30);

    /*
     * El solapamiento es el error clásico del paginado por offset cuando el orden no
     * es determinista: dos filas que empatan pueden cambiar de lugar entre consultas y
     * aparecer en las dos páginas, o en ninguna. El desempate acá es `max(leads.id)`,
     * que es único por cliente.
     */
    $repetidos = array_intersect(
        array_column($primera->json('clientes'), 'person_id'),
        array_column($segunda->json('clientes'), 'person_id'),
    );

    expect($repetidos)->toBe([]);
});

// ──────────────────── la pantalla: tres pestañas, no cuatro ────────────────────

it('el Punto tiene exactamente tres pestañas', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    // La grilla de navegación, por su comentario de encabezado.
    $desde = strpos($vista, 'Tres pestañas grandes');
    expect($desde)->not->toBeFalse();

    $hasta = strpos($vista, 'v-if="aviso"', $desde);
    $barra = substr($vista, $desde, $hasta - $desde);

    $vistas = [];
    preg_match_all('/vista === \'([a-z]+)\'/', $barra, $m);
    $vistas = array_values(array_unique($m[1]));

    expect($vistas)->toBe(['nuevo', 'pedidos', 'clientes']);

    /*
     * Caja era un resumen que se mira UNA vez, al cerrar el día, y se quedaba con un
     * cuarto de la barra todo el día. Si vuelve, tiene que volver a otro lugar.
     */
    expect($barra)->not->toContain('irACaja');
});

it('los botones dicen que hace cada seccion', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    /*
     * Las etiquetas nombran QUÉ lista cada botón, no desde dónde se lo mira.
     * "Para entregar" no decía qué contenía, y "Mis pedidos de hoy" describía al
     * dueño en vez del contenido —y además incluye los ya entregados, así que es un
     * historial—.
     */
    foreach (['Crear pedido', 'Pedidos pendientes', 'Historial de pedidos'] as $etiqueta) {
        $this->assertStringContainsString($etiqueta, $vista, 'Falta la etiqueta: ');
    }

    foreach (['Nuevo pedido', 'Para entregar', 'Mis pedidos de hoy'] as $viejo) {
        $this->assertStringNotContainsString($viejo, $vista, 'Quedó una etiqueta vieja: ');
    }
});

it('la caja ya no se dibuja en el Punto', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    // El endpoint sigue existiendo —el dato no se perdió— pero la pantalla del
    // distribuidor no lo muestra: se mira una vez al día y no desde la calle.
    expect($vista)->not->toContain("vista === 'caja'")
        ->and($vista)->not->toContain('cargarCaja')
        ->and($vista)->not->toContain('cajaUrl');
});

// ───────────────────────── editar el cliente ─────────────────────────

it('corrige el nombre y el telefono del cliente', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad, 'Marcela', '+54 388 400 0001');

    $persona = test()->actingAs(distribuidorDeAgenda(), 'user')
        ->patchJson(route('admin.distribuidor.cliente.update', $c['person_id']), [
            'nombre'   => 'Marcela Quispe',
            'telefono' => '+54 388 400 9999',
        ])
        ->assertOk()
        ->json('persona');

    expect($persona['name'])->toBe('Marcela Quispe')
        ->and($persona['telefono'])->toBe('+54 388 400 9999');
});

it('no deja poner un telefono que ya es de otro cliente', function () {
    [$ciudad] = dosCiudades();

    $tomado = '+5493884'.random_int(100000, 999999);

    $dueno = clienteDeAgenda($ciudad, 'Dueño del número', $tomado);
    $otro = clienteDeAgenda($ciudad, 'Otro cliente', '+5493884'.random_int(100000, 999999));

    /*
     * 🔴 El teléfono es la IDENTIDAD del cliente en todo el Punto: se lo busca por
     * número, el agente de WhatsApp lo encuentra por número y la bienvenida se le manda
     * al número. Dos fichas con el mismo teléfono es un estado del que no se vuelve: no
     * hay forma de saber a cuál pertenece un pedido.
     */
    test()->actingAs(distribuidorDeAgenda(), 'user')
        ->patchJson(route('admin.distribuidor.cliente.update', $otro['person_id']), [
            'nombre'   => 'Otro cliente',
            'telefono' => $tomado,
        ])
        ->assertStatus(422);

    // Y el número sigue siendo del dueño original.
    expect(app(\Webkul\Contact\Repositories\PersonRepository::class)->findByPhone($tomado)->id)
        ->toBe($dueno['person_id']);
});

it('guardar el mismo telefono del propio cliente no es un duplicado', function () {
    [$ciudad] = dosCiudades();

    $propio = '+5493884'.random_int(100000, 999999);
    $c = clienteDeAgenda($ciudad, 'Sin cambio de número', $propio);

    /*
     * El caso de corregir SÓLO el nombre: el teléfono se reenvía igual porque el
     * formulario manda los dos campos. Si la validación mirara nada más que "existe
     * alguien con este número", se rechazaría a sí mismo y el nombre no se podría
     * corregir nunca.
     */
    test()->actingAs(distribuidorDeAgenda(), 'user')
        ->patchJson(route('admin.distribuidor.cliente.update', $c['person_id']), [
            'nombre'   => 'Nombre corregido',
            'telefono' => $propio,
        ])
        ->assertOk()
        ->assertJsonPath('persona.name', 'Nombre corregido');
});

it('corregir el telefono conserva los otros numeros de la ficha', function () {
    [$ciudad] = dosCiudades();

    $personId = (int) DB::table('persons')->insertGetId([
        'name'            => 'Con dos números '.uniqid(),
        'contact_numbers' => json_encode([
            ['value' => '+5493884000111', 'label' => 'work'],
            ['value' => '+5493884000222', 'label' => 'home'],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    test()->actingAs(distribuidorDeAgenda(), 'user')
        ->patchJson(route('admin.distribuidor.cliente.update', $personId), [
            'nombre'   => 'Con dos números',
            'telefono' => '+5493884000333',
        ])
        ->assertOk();

    $numeros = json_decode(
        (string) DB::table('persons')->where('id', $personId)->value('contact_numbers'),
        true,
    );

    /*
     * Una ficha puede tener el fijo y el celular, y corregir el celular no es una orden
     * de borrar el resto. Se reemplaza el PRIMERO —el que el Punto muestra y usa— y los
     * demás quedan.
     */
    expect(collect($numeros)->pluck('value')->all())
        ->toBe(['+5493884000333', '+5493884000222']);
});

it('la agenda trae el domicilio para abrir el mapa donde ya está el pin', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad);

    app(\Webkul\Distribuidor\Services\Ubicacion::class)->guardarDelMapa(
        $c['person_id'], -24.1858, -65.2995, null, ['direccion' => 'Belgrano 100'],
    );

    $fila = collect(app(Clientes::class)->listar([$ciudad], null, 500))
        ->firstWhere('person_id', $c['person_id']);

    /*
     * Sin esto el editor abría en el centro de la provincia y corregir 20 metros
     * obligaba a buscar la casa desde cero. Viaja con la fila y no en una consulta por
     * cliente: 30 filas serían 30 idas y vueltas en un celular con datos móviles.
     */
    expect($fila['ubicacion'])->not->toBeNull()
        ->and($fila['ubicacion']['direccion'])->toBe('Belgrano 100')
        ->and(round($fila['ubicacion']['lat'], 4))->toBe(-24.1858);
});

// ───────────────────────── el historial ─────────────────────────

it('el historial trae todos los pedidos de la sucursal, no los de hoy', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad);

    /*
     * 🔴 El pedido es de ANTEAYER y de OTRO usuario, que es el caso que la pestaña no
     * mostraba: filtraba por `user_id = yo` y `created_at = hoy`, así que aparecía
     * vacía cada mañana y vacía también para quien reparte lo que cargó otro.
     */
    DB::table('leads')->where('id', $c['lead_id'])->update([
        'user_id'    => 1,
        'created_at' => now()->subDays(2),
    ]);

    $pedidos = test()->actingAs(distribuidorDeAgenda(), 'user')
        ->getJson(route('admin.distribuidor.pedidos', [
            'ciudad' => $ciudad,
            'filtro' => 'historial',
        ]))
        ->assertOk()
        ->json('pedidos');

    expect(array_column($pedidos, 'id'))->toContain($c['lead_id']);
});

it('el historial se puede acotar a un rango de fechas', function () {
    [$ciudad] = dosCiudades();

    $antiguo = clienteDeAgenda($ciudad);
    $medio = clienteDeAgenda($ciudad);
    $reciente = clienteDeAgenda($ciudad);

    DB::table('leads')->where('id', $antiguo['lead_id'])->update(['created_at' => now()->subDays(20)]);
    DB::table('leads')->where('id', $medio['lead_id'])->update(['created_at' => now()->subDays(10)]);
    DB::table('leads')->where('id', $reciente['lead_id'])->update(['created_at' => now()->subDay()]);

    $user = distribuidorDeAgenda();

    $pedir = fn (array $rango) => array_column(
        test()->actingAs($user, 'user')->getJson(route('admin.distribuidor.pedidos', array_merge(
            ['ciudad' => $ciudad, 'filtro' => 'historial'],
            $rango,
        )))->assertOk()->json('pedidos'),
        'id',
    );

    // Rango cerrado: entra el del medio, quedan afuera los dos extremos.
    $cerrado = $pedir([
        'desde' => now()->subDays(15)->toDateString(),
        'hasta' => now()->subDays(5)->toDateString(),
    ]);

    expect($cerrado)->toContain($medio['lead_id'])
        ->and($cerrado)->not->toContain($antiguo['lead_id'])
        ->and($cerrado)->not->toContain($reciente['lead_id']);

    // Sólo "desde": sin tope. Es una de las dos formas en que se usa de verdad.
    $soloDesde = $pedir(['desde' => now()->subDays(15)->toDateString()]);

    expect($soloDesde)->toContain($medio['lead_id'])
        ->and($soloDesde)->toContain($reciente['lead_id'])
        ->and($soloDesde)->not->toContain($antiguo['lead_id']);

    // Sólo "hasta": sin piso.
    $soloHasta = $pedir(['hasta' => now()->subDays(15)->toDateString()]);

    expect($soloHasta)->toContain($antiguo['lead_id'])
        ->and($soloHasta)->not->toContain($medio['lead_id']);
});

it('un rango de un solo dia trae ese dia completo', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad);

    /*
     * 🔴 A las 23:30, que es el caso que rompe la comparación ingenua. Si `hasta` se
     * compara contra el timestamp en vez de contra la fecha, "2026-10-06" se lee como
     * las 00:00 de ese día y deja afuera el día entero.
     */
    $dia = now()->subDays(4)->setTime(23, 30);
    DB::table('leads')->where('id', $c['lead_id'])->update(['created_at' => $dia]);

    $pedidos = test()->actingAs(distribuidorDeAgenda(), 'user')
        ->getJson(route('admin.distribuidor.pedidos', [
            'ciudad' => $ciudad,
            'filtro' => 'historial',
            'desde'  => $dia->toDateString(),
            'hasta'  => $dia->toDateString(),
        ]))
        ->assertOk()
        ->json('pedidos');

    expect(array_column($pedidos, 'id'))->toContain($c['lead_id']);
});

it('rechaza un rango al reves', function () {
    [$ciudad] = dosCiudades();

    // "Hasta" antes de "desde" no devuelve nada nunca, y devolver una lista vacía sin
    // decir por qué parece que no hay pedidos en vez de un rango mal puesto.
    test()->actingAs(distribuidorDeAgenda(), 'user')
        ->getJson(route('admin.distribuidor.pedidos', [
            'ciudad' => $ciudad,
            'filtro' => 'historial',
            'desde'  => now()->toDateString(),
            'hasta'  => now()->subDays(3)->toDateString(),
        ]))
        ->assertStatus(422);
});

it('cada pedido dice de qué día es, calculado en el servidor', function () {
    [$ciudad] = dosCiudades();

    $c = clienteDeAgenda($ciudad);
    DB::table('leads')->where('id', $c['lead_id'])->update(['created_at' => now()->subDays(3)]);

    $pedidos = test()->actingAs(distribuidorDeAgenda(), 'user')
        ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $ciudad, 'filtro' => 'historial']))
        ->assertOk()
        ->json('pedidos');

    $fila = collect($pedidos)->firstWhere('id', $c['lead_id']);

    /*
     * El día lo calcula el SERVIDOR y llega aparte. Derivarlo en el navegador lo deja a
     * merced del huso horario del celular: un pedido de las 23:30 se iría al día
     * siguiente en un teléfono mal configurado, y el encabezado mentiría sobre el día
     * de trabajo.
     */
    expect($fila['dia'])->toBe(now()->subDays(3)->toDateString())
        ->and($fila['creado_at'])->toContain(now()->subDays(3)->format('d/m/Y'));
});

it('el filtro viejo "hoy" ya no existe', function () {
    [$ciudad] = dosCiudades();

    // Se renombró a 'historial' y cambió de significado. Dejar pasar el nombre viejo
    // devolvería silenciosamente el listado de pendientes, que no es lo que se pidió.
    test()->actingAs(distribuidorDeAgenda(), 'user')
        ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $ciudad, 'filtro' => 'hoy']))
        ->assertStatus(422);
});

it('los pendientes no se separan por dia', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    /*
     * Los pendientes son una COLA de trabajo. Partirla en días sugiere que los de ayer
     * son de otra tanda, cuando son los más urgentes. El agrupador devuelve un grupo
     * único sin día para que la plantilla no dibuje encabezados.
     */
    $this->assertStringContainsString("if (this.filtro !== 'historial')", $vista,
        'El agrupador tiene que devolver un grupo plano fuera del historial: ');
    $this->assertStringContainsString('dia: null', $vista,
        'Falta el grupo sin día que apaga los encabezados: ');
});

it('el boton de envases dice qué registra', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    /*
     * "Recibí" obligaba a resolver quién era el sujeto, y en una columna de 100px se
     * leía como una etiqueta y no como un botón. "Devolvió envases" describe el hecho
     * que se registra, desde el lado del cliente, que es como lo cuenta el distribuidor.
     */
    $this->assertStringContainsString('Devolvió envases', $vista, 'Falta la etiqueta: ');
    $this->assertStringNotContainsString('>Recibí envases<', $vista, 'Quedó la etiqueta vieja: ');
    $this->assertStringContainsString('Editar cliente', $vista, 'Falta poder editar al cliente: ');
});
