<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Jobs\ResolverEnlaceDeMapa;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\EnlaceDeMapa;

/*
 * Los links de Google Maps que el cliente pega en el chat.
 *
 * Al 06/10/2026 había 5 pines nativos y 5 mensajes de texto con un
 * `maps.app.goo.gl`: pasa tanto como el 📍 y de estos no se sacaba nada. El
 * cliente cree que mandó su dirección y el agente ve una URL que no sabe leer.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    Queue::fake();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

function textoDeCliente(string $cuerpo): object
{
    $wamid = 'wamid.link.'.uniqid();

    (new IngestInboundPayload('cloud_api', [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Cliente']]],
                    'messages' => [[
                        'id'   => $wamid,
                        'from' => '5493884'.random_int(100000, 999999),
                        'type' => 'text',
                        'text' => ['body' => $cuerpo],
                    ]],
                ],
            ]],
        ]],
    ]))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    return MessageProxy::modelClass()::where('wa_message_id', $wamid)->firstOrFail();
}

/*
 * 🔴 El test que importa.
 *
 * `!3d!4d` es EL LUGAR y `/@` es el centro del visor. Con el link real que trajo
 * el usuario están a ~950 m: leer el `@` manda al distribuidor a otra cuadra.
 */
it('prefiere el lugar por sobre el centro del visor', function () {
    $url = 'https://www.google.com/maps/place/NOVITA/@-17.7436362,-63.191432,4481m/'
        .'data=!3m1!1e3!4m6!3m5!1s0x93f1e7e47b6ad42f:0x79bfc1685a001eef!8m2!'
        .'3d-17.7520937!4d-63.1931235!16s%2Fg%2F11zxcsmlwx';

    $punto = EnlaceDeMapa::parsear($url);

    expect($punto['latitude'])->toBe(-17.7520937)
        ->and($punto['longitude'])->toBe(-63.1931235)
        ->and($punto['name'])->toBe('NOVITA');
});

it('usa el visor sólo cuando no hay lugar', function () {
    $punto = EnlaceDeMapa::parsear('https://www.google.com/maps/@-24.1884781,-65.300333,17z');

    expect($punto['latitude'])->toBe(-24.1884781)
        ->and($punto['name'])->toBeNull();
});

it('lee coordenadas pedidas en la query', function () {
    expect(EnlaceDeMapa::parsear('https://maps.google.com/?q=-24.255002,-65.208238')['latitude'])
        ->toBe(-24.255002)
        ->and(EnlaceDeMapa::parsear('https://www.google.com/maps/search/?api=1&query=-24.19,-65.29')['longitude'])
        ->toBe(-65.29);
});

it('no inventa coordenadas donde no hay', function () {
    expect(EnlaceDeMapa::parsear('https://www.google.com/maps/place/Casa+de+Juan/'))->toBeNull()
        ->and(EnlaceDeMapa::parsear('https://maps.google.com/?q=0.0,0.0'))->toBeNull()
        ->and(EnlaceDeMapa::parsear('https://maps.google.com/?q=-99.5,-65.2'))->toBeNull();
});

/*
 * Resolver una URL que escribió un desconocido es un SSRF. La lista blanca se
 * revisa en el texto Y en cada salto de la redirección.
 */
it('no sale a buscar un dominio que no está en la lista blanca', function () {
    expect(EnlaceDeMapa::hay('mirá http://169.254.169.254/latest/meta-data/'))->toBeFalse()
        ->and(EnlaceDeMapa::hay('http://localhost:8080/admin'))->toBeFalse()
        ->and(EnlaceDeMapa::hay('https://maps.app.goo.gl.evil.com/x'))->toBeFalse()
        ->and(EnlaceDeMapa::hay('acá va https://maps.app.goo.gl/abc123'))->toBeTrue();
});

it('no sigue una redirección que se escapa de la lista blanca', function () {
    Http::fake([
        'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/']),
    ]);

    expect(EnlaceDeMapa::coordenadas('https://maps.app.goo.gl/fuga'))->toBeNull();
});

it('resuelve el acortador en un salto', function () {
    Http::fake([
        'maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Casa/@-24.24,-65.26,17z/'
                .'data=!4m6!3m5!8m2!3d-24.2432299!4d-65.2645195',
        ]),
    ]);

    $punto = EnlaceDeMapa::coordenadas('te mando mi ubi https://maps.app.goo.gl/rTqUJx2xNLEMzv248?g_st=aw');

    expect($punto['latitude'])->toBe(-24.2432299)
        ->and($punto['longitude'])->toBe(-65.2645195);
});

it('aguanta sin romperse que Google no conteste', function () {
    Http::fake(['maps.app.goo.gl/*' => Http::response('', 500)]);

    expect(EnlaceDeMapa::coordenadas('https://maps.app.goo.gl/abc'))->toBeNull();
});

/*
 * De punta a punta: el link entra como texto y el agente recibe coordenadas.
 */
it('el agente recibe las coordenadas del link, no el link', function () {
    Http::fake([
        'maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Mi+Casa/data=!8m2!3d-24.1866232!4d-65.3026021',
        ]),
    ]);

    $mensaje = textoDeCliente('hola, acá vivo https://maps.app.goo.gl/zEqrHNf61oZP2qJ4A?g_st=awb');

    (new ResolverEnlaceDeMapa($mensaje->id))->handle();

    $m = app(AgentPayload::class)->event($mensaje->fresh())['message'];

    expect($m['location']['lat'])->toBe(-24.1866232)
        ->and($m['latitude'])->toBe(-24.1866232)
        ->and($m['longitude'])->toBe(-65.3026021)
        ->and($m['location']['name'])->toBe('Mi Casa');

    // El mensaje sigue siendo el texto del cliente: el hilo no se reescribe.
    expect($m['type'])->toBe('text')
        ->and($m['text'])->toContain('hola, acá vivo');
});

it('no le pisa el nodo a un pin nativo', function () {
    $mensaje = textoDeCliente('https://maps.app.goo.gl/abc');
    $mensaje->forceFill(['payload' => ['location' => ['latitude' => -24.1, 'longitude' => -65.1]]])->save();

    Http::fake(); // si saliera a la red, el fake lo delataría con otra coordenada

    (new ResolverEnlaceDeMapa($mensaje->id))->handle();

    expect($mensaje->fresh()->payload['location']['latitude'])->toBe(-24.1);
});

/*
 * El distribuidor tiene que ver esa dirección en el Punto. Antes el Punto
 * filtraba por `type = 'location'` y los links —la mitad de las ubicaciones que
 * llegan— le quedaban invisibles.
 */
it('el agente recibe un texto listo para guardar, no la URL', function () {
    Http::fake([
        'maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Mi+Casa/data=!8m2!3d-24.1866232!4d-65.3026021',
        ]),
    ]);

    $mensaje = textoDeCliente('acá vivo https://maps.app.goo.gl/zEqrHNf61oZP2qJ4A');

    (new ResolverEnlaceDeMapa($mensaje->id))->handle();

    $m = app(AgentPayload::class)->event($mensaje->fresh())['message'];

    expect($m['location']['text'])->toBe('-24.186623,-65.302602 | Mi Casa')
        ->and($m['location']['text'])->not->toContain('goo.gl');
});
