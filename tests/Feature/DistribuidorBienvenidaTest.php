<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Services\Bienvenida;
use Webkul\Whatsapp\Services\TemplateCatalog;

/**
 * El saludo a un contacto nuevo cargado desde el Punto.
 *
 * Va a un número con el que nunca hablamos, así que cae fuera de la ventana de 24 h de
 * WhatsApp y sólo puede salir como plantilla aprobada por Meta. De ahí las dos reglas
 * que estos tests fijan: no se encola nada sin plantilla aprobada, y el alta del
 * contacto no puede fallar porque el saludo no haya salido.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
});

function distribuidorQueSaluda(): User
{
    $rol = Role::where('name', 'Distribuidor')->firstOrFail();

    return User::create([
        'name'     => 'Distribuidor saluda '.uniqid(),
        'email'    => 'saluda'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => $rol->id,
        'status'   => 1,
    ]);
}

function telefonoSinUsar(): string
{
    return '549388'.random_int(1000000, 9999999);
}

/** Finge una plantilla aprobada, para no depender de lo que haya en Meta. */
/**
 * @param  ?array  $header  Lo que la plantilla DECLARA como encabezado, tal como lo
 *                          devuelve TemplateCatalog: null si no tiene.
 */
function conPlantillaDeBienvenida(
    string $nombre = 'bienvenida_sensia',
    int $variables = 1,
    ?array $header = null,
): void {
    config([
        'whatsapp.bienvenida.enabled'  => true,
        'whatsapp.bienvenida.template' => $nombre,
        'whatsapp.bienvenida.language' => 'es',
    ]);

    app()->instance(TemplateCatalog::class, new class($nombre, $variables, $header) extends TemplateCatalog
    {
        public function __construct(protected string $nombre, protected int $vars, protected ?array $head) {}

        public function find(string $name, string $language): ?array
        {
            return $name === $this->nombre
                ? [
                    'name'      => $this->nombre,
                    'language'  => $language,
                    'body'      => 'Hola {{1}}! Bienvenido a Sensia.',
                    'variables' => $this->vars,
                    'header'    => $this->head,
                ]
                : null;
        }

        public function render(string $body, array $variables): string
        {
            return str_replace('{{1}}', $variables[0] ?? '', $body);
        }
    });
}

// ───────────────────────── la bandera ─────────────────────────

it('apagado no encola nada', function () {
    config(['whatsapp.bienvenida.enabled' => false]);

    expect(app(Bienvenida::class)->enviarA(telefonoSinUsar(), 'Juan'))->toBeFalse();

    Queue::assertNothingPushed();
});

it('sin plantilla aprobada no encola y lo registra', function () {
    config([
        'whatsapp.bienvenida.enabled'  => true,
        // Un nombre que no existe en el catálogo: es el estado real hasta que Meta
        // apruebe la plantilla.
        'whatsapp.bienvenida.template' => 'no_existe_'.uniqid(),
    ]);

    expect(app(Bienvenida::class)->enviarA(telefonoSinUsar(), 'Juan'))->toBeFalse();

    // No se encola un mensaje que el gateway va a rechazar.
    Queue::assertNotPushed(SendMessage::class);
});

it('con plantilla aprobada encola el saludo', function () {
    conPlantillaDeBienvenida();

    $telefono = telefonoSinUsar();

    expect(app(Bienvenida::class)->enviarA($telefono, 'Juan Pérez'))->toBeTrue();

    Queue::assertPushed(SendMessage::class);

    $mensaje = DB::table('whatsapp_messages')->orderByDesc('id')->first();

    expect($mensaje->type)->toBe('template')
        ->and($mensaje->direction)->toBe('outbound')
        ->and($mensaje->body)->toContain('Juan Pérez');

    /*
     * El payload se escribe ANTES del envío: es lo que lee SendMessage para armar el
     * request y lo que lee un reintento, así que tiene que bastar por sí solo.
     */
    $payload = json_decode((string) $mensaje->payload, true);

    expect($payload['template'])->toBe('bienvenida_sensia')
        ->and($payload['variables'])->toBe(['Juan Pérez']);
});

