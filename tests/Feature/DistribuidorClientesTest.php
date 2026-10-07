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
