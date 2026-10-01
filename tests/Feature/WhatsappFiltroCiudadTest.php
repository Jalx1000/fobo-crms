<?php

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Pipeline;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Support\ConversationCity;

/*
 * Filtro por ciudad en la bandeja de WhatsApp.
 *
 * En kohlberg la ciudad ES el pipeline, pero una conversación no tiene ciudad
 * propia: se deduce en cascada (handoff -> atributo del contacto -> pedido).
 * Lo que estos tests fijan es esa cascada y su precedencia, que es donde está
 * toda la sutileza.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();

    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

function conversacionCon(array $attrs = []): object
{
    return ConversationProxy::modelClass()::create(array_merge([
        'gateway'  => config('whatsapp.gateway'),
        'wa_phone' => '+5917'.random_int(1000000, 9999999),
        'wa_name'  => 'Filtro ciudad '.uniqid(),
    ], $attrs));
}

/** El id de ciudad que el CRM resuelve para esa conversación. */
function ciudadResuelta(int $conversationId): ?int
{
    $valor = DB::table('whatsapp_conversations')
        ->where('id', $conversationId)
        ->selectRaw(ConversationCity::sql().' as city_id')
        ->value('city_id');

    return $valor === null ? null : (int) $valor;
}

function personaConCiudad(?int $pipelineId): int
{
    $personId = (int) DB::table('persons')->insertGetId([
        'name'       => 'Contacto ciudad '.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($pipelineId) {
        DB::table('attribute_values')->insert([
            'entity_id'     => $personId,
            'entity_type'   => 'persons',
            'attribute_id'  => DB::table('attributes')->where('code', 'cliente_ciudad')->where('entity_type', 'persons')->value('id'),
            'integer_value' => $pipelineId,
        ]);
    }

    return $personId;
}

/*
|--------------------------------------------------------------------------
| La cascada
|--------------------------------------------------------------------------
*/

it('cascada: resuelve la ciudad desde el handoff', function () {
    $pipeline = Pipeline::first();

    $c = conversacionCon(['handoff_city_id' => $pipeline->id]);

    expect(ciudadResuelta($c->id))->toBe($pipeline->id);
});

it('cascada: resuelve la ciudad desde el atributo del contacto', function () {
    $pipeline = Pipeline::first();

    $c = conversacionCon(['person_id' => personaConCiudad($pipeline->id)]);

    expect(ciudadResuelta($c->id))->toBe($pipeline->id);
});

it('cascada: sin ninguna fuente devuelve null', function () {
    expect(ciudadResuelta(conversacionCon()->id))->toBeNull();
});

/*
 * La precedencia es lo que hace falta fijar: los tres caminos coinciden hoy
 * (CitySyncService los mantiene sincronizados), así que un error de orden no
 * se notaría con los datos actuales — hasta que un día dejen de coincidir.
 */
it('cascada: el handoff le gana al atributo del contacto', function () {
    $pipelines = Pipeline::limit(2)->get();

    if ($pipelines->count() < 2) {
        test()->markTestSkipped('Hacen falta dos pipelines.');
    }

    [$delHandoff, $delContacto] = [$pipelines[0], $pipelines[1]];

    $c = conversacionCon([
        'handoff_city_id' => $delHandoff->id,
        'person_id'       => personaConCiudad($delContacto->id),
    ]);

    expect(ciudadResuelta($c->id))->toBe($delHandoff->id);
});

/*
|--------------------------------------------------------------------------
| El filtro
|--------------------------------------------------------------------------
*/

function listar(array $params = []): array
{
    return test()->getJson(route('admin.whatsapp.conversations', $params))
        ->assertOk()
        ->json();
}

it('filtro: city=<id> devuelve sólo esa ciudad', function () {
    $pipeline = Pipeline::orderBy('id')->first();

    /*
     * El fixture lo crea el test.
     *
     * Antes dependía de que la base YA tuviera una conversación en esa ciudad
     * —cierto en kohlberg, falso en una instalación nueva—, así que el filtro
     * devolvía [] y la falla hablaba de un filtro roto cuando lo que faltaba
     * era el dato.
     */
    conversacionCon(['person_id' => personaConCiudad($pipeline->id)]);

    $filas = listar(['city' => $pipeline->id])['data'];

    expect($filas)->not->toBeEmpty();

    foreach ($filas as $fila) {
        expect($fila['city'])->toBe($pipeline->name);
    }
});

it('filtro: city=none devuelve las que no tienen ciudad', function () {
    $sinCiudad = collect(ConversationCity::noneEquivalentPipelineIds())
        ->map(fn ($id) => Pipeline::find($id)?->name)
        ->filter()
        ->all();

    foreach (listar(['city' => ConversationCity::NONE])['data'] as $fila) {
        // O no tiene ciudad, o cae en un pipeline que significa lo mismo.
        expect($fila['city'] === null || in_array($fila['city'], $sinCiudad, true))->toBeTrue();
    }
});

it('filtro: sin el parámetro la lista no cambia', function () {
    expect(listar()['last_page'])->toBe(listar(['city' => null])['last_page']);
});

it('filtro: se combina con etapa y búsqueda en vez de reemplazarlas', function () {
    $pipeline = Pipeline::where('name', 'Santa Cruz')->first() ?? Pipeline::first();

    $conCiudad = listar(['city' => $pipeline->id])['data'];
    $conAmbos = listar(['city' => $pipeline->id, 'filter' => 'handoff'])['data'];

    // Sumar un filtro sólo puede reducir: si "derivadas" ignorara la ciudad,
    // devolvería filas de otras ciudades o más de las que hay en ésta.
    expect(count($conAmbos))->toBeLessThanOrEqual(count($conCiudad));

    foreach ($conAmbos as $fila) {
        expect($fila['city'])->toBe($pipeline->name);
    }
});

/*
|--------------------------------------------------------------------------
| Conteos del desplegable
|--------------------------------------------------------------------------
*/

it('conteo: la suma de las ciudades es el total de conversaciones', function () {
    $r = test()->getJson(route('admin.whatsapp.cities'))->assertOk()->json();

    $suma = array_sum(array_column($r['cities'], 'count')) + $r['none']['count'];

    expect($suma)->toBe($r['total']);
});

/*
 * El conteo tiene que repetir la MISMA deduplicación por ROW_NUMBER que la
 * lista. Sin eso, un contacto con la misma línea guardada en dos formatos se
 * cuenta dos veces en el desplegable y una en la lista, y los números no
 * coinciden.
 */
it('conteo: el total coincide con lo que devuelve la lista', function () {
    $ciudades = test()->getJson(route('admin.whatsapp.cities'))->assertOk()->json();

    $enLista = 0;
    $pagina = 1;

    do {
        $r = listar(['page' => $pagina]);
        $enLista += count($r['data']);
        $pagina++;
    } while ($pagina <= $r['last_page']);

    expect($ciudades['total'])->toBe($enLista);
});

it('conteo: una ciudad sin conversaciones no aparece', function () {
    $r = test()->getJson(route('admin.whatsapp.cities'))->assertOk()->json();

    foreach ($r['cities'] as $ciudad) {
        expect($ciudad['count'])->toBeGreaterThan(0);
    }
});

/*
|--------------------------------------------------------------------------
| La fila y el blade
|--------------------------------------------------------------------------
*/

it('fila: cada conversación trae su ciudad, o null', function () {
    foreach (listar()['data'] as $fila) {
        expect($fila)->toHaveKey('city')
            ->and($fila['city'] === null || is_string($fila['city']))->toBeTrue();
    }
});

it('blade: el desplegable de ciudad está en el panel', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/chat.blade.php')
    );

    expect($blade)->toContain('id="wa-city"')
        ->and($blade)->toContain('cityFilter')
        ->and($blade)->toContain("route('admin.whatsapp.cities')")
        // El chip en la fila: sin él no hay forma de confirmar que el filtro
        // muestra lo correcto.
        ->and($blade)->toContain('c.city');
});