it('no manda mas variables de las que la plantilla declara', function () {
    // Una variable de más hace que Meta rechace el mensaje ENTERO.
    conPlantillaDeBienvenida('bienvenida_sensia', 0);

    app(Bienvenida::class)->enviarA(telefonoSinUsar(), 'Juan');

    $payload = json_decode((string) DB::table('whatsapp_messages')->orderByDesc('id')->value('payload'), true);

    expect($payload['variables'])->toBe([]);
});

it('un contacto sin nombre no deja la variable vacia', function () {
    conPlantillaDeBienvenida();

    app(Bienvenida::class)->enviarA(telefonoSinUsar(), null);

    $payload = json_decode((string) DB::table('whatsapp_messages')->orderByDesc('id')->value('payload'), true);

    // Meta rechaza una variable vacía, así que hay un respaldo.
    expect($payload['variables'][0])->toBe('cliente');
});

// ──────────────────── enganchado al alta del contacto ────────────────────

it('saluda al crear un contacto nuevo desde el punto', function () {
    conPlantillaDeBienvenida();

    $this->actingAs(distribuidorQueSaluda(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => telefonoSinUsar(),
            'nombre'   => 'Cliente saludado '.uniqid(),
        ])
        ->assertOk()
        ->assertJsonPath('creada', true);

    Queue::assertPushed(SendMessage::class);
});

it('saluda tambien si el contacto ya existia', function () {
    conPlantillaDeBienvenida();

    $telefono = telefonoSinUsar();
    $user = distribuidorQueSaluda();

    // Primera vez: se crea y se saluda.
    $this->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), ['telefono' => $telefono, 'nombre' => 'Cliente uno'])
        ->assertOk()
        ->assertJsonPath('creada', true);

    Queue::fake();

    /*
     * Segunda vez con el mismo teléfono: el contacto YA existe —`creada` vuelve false— y
     * el saludo sale igual.
     *
     * Este test afirmaba lo contrario, y la regla vieja dejaba afuera un caso que en la
     * calle pasa seguido: el distribuidor busca el teléfono, la búsqueda no lo encuentra,
     * toca Crear contacto, y el repositorio SÍ lo encuentra porque deduplica por teléfono
     * normalizado. Para quien está adelante de la pantalla acaba de dar de alta a un
     * cliente, así que el saludo tiene que salir.
     */
    $this->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), ['telefono' => $telefono, 'nombre' => 'Cliente uno'])
        ->assertOk()
        ->assertJsonPath('creada', false);

    Queue::assertPushed(SendMessage::class);
});

it('el alta del contacto no falla si el saludo revienta', function () {
    config(['whatsapp.bienvenida.enabled' => true]);

    // Un catálogo que explota: es lo que pasaría si Meta no responde.
    app()->instance(TemplateCatalog::class, new class extends TemplateCatalog
    {
        public function __construct() {}

        public function find(string $name, string $language): ?array
        {
            throw new \RuntimeException('Meta no responde');
        }
    });

    /*
     * Crear el cliente es el trabajo; saludarlo es un extra. Un alta que falla porque no
     * salió un mensaje deja al distribuidor sin poder vender con el cliente delante.
     */
    $this->actingAs(distribuidorQueSaluda(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => telefonoSinUsar(),
            'nombre'   => 'Cliente pese al error '.uniqid(),
        ])
        ->assertOk()
        ->assertJsonPath('creada', true);
});

// ───────────────────────── el logo del saludo ─────────────────────────
//
// El logo viaja como ENCABEZADO de la plantilla. No puede ir como mensaje de imagen
// aparte: el saludo sale a un número con el que nunca hablamos, o sea fuera de la
// ventana de 24 h, donde Meta sólo acepta plantillas.
//
// Y Meta valida el mensaje contra la estructura exacta con la que la plantilla fue
// aprobada, así que los dos desajustes dan el mismo error:
//
//   plantilla CON header IMAGE + envío sin imagen  → (#132012)
//   plantilla SIN header        + envío con imagen → (#132012)

