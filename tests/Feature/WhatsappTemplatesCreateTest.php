<?php

use Illuminate\Support\Facades\Http;
use Webkul\Whatsapp\Services\TemplatePublisher;

/*
| `whatsapp:templates-create` da de alta en Meta las plantillas del archivo
| Config/templates.php (nuevos contactos, recontacto, saludo...). Valida antes
| las reglas de formato de Meta y nunca pisa una plantilla que ya existe.
*/

const META_TEMPLATES = 'graph.facebook.com/v21.0/waba-test/message_templates*';

beforeEach(function () {
    config([
        'whatsapp.gateways.cloud_api.waba_id'     => 'waba-test',
        'whatsapp.gateways.cloud_api.token'       => 'token-test',
        'whatsapp.gateways.cloud_api.api_version' => 'v21.0',
    ]);
});

function validTemplate(array $overrides = []): array
{
    return array_merge([
        'category' => 'UTILITY',
        'language' => 'es',
        'body'     => 'Hola {{1}}, le escribimos de la clínica.',
        'examples' => ['María'],
    ], $overrides);
}

it('las plantillas del archivo cumplen las reglas de Meta', function () {
    $publisher = new TemplatePublisher;

    expect(config('whatsapp-templates'))->toHaveKeys([
        'bienvenida_nuevo_contacto', 'recontacto_paciente', 'saludo_retomar_conversacion',
    ]);

    foreach (config('whatsapp-templates') as $name => $definition) {
        expect($publisher->validate($name, $definition))->toBe([], $name);
    }
});

it('rechaza lo que Meta rechazaría', function (array $overrides, string $error) {
    $errors = (new TemplatePublisher)->validate('plantilla_test', validTemplate($overrides));

    expect(implode(' ', $errors))->toContain($error);
})->with([
    'variable al inicio'      => [['body' => '{{1}}, le escribimos.'], 'no puede empezar ni terminar'],
    'variable al final'       => [['body' => 'Hola {{1}}'], 'no puede empezar ni terminar'],
    'variables con salto'     => [['body' => 'Hola {{1}}, su cita es {{3}} hoy.', 'examples' => ['a', 'b', 'c']], 'en orden y sin saltos'],
    'variables seguidas'      => [['body' => 'Hola {{1}} {{2}}, gracias.', 'examples' => ['a', 'b']], 'no pueden ir seguidas'],
    'faltan ejemplos'         => [['examples' => []], '1 variable(s) y hay 0 ejemplo(s)'],
    'categoría inválida'      => [['category' => 'PROMO'], 'La categoría debe ser'],
    'pie largo'               => [['footer' => str_repeat('a', 61)], 'máximo 60 caracteres'],
    'botón largo'             => [['buttons' => [str_repeat('b', 26)]], 'entre 1 y 25 caracteres'],
    'botones repetidos'       => [['buttons' => ['Sí', 'Sí']], 'botones repetidos'],
]);

it('rechaza nombres que Meta no acepta', function () {
    expect((new TemplatePublisher)->validate('Saludo Nuevo', validTemplate()))
        ->toContain('El nombre solo admite minúsculas, números y guion bajo.');
});

it('arma el pedido de Meta con encabezado, ejemplos, pie y botones', function () {
    $payload = (new TemplatePublisher)->payload('bienvenida_nuevo_contacto', config('whatsapp-templates.bienvenida_nuevo_contacto'));

    expect($payload['category'])->toBe('MARKETING')
        ->and($payload['parameter_format'])->toBe('positional')
        ->and(array_column($payload['components'], 'type'))->toBe(['HEADER', 'BODY', 'FOOTER', 'BUTTONS'])
        ->and($payload['components'][1]['example']['body_text'])->toBe([['María']])
        ->and($payload['components'][3]['buttons'][0])->toBe(['type' => 'QUICK_REPLY', 'text' => 'Agendar consulta']);
});

it('--dry-run valida sin llamar a Meta', function () {
    Http::fake();

    $this->artisan('whatsapp:templates-create', ['--dry-run' => true])
        ->expectsOutputToContain('3 plantilla(s) válida(s). Nada se envió a Meta.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('crea solo las que faltan y no toca las existentes', function () {
    Http::fake([
        META_TEMPLATES => Http::sequence()
            ->push(['data' => [
                ['name' => 'recontacto_paciente', 'language' => 'es', 'status' => 'APPROVED'],
                ['name' => 'saludo_retomar_conversacion', 'language' => 'es', 'status' => 'REJECTED', 'rejected_reason' => 'INVALID_FORMAT'],
            ]])
            ->push(['id' => '123', 'status' => 'PENDING', 'category' => 'MARKETING']),
    ]);

    $this->artisan('whatsapp:templates-create', ['--yes' => true])
        ->expectsOutputToContain('bienvenida_nuevo_contacto: creada (PENDING).')
        ->assertSuccessful();

    $posts = Http::recorded(fn ($request) => $request->method() === 'POST')->values();

    expect($posts)->toHaveCount(1)
        ->and($posts[0][0]['name'])->toBe('bienvenida_nuevo_contacto')
        ->and($posts[0][0]->hasHeader('Authorization', 'Bearer token-test'))->toBeTrue();
});

it('pide confirmación antes de crear y respeta el «no»', function () {
    Http::fake([META_TEMPLATES => Http::response(['data' => []])]);

    $this->artisan('whatsapp:templates-create', ['names' => ['saludo_retomar_conversacion']])
        ->expectsConfirmation('¿Crear 1 plantilla(s) en la cuenta waba-test?', 'no')
        ->expectsOutputToContain('Cancelado.')
        ->assertSuccessful();

    Http::assertSentCount(1); // solo la lectura
});

it('muestra el motivo cuando Meta rechaza la creación', function () {
    Http::fake([
        META_TEMPLATES => Http::sequence()
            ->push(['data' => []])
            ->push(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'El contenido infringe las políticas.']], 400),
    ]);

    $this->artisan('whatsapp:templates-create', ['names' => ['saludo_retomar_conversacion'], '--yes' => true])
        ->expectsOutputToContain('saludo_retomar_conversacion: El contenido infringe las políticas.')
        ->assertFailed();
});

