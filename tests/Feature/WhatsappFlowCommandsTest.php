<?php

/*
|--------------------------------------------------------------------------
| Comandos de WhatsApp Flows (contra Meta simulada)
|--------------------------------------------------------------------------
| whatsapp:flows:keys y whatsapp:flows:publish hablan con la Graph API de
| producción: acá se verifica QUÉ mandan antes de correrlos de verdad.
*/

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->keyPath = sys_get_temp_dir().'/flowkeys-'.uniqid().'/private.pem';

    config([
        'whatsapp.flows.private_key_path'       => $this->keyPath,
        'whatsapp.flows.private_key_passphrase' => null,
        'whatsapp.flows.patient.name'           => 'odontoking_paciente_tercero',
        'whatsapp.flows.patient.id'             => null,
        'whatsapp.cloud.token'                  => 'tok',
        'whatsapp.cloud.waba_id'                => '999',
        'whatsapp.cloud.phone_number_id'        => '111',
        'whatsapp.cloud.api_version'            => 'v21.0',
    ]);

    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
    Http::preventStrayRequests();
});

afterEach(function () {
    @unlink($this->keyPath);
    @rmdir(dirname($this->keyPath));
});

it('keys genera la clave privada con permisos 0600 y sube la pública a Meta', function () {
    Http::fake([
        'graph.facebook.com/v21.0/111/whatsapp_business_encryption' => Http::sequence()
            ->push(['success' => true])
            ->push(['data' => [['business_public_key_signature_status' => 'VALID']]]),
    ]);

    $this->artisan('whatsapp:flows:keys', ['--upload' => true])
        ->expectsOutputToContain('Estado de la clave en Meta: VALID')
        ->assertSuccessful();

    expect(is_readable($this->keyPath))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($this->keyPath)), -4))->toBe('0600');

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->body(), 'business_public_key=')
        && str_contains(urldecode($r->body()), '-----BEGIN PUBLIC KEY-----'));
});

it('keys sin --upload no llama a Meta y reutiliza la clave existente', function () {
    $this->artisan('whatsapp:flows:keys')->assertSuccessful();
    $primera = file_get_contents($this->keyPath);

    $this->artisan('whatsapp:flows:keys')->expectsOutputToContain('Usando la clave privada existente')->assertSuccessful();

    expect(file_get_contents($this->keyPath))->toBe($primera);
    Http::assertNothingSent();
});

it('publish crea el Flow con el JSON del repo, la categoría de turnos y el endpoint del CRM', function () {
    Http::fake([
        'graph.facebook.com/v21.0/999/flows' => Http::response(['id' => 'FLOW123', 'success' => true, 'validation_errors' => []]),
    ]);

    $this->artisan('whatsapp:flows:publish')
        ->expectsOutputToContain('WHATSAPP_FLOW_PATIENT_ID=FLOW123')
        ->expectsOutputToContain('BORRADOR')
        ->assertSuccessful();

    Http::assertSent(function ($r) {
        $data = $r->data();

        return $r->url() === 'https://graph.facebook.com/v21.0/999/flows'
            && $data['name'] === 'odontoking_paciente_tercero'
            && $data['categories'] === ['APPOINTMENT_BOOKING']
            && $data['endpoint_uri'] === route('whatsapp.flows.endpoint')
            && json_decode($data['flow_json'], true)['version'] === '7.3';
    });
});

it('publish con id existente actualiza el JSON por assets y publica con --publish', function () {
    config(['whatsapp.flows.patient.id' => 'FLOW123']);

    Http::fake([
        'graph.facebook.com/v21.0/FLOW123/assets'  => Http::response(['success' => true, 'validation_errors' => []]),
        'graph.facebook.com/v21.0/FLOW123/publish' => Http::response(['success' => true]),
        'graph.facebook.com/v21.0/FLOW123'         => Http::response(['success' => true]),
    ]);

    $this->artisan('whatsapp:flows:publish', ['--publish' => true])
        ->expectsOutputToContain('Flow publicado')
        ->assertSuccessful();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/FLOW123/assets') && str_contains($r->body(), 'FLOW_JSON'));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/FLOW123/publish'));
});

it('publish muestra los errores de validación de Meta', function () {
    Http::fake([
        'graph.facebook.com/v21.0/999/flows' => Http::response([
            'id'                => 'FLOW9', 'success' => true,
            'validation_errors' => [['message' => 'Missing Form component', 'pointers' => [['path' => 'screens[0]']]]],
        ]),
    ]);

    $this->artisan('whatsapp:flows:publish')
        ->expectsOutputToContain('Validación: Missing Form component')
        ->assertSuccessful();
});
