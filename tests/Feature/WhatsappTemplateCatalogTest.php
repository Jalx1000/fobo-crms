<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Services\TemplateCatalog;

/**
 * El catálogo de plantillas aprobadas que alimenta el selector del inbox.
 *
 * Se afirma con `Http::fake()`: no se llama a Meta de verdad, y el host de
 * desarrollo tampoco llega a la base. Alcanza, porque todo lo que este servicio
 * puede romper vive en cómo interpreta la respuesta de Meta.
 */
function fakeMeta(array $data): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['data' => $data], 200),
    ]);
}

function metaTemplate(array $overrides = []): array
{
    return array_merge([
        'id'         => '1',
        'name'       => 'saludo',
        'language'   => 'es',
        'status'     => 'APPROVED',
        'category'   => 'UTILITY',
        'components' => [
            ['type' => 'BODY', 'text' => 'Hola {{1}}, tu pedido {{2}} está listo.'],
        ],
    ], $overrides);
}

beforeEach(function () {
    Cache::flush();

    config()->set('whatsapp.gateways.cloud_api.waba_id', '999');
    config()->set('whatsapp.gateways.cloud_api.token', 'test-token');
});

it('hides templates Meta has not approved', function () {
    fakeMeta([
        metaTemplate(['name' => 'aprobada']),
        metaTemplate(['name' => 'pendiente', 'status' => 'PENDING']),
        metaTemplate(['name' => 'rechazada', 'status' => 'REJECTED']),
    ]);

    $names = array_column(app(TemplateCatalog::class)->all(), 'name');

    expect($names)->toBe(['aprobada']);
});

it('counts the highest placeholder, not how many appear', function () {
    // Meta completa por posición: un cuerpo con {{1}} y {{3}} necesita TRES
    // valores. Contar los que aparecen daría 2 y el envío se rechazaría entero.
    fakeMeta([metaTemplate(['components' => [
        ['type' => 'BODY', 'text' => 'Hola {{1}}, referencia {{3}}.'],
    ]])]);

    expect(app(TemplateCatalog::class)->all()[0]['variables'])->toBe(3);
});

it('reports no variables for a body without placeholders', function () {
    fakeMeta([metaTemplate(['components' => [
        ['type' => 'BODY', 'text' => 'Gracias por escribirnos.'],
    ]])]);

    expect(app(TemplateCatalog::class)->all()[0]['variables'])->toBe(0);
});

it('keeps the header format so a document template can be told apart', function () {
    fakeMeta([metaTemplate(['components' => [
        ['type' => 'HEADER', 'format' => 'DOCUMENT'],
        ['type' => 'BODY', 'text' => 'Adjuntamos tu cotización {{1}}.'],
    ]])]);

    $template = app(TemplateCatalog::class)->all()[0];

    // El header multimedia no se puede previsualizar, pero el formato importa:
    // es lo que distingue a las plantillas capaces de llevar el PDF.
    expect($template['header_format'])->toBe('DOCUMENT')
        ->and($template['header'])->toBeNull();
});

it('flags a document-header template as unsendable from the chat', function () {
    // Es la plantilla real de cotizaciones: el header DOCUMENT es un parámetro
    // obligatorio, y mandarla sin el archivo devuelve 132012 — un error que no
    // menciona en ninguna parte que falta un adjunto.
    fakeMeta([metaTemplate(['name' => 'cotizacion_documento_v2', 'components' => [
        ['type' => 'HEADER', 'format' => 'DOCUMENT'],
        ['type' => 'BODY', 'text' => 'Te enviamos la cotización {{1}}.\nTotal: {{2}}'],
    ]])]);

    $template = app(TemplateCatalog::class)->all()[0];

    expect($template['requires_media_header'])->toBeTrue()
        ->and($template['variables'])->toBe(2);
});

it('does not flag a plain text header', function () {
    fakeMeta([metaTemplate(['components' => [
        ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hola'],
        ['type' => 'BODY', 'text' => 'Bienvenido.'],
    ]])]);

    $template = app(TemplateCatalog::class)->all()[0];

    expect($template['requires_media_header'])->toBeFalse()
        ->and($template['header_variables'])->toBe(0);
});

it('counts variables that live in the header separately from the body', function () {
    // Header y cuerpo son componentes distintos para Meta: los placeholders del
    // header no se completan con los del body, y confundirlos da 132012.
    fakeMeta([metaTemplate(['components' => [
        ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Pedido {{1}}'],
        ['type' => 'BODY', 'text' => 'Hola {{1}}, ya está listo.'],
    ]])]);

    $template = app(TemplateCatalog::class)->all()[0];

    expect($template['header_variables'])->toBe(1)
        ->and($template['variables'])->toBe(1);
});

it('explains itself when the account id is missing', function () {
    config()->set('whatsapp.gateways.cloud_api.waba_id', null);

    expect(fn () => app(TemplateCatalog::class)->all())
        ->toThrow(RuntimeException::class, 'waba_id');
});

it('caches per account so a number change never serves the previous catalogue', function () {
    // Secuencia y no dos `Http::fake()`: llamarlo de nuevo ACUMULA stubs y sigue
    // ganando el primero, con lo cual el test pasaria aunque el cache estuviera
    // mal aislado por cuenta.
    Http::fakeSequence('graph.facebook.com/*')
        ->push(['data' => [metaTemplate(['name' => 'de-la-cuenta-999'])]])
        ->push(['data' => [metaTemplate(['name' => 'de-la-cuenta-888'])]]);

    expect(app(TemplateCatalog::class)->all()[0]['name'])->toBe('de-la-cuenta-999');

    config()->set('whatsapp.gateways.cloud_api.waba_id', '888');

    expect(app(TemplateCatalog::class)->all()[0]['name'])->toBe('de-la-cuenta-888');
});

it('serves the cached catalogue instead of asking Meta twice', function () {
    Http::fakeSequence('graph.facebook.com/*')
        ->push(['data' => [metaTemplate(['name' => 'primera'])]])
        ->push(['data' => [metaTemplate(['name' => 'segunda'])]]);

    $catalog = app(TemplateCatalog::class);

    expect($catalog->all()[0]['name'])->toBe('primera')
        ->and($catalog->all()[0]['name'])->toBe('primera');

    // Y `fresh` sí vuelve a preguntar.
    expect($catalog->all(fresh: true)[0]['name'])->toBe('segunda');
});

it('finds a template by name and language', function () {
    fakeMeta([
        metaTemplate(['name' => 'saludo', 'language' => 'es']),
        metaTemplate(['name' => 'saludo', 'language' => 'en']),
    ]);

    $catalog = app(TemplateCatalog::class);

    expect($catalog->find('saludo', 'en')['language'])->toBe('en')
        ->and($catalog->find('saludo', 'pt'))->toBeNull();
});

it('stores the payload in the shape the send job reconstructs', function () {
    // Esta es la trampa del port: Odontoking guarda `variables`, pero
    // OutboundTemplate::fromArray() lee `body_params`. Con la clave equivocada
    // el mensaje sale sin nombre y falla en el worker, no en la request.
    $payload = [
        'name'        => 'cotizacion_documento',
        'language'    => 'es',
        'body_params' => ['Ana', '1200'],
    ];

    $template = OutboundTemplate::fromArray($payload);

    expect($template->name)->toBe('cotizacion_documento')
        ->and($template->language)->toBe('es')
        ->and($template->bodyParams)->toBe(['Ana', '1200']);
});