it('una plantilla inválida frena todo el envío', function () {
    Http::fake();
    config(['whatsapp-templates.mala' => validTemplate(['body' => '{{1}}'])]);

    $this->artisan('whatsapp:templates-create', ['--yes' => true])->assertFailed();

    Http::assertNothingSent();
});

it('un nombre que no está en el archivo se informa', function () {
    $this->artisan('whatsapp:templates-create', ['names' => ['no_existe'], '--dry-run' => true])
        ->expectsOutputToContain('No están en Config/templates.php: no_existe')
        ->assertFailed();
});

it('una caché inaccesible no hace pasar por fallida una plantilla ya creada', function () {
    Http::fake([META_TEMPLATES => Http::response(['id' => '1', 'status' => 'PENDING'])]);
    \Illuminate\Support\Facades\Cache::shouldReceive('forget')->andThrow(new Error('Class "Redis" not found'));

    expect((new TemplatePublisher)->create('saludo_retomar_conversacion', config('whatsapp-templates.saludo_retomar_conversacion')))
        ->toBe('PENDING');
});

it('elimina hello_world si está en la cuenta', function () {
    Http::fake([META_TEMPLATES => Http::sequence()
        ->push(['data' => array_merge(
            [['name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED']],
            array_map(fn ($name) => ['name' => $name, 'language' => 'es', 'status' => 'APPROVED'], array_keys(config('whatsapp-templates'))),
        )])
        ->push(['success' => true]),
    ]);

    $this->artisan('whatsapp:templates-create')
        ->expectsConfirmation('¿Eliminar hello_world en la cuenta waba-test?', 'yes')
        ->expectsOutputToContain('hello_world: eliminada.')
        ->assertSuccessful();

    $deletes = Http::recorded(fn ($request) => $request->method() === 'DELETE')->values();

    expect($deletes)->toHaveCount(1)
        ->and($deletes[0][0]->url())->toEndWith('/message_templates?name=hello_world');
});

it('sin hello_world en la cuenta no borra nada', function () {
    Http::fake([META_TEMPLATES => Http::response(['data' => array_map(
        fn ($name) => ['name' => $name, 'language' => 'es', 'status' => 'APPROVED'],
        array_keys(config('whatsapp-templates')),
    )])]);

    $this->artisan('whatsapp:templates-create', ['--yes' => true])
        ->expectsOutputToContain('Nada para hacer.')
        ->assertSuccessful();

    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
});

it('crear y borrar van en una sola confirmación', function () {
    Http::fake([META_TEMPLATES => Http::response(['data' => [
        ['name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED'],
    ]])]);

    $this->artisan('whatsapp:templates-create')
        ->expectsConfirmation('¿Crear 3 plantilla(s) y eliminar hello_world en la cuenta waba-test?', 'no')
        ->expectsOutputToContain('Cancelado.')
        ->assertSuccessful();

    Http::assertSentCount(1); // solo la lectura
});

it('una plantilla propia nunca se borra aunque esté en la lista', function () {
    config(['whatsapp.templates.remove' => ['hello_world', 'saludo_retomar_conversacion']]);
    Http::fake([META_TEMPLATES => Http::response(['data' => array_map(
        fn ($name) => ['name' => $name, 'language' => 'es', 'status' => 'APPROVED'],
        array_keys(config('whatsapp-templates')),
    )])]);

    $this->artisan('whatsapp:templates-create', ['--yes' => true])->assertSuccessful();

    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
});

it('--dry-run anuncia el borrado sin hacerlo', function () {
    Http::fake();

    $this->artisan('whatsapp:templates-create', ['--dry-run' => true])
        ->expectsOutputToContain('Si están en la cuenta, se eliminan: hello_world')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('si Meta no deja borrar hello_world, avisa sin fallar', function () {
    Http::fake([META_TEMPLATES => Http::sequence()
        ->push(['data' => array_merge(
            [['name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED']],
            array_map(fn ($name) => ['name' => $name, 'language' => 'es', 'status' => 'APPROVED'], array_keys(config('whatsapp-templates'))),
        )])
        ->push(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'Las plantillas de muestra no se pueden editar ni eliminar.']], 400),
    ]);

    $this->artisan('whatsapp:templates-create', ['--yes' => true])
        ->expectsOutputToContain('hello_world: Meta no la deja eliminar (Las plantillas de muestra no se pueden editar ni eliminar.). Queda oculta en el chat.')
        ->assertSuccessful();
});

it('el chat no ofrece hello_world', function () {
    \Illuminate\Support\Facades\Cache::flush();
    Http::fake([META_TEMPLATES => Http::response(['data' => [
        ['name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello World']]],
        ['name' => 'saludo_retomar_conversacion', 'language' => 'es', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, gracias.']]],
    ]])]);

    $names = array_column(app(\Webkul\Whatsapp\Services\TemplateCatalog::class)->all(true), 'name');

    expect($names)->toBe(['saludo_retomar_conversacion']);
});