/*
 * El CSS del admin se compiló el 2026-08-28 y las clases de valor arbitrario de
 * Tailwind NO existen en él: una clase h-[220px] no aplica nada. Es lo que dejó
 * el mapa de ubicaciones invisible. El markup nuevo usa clases estándar.
 */
it('blade: el filtro nuevo no usa clases de Tailwind con valor arbitrario', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/chat.blade.php')
    );

    $inicio = strpos($blade, 'id="wa-city"');
    $bloque = substr($blade, $inicio - 800, 1600);

    expect(preg_match('/class="[^"]*\b[a-z-]+\[[^\]]+\]/', $bloque))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| El filtro es GLOBAL: se comparte con Tablero, Pedidos y Prospectos
|--------------------------------------------------------------------------
| Vía la cookie "global_pipeline_id", que ya existía para esos tres módulos
| (LeadController, PersonController, DashboardController). Elegir Santa Cruz en
| la bandeja la deja elegida en el resto del CRM, y al revés.
*/

/*
 * withUnencryptedCookie y no withCookie: la cookie está en
 * EncryptCookies::$except justamente para que el JS del tablero la pueda leer.
 * withCookie() la trataría como encriptada y el request la descartaría.
 */
it('global: la bandeja arranca con la ciudad que dejó el filtro compartido', function () {
    $pipeline = Pipeline::where('name', 'Santa Cruz')->first() ?? Pipeline::first();

    $html = test()->withUnencryptedCookie('global_pipeline_id', (string) $pipeline->id)
        ->get(route('admin.whatsapp.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('saved-city="'.$pipeline->id.'"');
});

it('global: "all" en la cookie deja la bandeja en Todas', function () {
    $html = test()->withUnencryptedCookie('global_pipeline_id', 'all')
        ->get(route('admin.whatsapp.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('saved-city="all"');
});

it('global: sin cookie, la bandeja no fuerza ninguna ciudad', function () {
    $html = test()->get(route('admin.whatsapp.index'))->assertOk()->getContent();

    expect($html)->toContain('saved-city=""');
});

/*
 * La cookie tiene que ser LA MISMA que ya usan los otros módulos, no una
 * paralela: mismo nombre, y sin encriptar para que el JS la pueda leer y
 * escribir (por eso está en EncryptCookies::$except).
 */
it('global: usa la misma cookie que Pedidos y el Tablero, y sin encriptar', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/chat.blade.php')
    );

    expect($blade)->toContain('global_pipeline_id')
        ->and($blade)->toContain('SameSite=Lax');

    $except = (new ReflectionClass(App\Http\Middleware\EncryptCookies::class))
        ->newInstanceWithoutConstructor();

    $prop = (new ReflectionClass($except))->getProperty('except');
    $prop->setAccessible(true);

    expect($prop->getValue($except))->toContain('global_pipeline_id');
});

/*
 * La cookie llega como texto y las opciones del select bindean un id numérico.
 * Sin convertir, el desplegable queda sin nada seleccionado aunque la lista sí
 * venga filtrada — el usuario ve "Todas" y una lista que no son todas.
 */
it('global: el valor de la cookie se convierte a número', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/chat.blade.php')
    );

    expect($blade)->toContain('Number(this.savedCity)');
});