it('manda el logo cuando la plantilla declara un encabezado de imagen', function () {
    conPlantillaDeBienvenida(header: ['format' => 'IMAGE', 'media' => true, 'variables' => 0]);
    config(['whatsapp.bienvenida.logo' => 'https://sensia.example/logo.png']);

    expect(app(Bienvenida::class)->enviarA(telefonoSinUsar(), 'Juan'))->toBeTrue();

    $payload = json_decode(
        (string) DB::table('whatsapp_messages')->orderByDesc('id')->value('payload'),
        true,
    );

    /*
     * `toEqual` y no `toBe`: la columna `payload` es JSON nativo de MySQL, que NO
     * preserva el orden de las claves —las ordena por longitud, así que `link` vuelve
     * antes de `format` aunque el código las escriba al revés—. Un toBe acá falla por
     * el orden y hace creer que falta un dato.
     */
    expect($payload['header'])->toEqual([
        'format' => 'IMAGE',
        'link'   => 'https://sensia.example/logo.png',
    ]);
});

it('una plantilla sin encabezado no lleva el logo aunque esté configurado', function () {
    // El caso de hoy: `bienvenida_sensia` está aprobada SIN header. Mandarle una imagen
    // rompería un saludo que sale bien, así que la URL se ignora.
    conPlantillaDeBienvenida();
    config(['whatsapp.bienvenida.logo' => 'https://sensia.example/logo.png']);

    expect(app(Bienvenida::class)->enviarA(telefonoSinUsar(), 'Juan'))->toBeTrue();

    $payload = json_decode(
        (string) DB::table('whatsapp_messages')->orderByDesc('id')->value('payload'),
        true,
    );

    expect($payload)->not->toHaveKey('header');
});

it('si la plantilla pide imagen y no hay logo, no encola y lo registra', function () {
    conPlantillaDeBienvenida(header: ['format' => 'IMAGE', 'media' => true, 'variables' => 0]);
    config(['whatsapp.bienvenida.logo' => null]);

    /*
     * Encolarlo igual lo haría rechazar con un 132012 que habla de "formato de
     * parámetros": un error que no menciona ninguna imagen, para una causa que es
     * exactamente una imagen faltante. Mejor frenar y dejarlo escrito.
     */
    expect(app(Bienvenida::class)->enviarA(telefonoSinUsar(), 'Juan'))->toBeFalse();

    Queue::assertNotPushed(SendMessage::class);
});

it('el formato del nodo sale de la plantilla y no de la extension del archivo', function () {
    // Meta la aprobó como IMAGE: el nodo dice image aunque la URL no tenga extensión.
    conPlantillaDeBienvenida(header: ['format' => 'IMAGE', 'media' => true, 'variables' => 0]);
    config(['whatsapp.bienvenida.logo' => 'https://cdn.example/archivo?id=99']);

    app(Bienvenida::class)->enviarA(telefonoSinUsar(), 'Juan');

    $payload = json_decode(
        (string) DB::table('whatsapp_messages')->orderByDesc('id')->value('payload'),
        true,
    );

    expect($payload['header']['format'])->toBe('IMAGE');
});

// ───────────────────── el archivo del logo ─────────────────────

it('el banner del saludo existe y respeta la proporcion de WhatsApp', function () {
    $ruta = public_path('whatsapp/bienvenida-sensia.jpg');

    expect(file_exists($ruta))->toBeTrue(
        'Falta el banner del saludo. Es el encabezado de la plantilla: sin él, Meta '
        .'rechaza el envío con 132012.'
    );

    [$ancho, $alto] = getimagesize($ruta);

    /*
     * WhatsApp muestra los encabezados de imagen a 1.91:1. Lo que no respeta esa
     * proporción sale recortado o con zoom — un logo cuadrado pierde la mitad.
     *
     * La tolerancia es por el redondeo a píxeles enteros, no por holgura de criterio.
     */
    expect(round($ancho / $alto, 2))->toBeGreaterThanOrEqual(1.89)
        ->and(round($ancho / $alto, 2))->toBeLessThanOrEqual(1.93);

    // Meta corta en 5 MB, pero el límite real es otro: lo descarga en CADA envío.
    expect(filesize($ruta))->toBeLessThan(500 * 1024);
});

it('el logo por defecto apunta a una URL absoluta', function () {
    /*
     * La descarga META desde sus servidores, no el celular del cliente: una ruta
     * relativa no resuelve a nada del lado de Meta, y el síntoma sería el mismo 132012
     * de siempre.
     */
    expect(config('whatsapp.bienvenida.logo'))->toStartWith('http');
});
