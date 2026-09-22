<?php

use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Http\Controllers\InboxController;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/*
 * Las ubicaciones se ven en un mapa, no como un par de coordenadas.
 *
 * Lo que importa acá y no se ve mirando el blade: que el bloque `location`
 * llegue armado para AMBAS direcciones. Lo que mandamos nosotros se guarda en
 * payload['location']; lo que manda el cliente llega en el nodo propio de Meta,
 * y hasta que locationOf() leyó las dos formas una ubicación entrante se
 * dibujaba como burbuja vacía.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(fn () => DB::beginTransaction());

afterEach(fn () => DB::rollBack());

function conversacionUbicacion(): object
{
    return ConversationProxy::modelClass()::create([
        'gateway'  => 'cloud_api',
        'wa_phone' => '5917'.random_int(1000000, 9999999),
        'wa_name'  => 'Prueba ubicación',
    ]);
}

function transformar($message): array
{
    $m = (new ReflectionClass(InboxController::class))->getMethod('transform');
    $m->setAccessible(true);

    return $m->invoke(app(InboxController::class), $message);
}

it('arma el bloque de mapa para una ubicación que enviamos nosotros', function () {
    $message = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionUbicacion()->id,
        'direction'       => 'outbound',
        'type'            => 'location',
        'body'            => 'Oficina',
        'payload'         => ['location' => [
            'latitude'  => -17.754592,
            'longitude' => -63.196884,
            'name'      => 'Oficina',
            'address'   => 'Av. Ejemplo 123',
        ]],
        'status' => 'sent',
    ]);

    $row = transformar($message);

    expect($row['location']['lat'])->toBe(-17.754592)
        ->and($row['location']['lng'])->toBe(-63.196884)
        ->and($row['location']['name'])->toBe('Oficina')
        ->and($row['location']['address'])->toBe('Av. Ejemplo 123')
        ->and($row['location']['url'])->toContain('-17.754592,-63.196884');
});

it('arma el bloque de mapa para una ubicación que mandó el cliente', function () {
    // La forma cruda de Meta, que es distinta de la nuestra.
    $message = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionUbicacion()->id,
        'direction'       => 'inbound',
        'type'            => 'location',
        'body'            => null,
        'payload'         => [
            'type'     => 'location',
            'location' => [
                'latitude'  => -17.783330,
                'longitude' => -63.182140,
                'name'      => 'Mi casa',
            ],
        ],
        'status' => 'delivered',
    ]);

    $row = transformar($message);

    expect($row['location'])->not->toBeNull()
        ->and($row['location']['lat'])->toBe(-17.78333)
        ->and($row['location']['name'])->toBe('Mi casa')
        ->and($row['location']['address'])->toBeNull();
});

it('no inventa un mapa cuando la ubicación llegó sin coordenadas', function () {
    $message = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionUbicacion()->id,
        'direction'       => 'inbound',
        'type'            => 'location',
        'payload'         => ['location' => ['name' => 'Sin coordenadas']],
        'status'          => 'delivered',
    ]);

    // null y no un mapa en 0,0: la burbuja cae al aviso de "sin coordenadas"
    // en vez de dibujar un pin en el Golfo de Guinea.
    expect(transformar($message)['location'])->toBeNull();
});

it('un mensaje que no es ubicación no trae bloque de mapa', function () {
    $message = MessageProxy::modelClass()::create([
        'conversation_id' => conversacionUbicacion()->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'Hola',
        'payload'         => ['type' => 'text'],
        'status'          => 'delivered',
    ]);

    expect(transformar($message)['location'])->toBeNull();
});

it('el mapa se muestra abierto dentro del hilo, sin modal ni clic previo', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    // El contenedor del mapa está en el hilo, no detrás de un botón.
    expect($blade)->toContain('registerBubbleMap')
        // Y no quedó nada del visor en modal que reemplazó esto.
        ->and($blade)->not->toContain('locationViewer')
        ->and($blade)->not->toContain('verUbicacion');
});

/*
 * El zoom con la rueda tiene que quedar apagado. Con el mapa abierto por
 * defecto dentro de un hilo que scrollea, un mapa que captura la rueda le traba
 * el scroll al asesor cada vez que el cursor le pasa por encima.
 */
it('el mapa del hilo no se queda con el scroll de la rueda', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    expect($blade)->toContain('scrollWheelZoom: false');
});

/*
 * Los objetos de Leaflet no pueden vivir en data(): el proxy de reactividad de
 * Vue los rompe, porque Leaflet guarda nodos del DOM y los compara por
 * identidad. Ya está documentado en el archivo para el mapa del selector; este
 * test evita que alguien lo repita con los mapas de las burbujas.
 */
it('no guarda mapas de Leaflet dentro de data()', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    $data = substr($blade, strpos($blade, 'data()'), strpos($blade, 'watch:') - strpos($blade, 'data()'));

    expect($data)->not->toContain('bubbleMaps')
        ->and($data)->not->toContain('viewerMap:')
        // Y se destruyen: si no, quedan listeners sobre nodos ya removidos.
        ->and($blade)->toContain('destroyBubbleMaps');
});

/*
 * Dos métodos con el mismo nombre en el objeto `methods` no dan ningún error:
 * JavaScript se queda con el último y el primero desaparece. Pasó con
 * `openLocation` —el composer dejó de abrir el selector de ubicación porque un
 * método nuevo del visor de mapas se llamaba igual— y no lo delató ninguna
 * excepción, ni el compilador de Blade, ni la consola del navegador.
 */
it('no declara dos veces el mismo método en el componente', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    $inicio = strpos($blade, "\n            methods: {");

    expect($inicio)->not->toBeFalse('No se encontró el bloque methods del componente.');

    $bloque = substr($blade, $inicio);

    // Sólo las declaraciones al nivel del objeto: 16 espacios de indentación.
    preg_match_all('/^ {16}([A-Za-z_$][\w$]*)\s*\(/m', $bloque, $m);

    $repetidos = array_keys(array_filter(array_count_values($m[1]), fn ($n) => $n > 1));

    expect($repetidos)->toBe([], 'Métodos declarados más de una vez: '.implode(', ', $repetidos));
});

it('el composer conserva su propio openLocation para abrir el selector', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    // El del composer abre el modal del selector, y sigue siendo el único.
    expect($blade)->toContain('this.$refs.locationModal.open()')
        ->and(substr_count($blade, 'openLocation()'))->toBe(1);
});
