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
function conPlantillaDeBienvenida(string $nombre = 'bienvenida_sensia', int $variables = 1): void
{
    config([
        'whatsapp.bienvenida.enabled'  => true,
        'whatsapp.bienvenida.template' => $nombre,
        'whatsapp.bienvenida.language' => 'es',
    ]);

    app()->instance(TemplateCatalog::class, new class($nombre, $variables) extends TemplateCatalog
    {
        public function __construct(protected string $nombre, protected int $vars) {}

        public function find(string $name, string $language): ?array
        {
            return $name === $this->nombre
                ? [
                    'name'      => $this->nombre,
                    'language'  => $language,
                    'body'      => 'Hola {{1}}! Bienvenido a Sensia.',
                    'variables' => $this->vars,
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

it('no saluda de nuevo a un contacto que ya existia', function () {
    conPlantillaDeBienvenida();

    $telefono = telefonoSinUsar();
    $user = distribuidorQueSaluda();

    // Primera vez: se crea y se saluda.
    $this->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), ['telefono' => $telefono, 'nombre' => 'Cliente uno'])
        ->assertOk()
        ->assertJsonPath('creada', true);

    Queue::fake();

    // Segunda vez con el mismo teléfono: ya existía, no se saluda.
    $this->actingAs($user, 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), ['telefono' => $telefono, 'nombre' => 'Cliente uno'])
        ->assertOk()
        ->assertJsonPath('creada', false);

    Queue::assertNotPushed(SendMessage::class);
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
